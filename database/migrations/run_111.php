<?php
/**
 * Migration 111 — isola `contatos` por conta (bug B1).
 *
 * O PROBLEMA
 *
 * `contatos` tinha UNIQUE GLOBAL em `telefone` e em `remote_jid`, sem
 * `account_id`. `Contato::findOrCreateByPhone()` faz INSERT ... ON DUPLICATE
 * KEY, entao dois escritorios que cadastrassem um cliente com o MESMO telefone
 * acabavam apontando para a MESMA linha de contato, e o nome exibido era o de
 * quem cadastrou primeiro. Nenhuma leitura de `contatos` filtra por conta:
 * ProcessoAudit (historico do processo) e TaskLink (vinculo da tarefa) resolvem
 * o nome por id.
 *
 * O QUE ESTA MIGRATION FAZ
 *
 *   1. Preenche `account_id` dos contatos que dao para deduzir pelas linhas que
 *      os referenciam (cards, clientes, processos, whatsapp_chats).
 *   2. Troca os UNIQUE globais por UNIQUE (account_id, telefone) e
 *      (account_id, remote_jid). Precisa vir ANTES da separacao: com o UNIQUE
 *      global no lugar, a copia com o mesmo telefone seria recusada.
 *   3. SEPARA os contatos usados por mais de uma conta: cria uma COPIA por
 *      conta extra e reaponta as referencias daquela conta para a copia dela.
 *
 * NADA E APAGADO, e ninguem perde o que ve hoje: na separacao, cada conta fica
 * com uma copia contendo os MESMOS dados. Por isso nao ha decisao de "de quem e
 * o nome": todos continuam vendo o que viam.
 *
 * IDEMPOTENTE: rodar duas vezes nao duplica nada. Se os indices ja estiverem
 * trocados, a etapa 3 e pulada.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_111.php
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_111.php
 *
 * Flags:
 *   --dry-run   diz o que faria, sem gravar nada. RODE ISSO PRIMEIRO EM PRODUCAO.
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

function linha(string $s = ''): void
{
    echo $s . PHP_EOL;
}

linha('== Migration 111: isola contatos por conta (B1) ==');
linha($dryRun ? '   MODO DRY-RUN: nada sera gravado.' : '   MODO APLICACAO.');
linha();

/* ── quem referencia contatos, e por qual coluna de conta ─────────────────── */
$REFS = [
    'cards'          => 'account_id',
    'clientes'       => 'account_id',
    'processos'      => 'account_id',
    'whatsapp_chats' => 'account_id',
];

// so considera as tabelas que existem e tem as duas colunas
$refsValidas = [];
foreach ($REFS as $tab => $colConta) {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `$tab`")->fetchAll(\PDO::FETCH_COLUMN);
        if (in_array('contato_id', $cols, true) && in_array($colConta, $cols, true)) {
            $refsValidas[$tab] = $colConta;
        } else {
            linha("   aviso: `$tab` nao tem contato_id + $colConta, ignorada");
        }
    } catch (\Throwable $e) {
        linha("   aviso: tabela `$tab` nao existe, ignorada");
    }
}
if (!$refsValidas) {
    linha('ERRO: nenhuma tabela de referencia utilizavel. Abortando.');
    exit(1);
}

/** SELECT contato_id, conta de todas as tabelas que referenciam. */
function unionRefs(array $refsValidas): string
{
    $partes = [];
    foreach ($refsValidas as $tab => $col) {
        $partes[] = "SELECT contato_id, `$col` AS acc FROM `$tab`
                     WHERE contato_id IS NOT NULL AND `$col` IS NOT NULL";
    }
    return implode(' UNION ', $partes);
}
$UNION = unionRefs($refsValidas);

/* ── estado atual ─────────────────────────────────────────────────────────── */
$total    = (int)$pdo->query('SELECT COUNT(*) FROM contatos')->fetchColumn();
$semConta = (int)$pdo->query('SELECT COUNT(*) FROM contatos WHERE account_id IS NULL')->fetchColumn();

$compart = $pdo->query(
    "SELECT c.id, COUNT(DISTINCT x.acc) AS contas
       FROM contatos c
       JOIN ($UNION) x ON x.contato_id = c.id
      GROUP BY c.id HAVING contas > 1"
)->fetchAll(\PDO::FETCH_ASSOC);

linha('Estado atual:');
linha("   contatos ........................ $total");
linha("   sem account_id .................. $semConta");
linha('   usados por MAIS DE UMA conta .... ' . count($compart));
foreach (array_slice($compart, 0, 20) as $c) {
    linha("      contato #{$c['id']} usado por {$c['contas']} contas");
}
if (count($compart) > 20) {
    linha('      ... +' . (count($compart) - 20));
}
linha();

/* ── 1. backfill de account_id ────────────────────────────────────────────── */
linha('1) Preenchendo account_id dos contatos de dono unico');

$paraPreencher = $pdo->query(
    "SELECT c.id, MIN(x.acc) AS acc, COUNT(DISTINCT x.acc) AS contas
       FROM contatos c
       JOIN ($UNION) x ON x.contato_id = c.id
      WHERE c.account_id IS NULL
      GROUP BY c.id HAVING contas = 1"
)->fetchAll(\PDO::FETCH_ASSOC);

linha('   contatos com dono deduzivel: ' . count($paraPreencher));
if (!$dryRun && $paraPreencher) {
    $up = $pdo->prepare('UPDATE contatos SET account_id = ? WHERE id = ? AND account_id IS NULL');
    foreach ($paraPreencher as $r) {
        $up->execute([(int)$r['acc'], (int)$r['id']]);
    }
    linha('   preenchidos.');
}

$orfaos = (int)$pdo->query(
    "SELECT COUNT(*) FROM contatos c
      WHERE c.account_id IS NULL
        AND NOT EXISTS (SELECT 1 FROM ($UNION) x WHERE x.contato_id = c.id)"
)->fetchColumn();
if ($orfaos) {
    linha("   $orfaos contato(s) seguem sem conta por nao serem referenciados por ninguem.");
    linha('   Ficam como estao: nao da para adivinhar o dono, e apagar seria perda de dado.');
}
linha();

/* ── 2. troca dos indices ─────────────────────────────────────────────────── */
linha('2) Trocando UNIQUE global por UNIQUE por conta');
linha('   (antes de separar: com o UNIQUE global no lugar, a copia seria recusada)');

$indices = [];
foreach ($pdo->query('SHOW INDEX FROM contatos') as $i) {
    $indices[$i['Key_name']] = true;
}

$trocas = [
    ['antigo' => 'uniq_telefone',   'novo' => 'uniq_conta_telefone',   'col' => 'telefone'],
    ['antigo' => 'uniq_remote_jid', 'novo' => 'uniq_conta_remote_jid', 'col' => 'remote_jid'],
];

foreach ($trocas as $t) {
    if (isset($indices[$t['novo']])) {
        linha("   {$t['novo']} ja existe, pulando.");
        continue;
    }
    if ($dryRun) {
        linha("   trocaria {$t['antigo']} por {$t['novo']} (account_id, {$t['col']})");
        continue;
    }
    if (isset($indices[$t['antigo']])) {
        $pdo->exec("ALTER TABLE contatos DROP INDEX `{$t['antigo']}`");
        linha("   removido {$t['antigo']}");
    }
    $pdo->exec("ALTER TABLE contatos ADD UNIQUE KEY `{$t['novo']}` (account_id, `{$t['col']}`)");
    linha("   criado {$t['novo']} (account_id, {$t['col']})");
}
linha();

/* ── 3. separacao dos compartilhados ──────────────────────────────────────── */
linha('3) Separando contatos usados por mais de uma conta');

if (!$compart) {
    linha('   nenhum compartilhado. Nada a separar.');
} else {
    $criadas = 0;
    $repontadas = 0;
    foreach ($compart as $c) {
        $contatoId = (int)$c['id'];

        $contas = $pdo->prepare("SELECT DISTINCT x.acc FROM ($UNION) x WHERE x.contato_id = ? ORDER BY x.acc");
        $contas->execute([$contatoId]);
        $listaContas = array_map('intval', $contas->fetchAll(\PDO::FETCH_COLUMN));

        // a primeira conta fica com a linha original
        $dona = array_shift($listaContas);
        if (!$dryRun) {
            $pdo->prepare('UPDATE contatos SET account_id = ? WHERE id = ?')
                ->execute([$dona, $contatoId]);
        }
        linha("   contato #$contatoId: conta $dona fica com a linha original");

        foreach ($listaContas as $outra) {
            if ($dryRun) {
                linha("      conta $outra receberia uma COPIA");
                $criadas++;
                continue;
            }
            // copia com os mesmos dados
            $pdo->prepare(
                'INSERT INTO contatos
                   (account_id, nome, telefone, remote_jid, email, observacoes,
                    created_at, updated_at, deleted_at, anonymized_at, deletion_reason)
                 SELECT ?, nome, telefone, remote_jid, email, observacoes,
                        created_at, NOW(), deleted_at, anonymized_at, deletion_reason
                   FROM contatos WHERE id = ?'
            )->execute([$outra, $contatoId]);
            $copiaId = (int)$pdo->lastInsertId();
            $criadas++;
            linha("      conta $outra -> copia #$copiaId");

            // reaponta as referencias daquela conta, e os vinculos que as seguem
            foreach ($refsValidas as $tab => $colConta) {
                $ids = $pdo->prepare(
                    "SELECT id FROM `$tab` WHERE contato_id = ? AND `$colConta` = ?");
                $ids->execute([$contatoId, $outra]);
                $refIds = array_map('intval', $ids->fetchAll(\PDO::FETCH_COLUMN));
                if (!$refIds) {
                    continue;
                }
                $in = implode(',', $refIds);
                $pdo->exec("UPDATE `$tab` SET contato_id = $copiaId WHERE id IN ($in)");
                $repontadas += count($refIds);

                // contato_vinculos aponta para a entidade, nao para a conta
                $tipo = ['cards' => 'card', 'processos' => 'processo',
                         'whatsapp_chats' => 'chat'][$tab] ?? null;
                if ($tipo) {
                    try {
                        $pdo->exec(
                            "UPDATE contato_vinculos SET contato_id = $copiaId
                              WHERE contato_id = $contatoId
                                AND tipo_vinculo = " . $pdo->quote($tipo) . "
                                AND referencia_id IN ($in)");
                    } catch (\Throwable $e) {
                        linha('      aviso ao reapontar contato_vinculos: ' . $e->getMessage());
                    }
                }
            }
        }
    }
    linha("   copias criadas: $criadas · referencias reapontadas: $repontadas");
}
linha();

/* ── conferencia ──────────────────────────────────────────────────────────── */
linha('Conferencia final:');
$restam = $pdo->query(
    "SELECT COUNT(*) FROM (
        SELECT c.id FROM contatos c JOIN ($UNION) x ON x.contato_id = c.id
         GROUP BY c.id HAVING COUNT(DISTINCT x.acc) > 1) t"
)->fetchColumn();
linha("   contatos ainda usados por mais de uma conta: $restam");

$idx = [];
foreach ($pdo->query('SHOW INDEX FROM contatos') as $i) {
    $idx[$i['Key_name']][] = $i['Column_name'];
}
foreach (['uniq_conta_telefone', 'uniq_conta_remote_jid'] as $n) {
    linha("   $n: " . (isset($idx[$n]) ? '(' . implode(', ', $idx[$n]) . ')' : 'AUSENTE'));
}

if ($dryRun) {
    linha();
    linha('[DRY-RUN] nada foi gravado.');
    exit(0);
}

$ok = ((int)$restam === 0) && isset($idx['uniq_conta_telefone']) && isset($idx['uniq_conta_remote_jid']);
linha();
linha($ok ? 'Migration 111 aplicada com sucesso.' : 'Migration 111 terminou com pendencia, confira acima.');
exit($ok ? 0 : 1);
