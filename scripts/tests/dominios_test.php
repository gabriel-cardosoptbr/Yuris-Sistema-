<?php
/**
 * dominios_test.php — teste de COMPORTAMENTO dos domínios que não tinham teste.
 *
 * POR QUE ISTO EXISTE (débito D5)
 *
 * Até 27/08/2026 as suítes cobriam plano e WhatsApp. Processos, Tarefas,
 * Clientes, Prospecção, Finanças e LGPD eram verificados à mão, e o custo disso
 * apareceu no mesmo dia: um `require` quebrado dentro de `Cliente::create()`
 * chegou à main. Só era alcançável criando cliente COM TELEFONE, e nada
 * automatizado fazia isso.
 *
 * As outras redes do projeto não pegariam:
 *   - `php -l` não resolve caminho de require
 *   - `class_refs_test` é estático: prova que o alvo existe, não que a operação
 *     funciona
 *   - a varredura HTTP é por GET, e escrita é POST
 *
 * Este teste exercita os caminhos de ESCRITA de verdade, contra o banco.
 *
 * O QUE ELE GARANTE, ALÉM DE "NÃO EXPLODE"
 *
 * Cada domínio é testado também para ISOLAMENTO: uma segunda conta é criada, e
 * o teste exige que ela NÃO enxergue os dados da primeira. É o pior bug possível
 * neste sistema (entregar dado do escritório errado) e não quebra nada quando
 * acontece.
 *
 * SEGURANÇA DE EXECUÇÃO
 *
 * Cria duas contas descartáveis e apaga tudo o que criou num
 * register_shutdown_function, que roda MESMO se uma asserção falhar. Não toca
 * em dado pré-existente: todo filtro é pelas contas criadas aqui.
 *
 * Uso:  php scripts/tests/dominios_test.php
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Master\Account;
use App\Clientes\Cliente;
use App\Clientes\ClienteSetor;
use App\Prospeccao\Card;
use App\Prospeccao\Contato;
use App\Prospeccao\PipelineColumn;
use App\Processos\Processo;
use App\Tarefas\Task;
use App\Tarefas\TaskBoard;
use App\Tarefas\TaskColumn;
use App\Financas\DREAccount;
use App\Lgpd\LgpdRequest;

if (PHP_SAPI === 'cli') {
    @session_start();
}

$OK = 0;
$FALHAS = [];
$SECAO = '';

function secao(string $t): void
{
    global $SECAO;
    $SECAO = $t;
    echo "\n== $t ==\n";
}

function ok(string $msg, bool $cond): void
{
    global $OK, $FALHAS, $SECAO;
    if ($cond) {
        $OK++;
        echo "  [ok]   $msg\n";
    } else {
        $FALHAS[] = "$SECAO :: $msg";
        echo "  [FALHA] $msg\n";
    }
}

/** Executa e devolve [resultado, excecao]. Falha de verdade nunca aborta a suíte. */
function tenta(callable $fn): array
{
    try {
        return [$fn(), null];
    } catch (\Throwable $e) {
        return [null, $e];
    }
}

function okSemErro(string $msg, callable $fn)
{
    [$r, $e] = tenta($fn);
    ok($msg . ($e ? ' :: ' . get_class($e) . ': ' . $e->getMessage() : ''), $e === null);
    return $r;
}

$pdo = Database::getConnection();
$RUN = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

// ── limpeza garantida ───────────────────────────────────────────────────────
$CRIADO = ['accounts' => [], 'boards' => [], 'lgpd' => [], 'contatos' => []];

register_shutdown_function(function () use (&$CRIADO) {
    try {
        $pdo = Database::getConnection();
        if ($CRIADO['boards']) {
            $in = implode(',', array_map('intval', $CRIADO['boards']));
            $pdo->exec("DELETE FROM tasks WHERE board_id IN ($in)");
            $pdo->exec("DELETE FROM task_columns WHERE board_id IN ($in)");
            $pdo->exec("DELETE FROM task_boards WHERE id IN ($in)");
        }
        if ($CRIADO['lgpd']) {
            $in = implode(',', array_map('intval', $CRIADO['lgpd']));
            // NÃO apagar lgpd_request_events direto: a tabela é imutável por
            // trigger (LGPD Art. 18) e o DELETE é recusado. Apagar a
            // solicitação leva os eventos junto pela FK, e cascata não dispara
            // trigger de linha no MySQL.
            $pdo->exec("DELETE FROM lgpd_requests WHERE id IN ($in)");
        }
        // contatos sao apagados por id porque alguns podem ter nascido sem
        // account_id antes da migration 111 (bug B1).
        if ($CRIADO['contatos']) {
            $in = implode(',', array_map('intval', $CRIADO['contatos']));
            $pdo->exec("DELETE FROM contatos WHERE id IN ($in)");
        }
        if ($CRIADO['accounts']) {
            $in = implode(',', array_map('intval', $CRIADO['accounts']));
            // Ordem importa: filhos antes de accounts. Nomes conferidos contra o
            // schema real (clientes_setores e clientes_origens, no plural).
            $tabelas = [
                'clientes_history'  => 'account_id',
                'clientes'          => 'account_id',
                'clientes_setores'  => 'account_id',
                'clientes_origens'  => 'account_id',
                'contatos'          => 'account_id',
                'cards'             => 'account_id',
                'pipeline_columns'  => 'account_id',
                'processos'         => 'account_id',
                'dre_accounts'      => 'account_id',
                'task_boards'       => 'account_id',
                'resource_shares'   => 'from_account_id',
                'users'             => 'account_id',
            ];
            $restos = [];
            foreach ($tabelas as $tab => $col) {
                try {
                    $pdo->exec("DELETE FROM `$tab` WHERE `$col` IN ($in)");
                } catch (\Throwable $e) {
                    $restos[] = $tab . ' (' . substr($e->getMessage(), 0, 60) . ')';
                }
            }
            $pdo->exec("DELETE FROM accounts WHERE id IN ($in)");
            // Limpeza silenciosamente incompleta enche o banco de dev a cada
            // execucao. Se alguma tabela recusar, isso precisa aparecer.
            if ($restos) {
                fwrite(STDERR, "\n[cleanup] nao consegui limpar: " . implode(' | ', $restos) . "\n");
            }
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "\n[cleanup] " . $e->getMessage() . "\n");
    }
});

// ── contas descartáveis ─────────────────────────────────────────────────────
secao('0. Cenário: duas contas isoladas');

$accA = (int) Account::create(['nome' => "Teste Dominios A {$RUN}", 'tipo' => 'matriz']);
$CRIADO['accounts'][] = $accA;
$accB = (int) Account::create(['nome' => "Teste Dominios B {$RUN}", 'tipo' => 'matriz']);
$CRIADO['accounts'][] = $accB;
ok('duas contas descartáveis criadas', $accA > 0 && $accB > 0 && $accA !== $accB);

$mkUser = function (int $acc, string $sufixo) use ($pdo): int {
    $st = $pdo->prepare(
        "INSERT INTO users (account_id, nome, login, senha_hash, perfil, role, status, created_at, updated_at)
         VALUES (:acc, :nome, :login, :hash, 'admin', 'owner', 'active', NOW(), NOW())"
    );
    $st->execute([
        'acc'   => $acc,
        'nome'  => "Teste $sufixo",
        'login' => "teste.dominios.$sufixo@local.invalid",
        'hash'  => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
    ]);
    return (int)$pdo->lastInsertId();
};
$userA = $mkUser($accA, strtolower($RUN) . '.a');
$userB = $mkUser($accB, strtolower($RUN) . '.b');
ok('um usuário por conta', $userA > 0 && $userB > 0);

/* ═══════════════════════════════════════════════════════════════════════════
   CLIENTES — o domínio onde o bug de 27/08 morava
   ═══════════════════════════════════════════════════════════════════════════ */
secao('1. Clientes');

$setorA = okSemErro('cria setor do kanban de Clientes', fn() => ClienteSetor::create([
    'account_id' => $accA, 'nome' => "Novo {$RUN}", 'ordem' => 1,
]));

// ESTE É O CASO QUE PEGARIA O BUG: telefone dispara Contato::findOrCreateByPhone,
// que era carregado por um require quebrado depois da mudança de pasta.
$cliComTel = okSemErro('cria cliente COM TELEFONE (dispara dedupe de contato)',
    fn() => Cliente::create([
        'account_id' => $accA,
        'setor_id'   => $setorA,
        'nome'       => "Cliente Com Telefone {$RUN}",
        'telefone'   => '11987654321',
    ], $userA));
ok('cliente com telefone recebeu id', is_numeric($cliComTel) && (int)$cliComTel > 0);

if ($cliComTel) {
    $row = Cliente::find((int)$cliComTel);
    ok('cliente com telefone foi vinculado a um contato (dedupe funcionou)',
        $row !== null && !empty($row['contato_id']));
    if ($row && !empty($row['contato_id'])) {
        $CRIADO['contatos'][] = (int)$row['contato_id'];
    }
}

$cliSemTel = okSemErro('cria cliente SEM telefone', fn() => Cliente::create([
    'account_id' => $accA, 'setor_id' => $setorA, 'nome' => "Cliente Sem Telefone {$RUN}",
], $userA));

// update com telefone novo: o segundo ponto onde o require quebrado ficava
okSemErro('atualiza telefone do cliente (re-dedupe)', fn() => Cliente::update(
    (int)$cliSemTel, ['telefone' => '11912345678'], $userA));
$rowUp = $cliSemTel ? Cliente::find((int)$cliSemTel) : null;
ok('cliente atualizado ganhou contato vinculado', $rowUp !== null && !empty($rowUp['contato_id']));
if ($rowUp && !empty($rowUp['contato_id'])) {
    $CRIADO['contatos'][] = (int)$rowUp['contato_id'];
}

$listaA = Cliente::list(['account_ids' => [$accA]]);
ok('conta A enxerga os 2 clientes que criou', count($listaA) === 2);

$listaB = Cliente::list(['account_ids' => [$accB]]);
$idsB = array_column($listaB, 'id');
ok('ISOLAMENTO: conta B não enxerga cliente da conta A',
    !in_array((int)$cliComTel, array_map('intval', $idsB), true));

// ── Regressão do bug B1 (migration 111) ─────────────────────────────────────
// Até 27/08/2026 `contatos.telefone` era UNIQUE GLOBAL: dois escritórios com um
// cliente de MESMO telefone caíam na mesma linha de contato, e o nome exibido
// era o de quem cadastrou primeiro. O histórico do processo e o vínculo da
// tarefa resolvem o nome do contato por id, sem filtro de conta, então isso
// aparecia na tela do escritório errado.
$setorB = okSemErro('cria setor na conta B', fn() => ClienteSetor::create([
    'account_id' => $accB, 'nome' => "Novo B {$RUN}", 'ordem' => 1,
]));
$telCompartilhado = '11' . random_int(900000000, 999999999);

$cliA = okSemErro('conta A cria cliente com um telefone', fn() => Cliente::create([
    'account_id' => $accA, 'setor_id' => $setorA,
    'nome' => "Cliente Com Telefone A {$RUN}", 'telefone' => $telCompartilhado,
], $userA));
$cliB = okSemErro('conta B cria cliente com o MESMO telefone', fn() => Cliente::create([
    'account_id' => $accB, 'setor_id' => $setorB,
    'nome' => "Cliente Com Telefone B {$RUN}", 'telefone' => $telCompartilhado,
], $userB));

$rA = $cliA ? Cliente::find((int)$cliA) : null;
$rB = $cliB ? Cliente::find((int)$cliB) : null;
if ($rA && !empty($rA['contato_id'])) { $CRIADO['contatos'][] = (int)$rA['contato_id']; }
if ($rB && !empty($rB['contato_id'])) { $CRIADO['contatos'][] = (int)$rB['contato_id']; }

ok('B1: mesmo telefone em contas diferentes gera contatos DIFERENTES',
    $rA !== null && $rB !== null && !empty($rA['contato_id']) && !empty($rB['contato_id'])
    && (int)$rA['contato_id'] !== (int)$rB['contato_id']);

$ctA = ($rA && !empty($rA['contato_id'])) ? Contato::find((int)$rA['contato_id']) : null;
$ctB = ($rB && !empty($rB['contato_id'])) ? Contato::find((int)$rB['contato_id']) : null;
ok('B1: cada contato pertence à sua própria conta',
    $ctA !== null && $ctB !== null
    && (int)($ctA['account_id'] ?? 0) === $accA
    && (int)($ctB['account_id'] ?? 0) === $accB);
ok('B1: cada escritório vê o nome que ELE digitou',
    $ctB !== null && str_contains((string)$ctB['nome'], 'Telefone B'));
ok('B1: Contato::find com conta errada não devolve o contato do outro',
    $ctB !== null && Contato::find((int)$rB['contato_id'], $accA) === null);

/* ═══════════════════════════════════════════════════════════════════════════
   PROSPECÇÃO
   ═══════════════════════════════════════════════════════════════════════════ */
secao('2. Prospecção');

$colA = okSemErro('cria coluna do funil', fn() => PipelineColumn::create([
    'account_id' => $accA, 'nome' => "Lead {$RUN}", 'ordem' => 1,
]));

$cardA = okSemErro('cria card no funil', fn() => Card::create([
    'account_id' => $accA,
    'coluna_id'  => $colA,
    'titulo'     => "Card {$RUN}",
    'cliente_nome' => 'Fulano da Silva',
    'telefone_whatsapp' => '11999998888',
]));
ok('card recebeu id', is_numeric($cardA) && (int)$cardA > 0);

$cardsA = Card::list(['account_ids' => [$accA]]);
ok('conta A enxerga o card que criou', count($cardsA) === 1);

$cardsB = Card::list(['account_ids' => [$accB]]);
ok('ISOLAMENTO: conta B não enxerga card da conta A',
    !in_array((int)$cardA, array_map('intval', array_column($cardsB, 'id')), true));

/* ═══════════════════════════════════════════════════════════════════════════
   PROCESSOS
   ═══════════════════════════════════════════════════════════════════════════ */
secao('3. Processos');

$procA = okSemErro('cria processo', fn() => Processo::create([
    'account_id'   => $accA,
    'numero'       => "PROC-{$RUN}-001",
    'cliente_nome' => 'Cliente do Processo',
    'status'       => 'ativo',
]));
ok('processo recebeu id', is_numeric($procA) && (int)$procA > 0);

$procsA = Processo::list(['account_ids' => [$accA]]);
ok('conta A enxerga o processo que criou', count($procsA) === 1);

$procsB = Processo::list(['account_ids' => [$accB]]);
ok('ISOLAMENTO: conta B não enxerga processo da conta A',
    !in_array((int)$procA, array_map('intval', array_column($procsB, 'id')), true));

/* ═══════════════════════════════════════════════════════════════════════════
   TAREFAS
   ═══════════════════════════════════════════════════════════════════════════ */
secao('4. Tarefas');

$boardA = okSemErro('cria quadro de tarefas', fn() => TaskBoard::create([
    'nome' => "Quadro {$RUN}", 'tipo' => 'equipe', 'owner_id' => $userA, 'account_id' => $accA,
]));
if ($boardA) {
    $CRIADO['boards'][] = (int)$boardA;
}

$colTaskA = okSemErro('cria coluna do quadro', fn() => TaskColumn::create([
    'board_id' => $boardA, 'nome' => 'A fazer', 'ordem' => 1, 'inicial' => 1,
]));

$taskA = okSemErro('cria tarefa', fn() => Task::create([
    'board_id'      => $boardA,
    'column_id'     => $colTaskA,
    'titulo'        => "Tarefa {$RUN}",
    'criado_por_id' => $userA,
]));
ok('tarefa recebeu id', is_numeric($taskA) && (int)$taskA > 0);

$tarefas = $taskA ? Task::findByBoard((int)$boardA) : [];
ok('quadro lista a tarefa criada', count($tarefas) === 1);

okSemErro('conclui a tarefa', fn() => Task::complete((int)$taskA, $userA));

$boardsB = TaskBoard::findForUser($userB, [$accB]);
ok('ISOLAMENTO: usuário da conta B não enxerga quadro da conta A',
    !in_array((int)$boardA, array_map('intval', array_column($boardsB, 'id')), true));

/* ═══════════════════════════════════════════════════════════════════════════
   FINANÇAS
   ═══════════════════════════════════════════════════════════════════════════ */
secao('5. Finanças');

$dreA = okSemErro('cria conta do DRE', fn() => DREAccount::create([
    'account_id' => $accA,
    'codigo'     => "T{$RUN}",
    'nome'       => "Receita Teste {$RUN}",
    'tipo'       => 'receita',
]));
ok('conta do DRE recebeu id', is_numeric($dreA) && (int)$dreA > 0);

$dreListaA = DREAccount::listAll(['account_ids' => [$accA]]);
ok('conta A enxerga a conta de DRE que criou', count($dreListaA) === 1);

$dreListaB = DREAccount::listAll(['account_ids' => [$accB]]);
ok('ISOLAMENTO: conta B não enxerga DRE da conta A',
    !in_array((int)$dreA, array_map('intval', array_column($dreListaB, 'id')), true));

/* ═══════════════════════════════════════════════════════════════════════════
   LGPD
   ═══════════════════════════════════════════════════════════════════════════ */
secao('6. LGPD');

$novo = okSemErro('cria solicitação de titular', fn() => LgpdRequest::create([
    'titular_nome'  => "Titular {$RUN}",
    'titular_email' => "titular.{$RUN}@local.invalid",
    'tipo'          => 'acesso',
    'descricao'     => 'Teste automatizado',
    'account_id'    => $accA,
]));
$lgpdA = is_array($novo) ? ($novo['id'] ?? null) : null;
if ($lgpdA) {
    $CRIADO['lgpd'][] = (int)$lgpdA;
}
ok('solicitação recebeu id', $lgpdA !== null && (int)$lgpdA > 0);
ok('create devolve o token de acompanhamento na hora',
    is_array($novo) && !empty($novo['token']) && strlen((string)$novo['token']) === 64);

if ($lgpdA) {
    $req = LgpdRequest::findById((int)$lgpdA);
    ok('solicitação nasce com token gravado', $req !== null && !empty($req['token_acesso']));
    // O prazo legal é o motivo de este módulo existir: nasce preenchido ou o
    // escritório perde o relógio da ANPD sem perceber.
    ok('solicitação nasce com prazo de resposta definido',
        $req !== null && !empty($req['prazo_resposta']));
    ok('solicitação nasce com status "aberto"',
        $req !== null && ($req['status'] ?? '') === 'aberto');

    $porToken = LgpdRequest::findByToken((string)$req['token_acesso']);
    ok('titular consegue acompanhar pelo token',
        $porToken !== null && (int)($porToken['id'] ?? 0) === (int)$lgpdA);

    okSemErro('registra evento na solicitação',
        fn() => LgpdRequest::addEvent((int)$lgpdA, 'aberta', 'teste automatizado'));
    ok('evento aparece no histórico', count(LgpdRequest::listEvents((int)$lgpdA)) >= 1);

    // O histórico da solicitação é a prova de que o escritório cumpriu o prazo.
    // O banco recusa UPDATE e DELETE nele por trigger, e isso precisa continuar
    // valendo: uma migration futura que recrie a tabela sem os triggers apagaria
    // essa garantia em silêncio.
    [$r, $e] = tenta(fn() => Database::getConnection()->exec(
        'DELETE FROM lgpd_request_events WHERE request_id = ' . (int)$lgpdA));
    ok('banco RECUSA apagar evento do histórico (imutabilidade LGPD)', $e !== null);

    [$r, $e] = tenta(fn() => Database::getConnection()->exec(
        "UPDATE lgpd_request_events SET observacao = 'adulterado' WHERE request_id = " . (int)$lgpdA));
    ok('banco RECUSA alterar evento do histórico (imutabilidade LGPD)', $e !== null);
}

// Tipo inválido tem que ser recusado: é a porta de entrada pública do módulo.
[$r, $e] = tenta(fn() => LgpdRequest::create([
    'titular_nome' => 'X', 'titular_email' => 'x@local.invalid', 'tipo' => 'tipo_que_nao_existe',
]));
ok('recusa tipo de solicitação inválido', $e instanceof \InvalidArgumentException);

[$r, $e] = tenta(fn() => LgpdRequest::create([
    'titular_nome' => 'X', 'titular_email' => 'nao-e-email', 'tipo' => 'acesso',
]));
ok('recusa e-mail inválido', $e instanceof \InvalidArgumentException);

// ── resultado ───────────────────────────────────────────────────────────────
echo "\n----\n";
if (!$FALHAS) {
    echo "Resultado: {$OK} ok · 0 falha(s)\n";
    exit(0);
}
echo 'Resultado: ' . $OK . ' ok · ' . count($FALHAS) . " falha(s)\n\n";
foreach ($FALHAS as $f) {
    echo "  - $f\n";
}
exit(1);
