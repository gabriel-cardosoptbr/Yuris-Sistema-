<?php
/**
 * scripts/manutencao/backfill_prospeccao_planilha.php — lança na Prospecção os
 * leads que um disparo de WhatsApp já abordou ANTES de o número ser ligado ao
 * sistema, a partir da planilha do disparo (CSV).
 *
 * Por que existe: na edição CRM, toda mensagem NOVA cria o lead sozinha
 * (SdrFleetiflow::garantirCard). O que saiu antes de o número ser ligado não
 * passou pelo webhook, e a sincronização não cria card (só mensagens de até 15
 * minutos contam). Foi o caso do disparo da Isa na Inovaize, desde 28/09/2026.
 *
 * A DATA REAL DO ENVIO é preservada: o card nasce com created_at = data do envio
 * (a planilha está no horário de São Paulo; o banco grava em UTC) e o histórico
 * (card_history) ganha "created" e "captado_whatsapp" com essa mesma data. Assim
 * o painel conta cada lead no dia em que foi abordado, não no dia do backfill.
 * card_history é imutável para UPDATE/DELETE (LGPD), mas aceita INSERT com data.
 *
 * Regras, iguais às do garantirCard:
 *   · lead que já existe (mesmos 8 últimos dígitos na conta) não é recriado: só
 *     ganha o nome da empresa (se o nome ainda for o telefone), a categoria (se
 *     vazia) e o responsável (se vazio);
 *   · quem já é cliente não volta para o funil;
 *   · a conversa do número, se existir e estiver solta, é ligada ao card.
 *
 * O responsável é o dono do número (--instancia, migration 137) ou --responsavel.
 *
 * CSV: colunas empresa, categoria, telefone, whatsapp_status, whatsapp_enviado_em
 * ("yyyy-MM-dd HH:mm"). Entram só as linhas com whatsapp_status = "enviado".
 *
 * USO (só confere por padrão; --aplicar lança):
 *   php scripts/manutencao/backfill_prospeccao_planilha.php --conta=119 --instancia=18 --csv=/tmp/leads.csv [--aplicar]
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Só via CLI.\n"); }
require_once __DIR__ . '/../../app/bootstrap.php';

use App\WhatsAppAgente\SdrFleetiflow;

$o = getopt('', ['conta:', 'instancia:', 'csv:', 'responsavel:', 'aplicar']);
$conta = (int) ($o['conta'] ?? 0);
$inst  = (int) ($o['instancia'] ?? 0);
$csv   = (string) ($o['csv'] ?? '');
$aplicar = isset($o['aplicar']);
if ($conta <= 0 || $csv === '') exit("Informe --conta=ID e --csv=arquivo (e --instancia=ID do número).\n");

$pdo = \App\Core\Database::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (!SdrFleetiflow::contaUsa($conta)) exit("A conta $conta não é da edição CRM.\n");
if ($inst > 0) {
    $st = $pdo->prepare('SELECT id FROM whatsapp_instances WHERE id = ? AND account_id = ?');
    $st->execute([$inst, $conta]);
    if (!$st->fetchColumn()) exit("O número $inst não é da conta $conta.\n");
}
$resp = isset($o['responsavel']) ? (int) $o['responsavel'] : (SdrFleetiflow::donoDoNumero($pdo, $conta, $inst ?: null) ?? 0);
if ($resp > 0) {
    $st = $pdo->prepare("SELECT nome FROM users WHERE id = ? AND account_id = ? AND deleted_at IS NULL AND status = 'active'");
    $st->execute([$resp, $conta]);
    $nomeResp = $st->fetchColumn();
    if ($nomeResp === false) exit("Responsável $resp não é usuário ativo da conta $conta.\n");
} else { $nomeResp = '(nenhum)'; }
$colunas = SdrFleetiflow::colunasDaConta($pdo, $conta);
$coluna  = $colunas['novo'] ?? \App\Prospeccao\CaptacaoAutomatica::primeiraColuna($pdo, $conta);
if (!$coluna) exit("A conta $conta não tem coluna de entrada no funil.\n");

$fh = @fopen($csv, 'r');
if (!$fh) exit("Não consegui abrir $csv.\n");
$cab = fgetcsv($fh);
$linhas = [];
while (($r = fgetcsv($fh)) !== false) {
    $row = @array_combine($cab, array_pad(array_slice($r, 0, count($cab)), count($cab), ''));
    if (is_array($row) && trim((string) ($row['whatsapp_status'] ?? '')) === 'enviado') $linhas[] = $row;
}
fclose($fh);

echo ($aplicar ? 'APLICAR' : 'CONFERIR') . " conta $conta, número " . ($inst ?: '-') . ", coluna $coluna, responsável $resp $nomeResp, " . count($linhas) . " linhas \"enviado\"\n";

$spTz = new DateTimeZone('America/Sao_Paulo'); $utc = new DateTimeZone('UTC');
$n = ['novo' => 0, 'enriquecido' => 0, 'ja_completo' => 0, 'cliente' => 0, 'invalido' => 0];
$categoria = static function (string $c): ?string {
    $c = trim($c);
    if ($c === '') return null;
    $mapa = ['clinica de estetica' => 'Clínica de Estética', 'advocacia' => 'Advocacia'];
    return $mapa[mb_strtolower($c)] ?? mb_substr($c, 0, 60);
};
$telefoneLegivel = static fn(string $f): string => \App\Prospeccao\CaptacaoAutomatica::telefoneLegivel($f);
$soNumero = static fn(string $v): bool => (bool) preg_match('/^[+0-9 ()-]+$/', trim($v));

foreach ($linhas as $row) {
    $fone = preg_replace('/\D/', '', (string) $row['telefone']);
    if (strlen($fone) === 10 || strlen($fone) === 11) $fone = '55' . $fone;
    $quando = DateTime::createFromFormat('Y-m-d H:i', trim((string) $row['whatsapp_enviado_em']), $spTz);
    if (strlen($fone) < 12 || strlen($fone) > 13 || !$quando) { $n['invalido']++; echo "  [invalido] id={$row['id']}\n"; continue; }
    $quandoUtc = (clone $quando)->setTimezone($utc)->format('Y-m-d H:i:s');
    $empresa = mb_substr(trim((string) $row['empresa']), 0, 180);
    $tipo = $categoria((string) $row['categoria']);
    $fim = substr($fone, -8);

    $st = $pdo->prepare("SELECT id, titulo, cliente_nome, empresa_nome, tipo_lead, responsavel_user_id FROM cards
                          WHERE account_id = ? AND deleted_at IS NULL
                            AND RIGHT(REGEXP_REPLACE(COALESCE(telefone_whatsapp,''), '[^0-9]', ''), 8) = ?
                       ORDER BY id DESC LIMIT 1");
    $st->execute([$conta, $fim]);
    $card = $st->fetch(PDO::FETCH_ASSOC);

    if ($card) {
        $set = [];
        if ($empresa !== '' && trim((string) $card['empresa_nome']) === '') $set['empresa_nome'] = $empresa;
        foreach (['titulo', 'cliente_nome'] as $c) if ($empresa !== '' && (trim((string) $card[$c]) === '' || $soNumero((string) $card[$c]))) $set[$c] = $empresa;
        if ($tipo !== null && trim((string) $card['tipo_lead']) === '') $set['tipo_lead'] = $tipo;
        if ($resp > 0 && empty($card['responsavel_user_id'])) $set['responsavel_user_id'] = $resp;
        if (!$set) { $n['ja_completo']++; continue; }
        $n['enriquecido']++;
        echo "  [enriquece] card#{$card['id']} " . implode(',', array_keys($set)) . " ($empresa)\n";
        if ($aplicar) {
            $sql = 'UPDATE cards SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($set))) . ' WHERE id = ? AND account_id = ?';
            $pdo->prepare($sql)->execute([...array_values($set), (int) $card['id'], $conta]);
        }
        continue;
    }

    $st = $pdo->prepare("SELECT 1 FROM clientes WHERE account_id = ? AND deleted_at IS NULL
                           AND (RIGHT(REGEXP_REPLACE(COALESCE(whatsapp,''), '[^0-9]', ''), 8) = ?
                             OR RIGHT(REGEXP_REPLACE(COALESCE(telefone,''), '[^0-9]', ''), 8) = ?) LIMIT 1");
    $st->execute([$conta, $fim, $fim]);
    if ($st->fetchColumn()) { $n['cliente']++; echo "  [cliente] $empresa já é cliente, fica fora do funil\n"; continue; }

    $n['novo']++;
    echo "  [novo] " . $quando->format('d/m H:i') . " $empresa" . ($tipo ? " ($tipo)" : '') . "\n";
    if (!$aplicar) continue;

    $rotulo = $empresa !== '' ? $empresa : $telefoneLegivel($fone);
    $desc = 'Lead da prospecção ativa pelo WhatsApp. Abordado em ' . $quando->format('d/m/Y H:i')
          . ' pelo disparo (lançado depois, a partir da planilha do disparo, linha ' . (int) $row['id'] . ').';
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO cards (account_id, titulo, cliente_nome, empresa_nome, tipo_lead, telefone_whatsapp,
                                          responsavel_user_id, coluna_id, ordem_na_coluna, status, descricao, created_at, updated_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 'aberto', ?, ?, ?)")
            ->execute([$conta, $rotulo, $rotulo, $empresa !== '' ? $empresa : null, $tipo, $fone,
                       $resp > 0 ? $resp : null, $coluna, $desc, $quandoUtc, $quandoUtc]);
        $cardId = (int) $pdo->lastInsertId();
        $h = $pdo->prepare('INSERT INTO card_history (card_id, usuario_id, acao, campo_alterado, valor_anterior, valor_novo, created_at)
                            VALUES (?, NULL, ?, ?, NULL, ?, ?)');
        $h->execute([$cardId, 'created', null, null, $quandoUtc]);
        $h->execute([$cardId, 'captado_whatsapp', 'telefone', $fone, $quandoUtc]);
        if ($inst > 0) {
            $pdo->prepare("UPDATE whatsapp_chats SET linked_card_id = ? WHERE instance_id = ? AND account_id = ?
                              AND remote_jid = ? AND linked_card_id IS NULL")
                ->execute([$cardId, $inst, $conta, $fone . '@s.whatsapp.net']);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        echo "  ERRO em {$row['id']}: " . $e->getMessage() . "\n";
    }
}
echo "resumo: " . json_encode($n) . "\n";
if ($aplicar) {
    \App\Master\Account::audit($conta, 'prospeccao.backfill_planilha', ['entidade' => 'account', 'entidade_id' => $conta,
        'detalhes' => $n + ['instancia' => $inst, 'responsavel' => $resp, 'origem' => 'scripts/manutencao/backfill_prospeccao_planilha.php']]);
}
