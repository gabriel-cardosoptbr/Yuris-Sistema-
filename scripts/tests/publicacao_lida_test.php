<?php
/**
 * publicacao_lida_test.php — publicação marcada como Lida sai do filtro Não lidas.
 *
 * O defeito (T5, 10/11/2026): marcar Lida mudava o status na hora, mas a busca
 * seguinte de Não lidas trazia a publicação de volta. O status ERA gravado.
 * A causa estava em public/api/push/list.php: o cache do dia (push_today_cache)
 * só era descontado das publicações que tinham passado no filtro. Com o filtro
 * "lida = 0", a publicação lida não entrava na lista salva, o hash dela não era
 * descontado, e a cópia do cache voltava como item novo, sem status.
 *
 * O que este arquivo trava, pelo caminho real (HTTP em persist.php e list.php,
 * com uma sessão montada para o primeiro usuário da conta 1):
 *   1. Marcar Lida grava push_event_user_status.lida = 1 (SELECT confere).
 *   2. Depois de marcar 3, a busca Não lidas não traz nenhuma delas, nem depois
 *      de uma nova sincronização (o mesmo item gravado de novo no cache do dia
 *      e promovido de novo a push_events, como fazem a busca e o monitor).
 *   3. A sincronização não reverte o status lido.
 *   4. Os filtros Lidas e Todas mostram as 3, uma vez cada.
 *
 * ESCREVE NO BANCO LOCAL e NÃO APAGA: as 3 publicações têm título
 * "[TESTE-AUTO] T5 ..." e hash fixo, então rodar de novo reaproveita as mesmas
 * linhas em vez de criar outras. Não rode em produção.
 *
 * Precisa do servidor local em http://localhost:8090 (ou YURIS_TESTE_URL).
 * Uso: C:\xampp\php\php.exe scripts/tests/publicacao_lida_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Processos\PushEvent;
use App\Processos\PushTodayCache;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

$FAILS = 0; $PASSES = 0;
function pass(string $m): void { global $PASSES; $PASSES++; echo "  [PASS] $m\n"; }
function fail(string $m): void { global $FAILS;  $FAILS++;  echo "  [FAIL] $m\n"; }
function secao(string $t): void { echo "\n== $t ==\n"; }
function ok(bool $c, string $m): void { $c ? pass($m) : fail($m); }

$BASE = rtrim(getenv('YURIS_TESTE_URL') ?: 'http://localhost:8090', '/');
$CONTA = 1;

/* ── sessão de teste (mesmo método da varredura_urls.php: só SELECT) ─────── */
$st = $pdo->prepare('SELECT u.*, a.tipo acc_tipo, a.nome acc_nome, a.plano acc_plano
                       FROM users u JOIN accounts a ON a.id = u.account_id
                      WHERE u.account_id = ? AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1');
$st->execute([$CONTA]);
$u = $st->fetch(\PDO::FETCH_ASSOC);
if (!$u) { echo "SKIP: conta $CONTA sem usuário.\n"; exit(0); }
$UID = (int) $u['id'];
$CSRF = str_repeat('b', 32);
$sid = 't5lida' . bin2hex(random_bytes(8));
session_id($sid);
@session_start();
$_SESSION = [
    'user_id' => $UID, 'user_nome' => $u['nome'], 'user_perfil' => $u['perfil'],
    'user_role' => $u['role'] ?? 'owner', 'account_id' => $CONTA,
    'account_tipo' => $u['acc_tipo'] ?? 'matriz', 'account_nome' => $u['acc_nome'] ?? '',
    'account_plano' => $u['acc_plano'] ?? 'basico', 'user_permissions' => ['*'],
    'csrf_token' => $CSRF,
];
session_write_close();

function http(string $metodo, string $caminho, ?array $corpo = null): array
{
    global $BASE, $sid;
    $ch = curl_init($BASE . $caminho);
    $op = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_COOKIE => 'PHPSESSID=' . $sid, CURLOPT_CUSTOMREQUEST => $metodo,
    ];
    if ($corpo !== null) {
        $op[CURLOPT_POSTFIELDS] = json_encode($corpo);
        $op[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
    }
    curl_setopt_array($ch, $op);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $resp, true), (string) $resp];
}

/* ── as 3 publicações de teste, no cache do dia (como a sincronização deixa) ─ */
$HOJE = date('Y-m-d');
$itens = [];
for ($i = 1; $i <= 3; $i++) {
    $hash = hash('sha256', 'TESTE-AUTO-T5-publicacao-lida-' . $i);
    $itens[$i] = [
        'account_id' => $CONTA, 'source_id' => 'djen', 'tribunal' => 'TJAC',
        'data_disponibilizacao' => $HOJE, 'numero_processo' => null,
        'titulo' => "[TESTE-AUTO] T5 publicação $i", 'resumo' => '[TESTE-AUTO]',
        'conteudo' => "[TESTE-AUTO] Publicação de teste $i do filtro Não lidas.",
        'hash_conteudo' => $hash, 'expires_at' => $HOJE . ' 23:59:59',
    ];
}
$sincroniza = function () use ($pdo, $itens) {
    foreach ($itens as $it) {
        PushTodayCache::upsert($it);
        // INSERT IGNORE não renova a validade: renova só nas linhas deste teste.
        $pdo->prepare('UPDATE push_today_cache SET expires_at = ? WHERE account_id = ? AND hash_conteudo = ?')
            ->execute([$it['expires_at'], $it['account_id'], $it['hash_conteudo']]);
    }
};
$sincroniza();

$eventoDe = function (string $hash) use ($pdo, $CONTA): int {
    $s = $pdo->prepare('SELECT id FROM push_events WHERE account_id = ? AND hash_conteudo = ?');
    $s->execute([$CONTA, $hash]);
    return (int) $s->fetchColumn();
};
$lidaNoBanco = function (int $ev) use ($pdo, $UID): ?int {
    $s = $pdo->prepare('SELECT lida FROM push_event_user_status WHERE event_id = ? AND user_id = ?');
    $s->execute([$ev, $UID]);
    $v = $s->fetchColumn();
    return $v === false ? null : (int) $v;
};
$hashes = array_map(fn ($it) => $it['hash_conteudo'], $itens);
$quantasNaLista = function (string $qs) use ($hashes): array {
    [$code, $j] = http('GET', '/api/push/list.php?' . $qs);
    $cont = array_fill_keys($hashes, 0);
    foreach (($j['items'] ?? []) as $it) {
        if (isset($cont[$it['hash_conteudo'] ?? ''])) $cont[$it['hash_conteudo']]++;
    }
    return [$code, $cont];
};

/* Ponto de partida conhecido: as 3 como NÃO lidas, pela ação real "unread". */
foreach ($itens as $it) {
    $ev = $eventoDe($it['hash_conteudo']);
    if ($ev) http('POST', '/api/push/persist.php', ['_csrf' => $CSRF, 'action' => 'unread', 'event_id' => $ev]);
}

secao('0. Antes de marcar: as 3 aparecem em Não lidas');
[$code, $cont] = $quantasNaLista('lida=0&limit=500');
ok($code === 200, "list.php lida=0 responde 200 (obtido $code)");
ok(array_sum($cont) === 3 && max($cont) === 1, 'as 3 aparecem uma vez cada em Não lidas (' . json_encode(array_values($cont)) . ')');

secao('1. Marcar Lida grava no banco (CA1)');
$eventos = [];
foreach ($itens as $i => $it) {
    $ev = $eventoDe($it['hash_conteudo']);
    $corpo = ['_csrf' => $CSRF, 'action' => 'read'];
    if ($ev) {
        $corpo['event_id'] = $ev;
    } else {
        // Item ainda só no cache: a tela manda o item normalizado, como aqui.
        $corpo['payload'] = $it;
    }
    [$code, $j] = http('POST', '/api/push/persist.php', $corpo);
    ok($code === 200 && !empty($j['ok']), "persist read da publicação $i responde ok (HTTP $code)");
    $eventos[$i] = (int) ($j['event_id'] ?? 0);
    ok($lidaNoBanco($eventos[$i]) === 1, "SELECT: push_event_user_status.lida = 1 para o evento {$eventos[$i]}");
}

/* Processo de teste fixo: as 3 ficam vinculadas a ele (ação real link_process),
   para conferir que ler e sincronizar não as tiram do processo. */
$st = $pdo->prepare('SELECT id FROM processos WHERE account_id = ? AND numero = ? AND deleted_at IS NULL ORDER BY id LIMIT 1');
$st->execute([$CONTA, 'TESTE-AUTO-T5']);
$PROC = (int) $st->fetchColumn();
if (!$PROC) {
    $PROC = (int) \App\Processos\Processo::create([
        'account_id' => $CONTA, 'numero' => 'TESTE-AUTO-T5',
        'cliente_nome' => '[TESTE-AUTO] Cliente do T5', 'status' => 'ativo',
    ]);
}
foreach ($eventos as $i => $ev) {
    $s = $pdo->prepare('SELECT processo_id FROM push_events WHERE id = ?');
    $s->execute([$ev]);
    if ((int) $s->fetchColumn() !== $PROC) {
        [$code] = http('POST', '/api/push/persist.php', ['_csrf' => $CSRF, 'action' => 'link_process',
                                                       'event_id' => $ev, 'extra' => ['processo_id' => $PROC]]);
        ok($code === 200, "publicação $i vinculada ao processo de teste $PROC (HTTP $code)");
    }
}

secao('2. Não lidas não traz as 3 de volta (CA2)');
[$code, $cont] = $quantasNaLista('lida=0&limit=500');
ok(array_sum($cont) === 0, 'busca Não lidas, logo depois: nenhuma das 3 (' . json_encode(array_values($cont)) . ')');
[$code, $cont] = $quantasNaLista('lida=0&limit=100');
ok(array_sum($cont) === 0, 'busca Não lidas como a tela pede (limit=100): nenhuma das 3');

// Nova sincronização: o mesmo item encontrado de novo e promovido de novo.
$sincroniza();
foreach ($itens as $it) {
    PushEvent::upsert($it + ['tipo_evento' => 'publicacao', 'data_evento' => date('Y-m-d H:i:s'),
                             'origem_busca' => 'cache_hoje', 'status' => 'ativo']);
}
foreach ($eventos as $i => $ev) {
    ok($lidaNoBanco($ev) === 1, "a sincronização não reverte o status lido do evento $ev");
}
[$code, $cont] = $quantasNaLista('lida=0&limit=500');
ok(array_sum($cont) === 0, 'Não lidas depois da sincronização: nenhuma das 3 (' . json_encode(array_values($cont)) . ')');
[$code, $j] = http('GET', '/api/push/list.php?lida=0&limit=500');
$naoLidasApi = (int) ($j['nao_lidas'] ?? -1);
$s = $pdo->prepare('SELECT COUNT(*) FROM push_events e LEFT JOIN push_event_user_status s ON s.event_id = e.id AND s.user_id = ?
                     WHERE e.account_id = ? AND e.status = "ativo" AND COALESCE(s.lida,0) = 0');
$s->execute([$UID, $CONTA]);
ok($naoLidasApi === (int) $s->fetchColumn(), "o contador nao_lidas da API ($naoLidasApi) bate com o SELECT");

secao('3. Lidas e Todas mostram as 3 (CA3)');
[$code, $cont] = $quantasNaLista('lida=1&limit=500');
ok(array_sum($cont) === 3 && max($cont) === 1, 'filtro Lidas: as 3, uma vez cada (' . json_encode(array_values($cont)) . ')');
[$code, $cont] = $quantasNaLista('limit=500');
ok(array_sum($cont) === 3 && max($cont) === 1, 'filtro Todas: as 3, uma vez cada, sem cópia do cache (' . json_encode(array_values($cont)) . ')');
foreach ($eventos as $i => $ev) {
    $s = $pdo->prepare('SELECT processo_id FROM push_events WHERE id = ?');
    $s->execute([$ev]);
    ok((int) $s->fetchColumn() === $PROC, "lida e sincronizada, a publicação $i continua no processo $PROC");
    $h = $pdo->prepare("SELECT COUNT(*) FROM processo_history WHERE processo_id = ? AND acao = 'Intimação vinculada' AND descricao LIKE ?");
    $h->execute([$PROC, '%push_event #' . $ev]);
    ok((int) $h->fetchColumn() >= 1, "o histórico do processo $PROC registra a publicação $i (push_event #$ev)");
}

secao('4. A busca externa devolve o status já gravado');
// search.php consulta a DJEN de verdade; aqui só conferimos a regra de leitura
// que ela usa para marcar o que já foi lido (App\Processos\PushEventUserStatus).
$mapa = \App\Processos\PushEventUserStatus::statusPorHash($CONTA, $UID, $hashes);
ok(count($mapa) === 3 && array_sum(array_column($mapa, 'lida')) === 3, 'statusPorHash devolve as 3 como lidas');
ok(\App\Processos\PushEventUserStatus::statusPorHash(999999, $UID, $hashes) === [], 'outra conta não enxerga o status');

fim:
echo "\nEventos de teste (não apagados): " . implode(', ', $eventos ?? []) . ' · processo de teste: ' . ($PROC ?? '-') . "\n";
echo "\n=== $PASSES ok / $FAILS falha(s) ===\n";
exit($FAILS > 0 ? 1 : 0);
