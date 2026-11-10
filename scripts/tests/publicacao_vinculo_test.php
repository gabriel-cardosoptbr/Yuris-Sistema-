<?php
/**
 * publicacao_vinculo_test.php — vincular publicação ao processo sem obrigar prazo.
 *
 * T4 (10/11/2026): o prazo é consequência opcional do vínculo. A publicação
 * pertence ao processo com ou sem prazo. Pelo caminho real (HTTP em
 * public/api/push/persist.php, servidor local), este arquivo trava:
 *   1. "Vincular sem prazo" (link_process sozinho) não cria prazo nem tarefa:
 *      contagem de processo_prazos, processo_tarefas e tasks antes e depois.
 *   2. A publicação vinculada sem prazo aparece no histórico do processo
 *      (processo_history) e no dossiê do processo (App\Relatorios\Dossie, o
 *      "Relatório completo" da ficha). Relatório ao cliente não existe no sistema.
 *   3. "Vincular e criar prazo" continua igual: link_process + create_prazo
 *      criam exatamente 1 prazo no processo, com a referência da publicação.
 *   4. Dá para criar o prazo depois, numa publicação já vinculada sem prazo.
 *   5. create_prazo sem vínculo continua recusado (409).
 *   6. A tela oferece as duas opções (texto do modal em intimacoes.php/js).
 *
 * ESCREVE NO BANCO LOCAL e NÃO APAGA. Publicações e processo de teste têm
 * hash e número fixos ("[TESTE-AUTO] T4", processo TESTE-AUTO-T4) e são
 * reaproveitados; cada rodada acrescenta 2 prazos "[TESTE-AUTO]" e linhas de
 * histórico nesse processo de teste. Não rode em produção.
 *
 * Precisa do servidor local em http://localhost:8090 (ou YURIS_TESTE_URL).
 * Uso: C:\xampp\php\php.exe scripts/tests/publicacao_vinculo_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
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

/* ── sessão de teste (como a varredura_urls.php: só SELECT) ─────────────── */
$st = $pdo->prepare('SELECT u.*, a.tipo acc_tipo, a.nome acc_nome, a.plano acc_plano
                       FROM users u JOIN accounts a ON a.id = u.account_id
                      WHERE u.account_id = ? AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1');
$st->execute([$CONTA]);
$u = $st->fetch(\PDO::FETCH_ASSOC);
if (!$u) { echo "SKIP: conta $CONTA sem usuário.\n"; exit(0); }
$UID = (int) $u['id'];
$CSRF = str_repeat('c', 32);
$sid = 't4vinc' . bin2hex(random_bytes(8));
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

function persist(array $corpo): array
{
    global $BASE, $sid, $CSRF;
    $ch = curl_init($BASE . '/api/push/persist.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_POST => true,
        CURLOPT_COOKIE => 'PHPSESSID=' . $sid,
        CURLOPT_POSTFIELDS => json_encode(['_csrf' => $CSRF] + $corpo),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $resp, true)];
}

/* ── processo e publicações de teste ───────────────────────────────────── */
$st = $pdo->prepare('SELECT id FROM processos WHERE account_id = ? AND numero = ? AND deleted_at IS NULL ORDER BY id LIMIT 1');
$st->execute([$CONTA, 'TESTE-AUTO-T4']);
$PROC = (int) $st->fetchColumn();
if (!$PROC) {
    $PROC = (int) \App\Processos\Processo::create([
        'account_id' => $CONTA, 'numero' => 'TESTE-AUTO-T4',
        'cliente_nome' => '[TESTE-AUTO] Cliente do T4', 'status' => 'ativo',
    ]);
}
$HOJE = date('Y-m-d');
$evento = function (int $n) use ($pdo, $CONTA, $HOJE): int {
    $it = [
        'account_id' => $CONTA, 'source_id' => 'djen', 'tribunal' => 'TJAC',
        'data_disponibilizacao' => $HOJE, 'titulo' => "[TESTE-AUTO] T4 publicação $n",
        'conteudo' => "[TESTE-AUTO] Publicação $n do vínculo sem prazo.",
        'hash_conteudo' => hash('sha256', 'TESTE-AUTO-T4-vinculo-' . $n),
        'expires_at' => $HOJE . ' 23:59:59',
    ];
    PushTodayCache::upsert($it);
    $s = $pdo->prepare('SELECT id FROM push_events WHERE account_id = ? AND hash_conteudo = ?');
    $s->execute([$CONTA, $it['hash_conteudo']]);
    $id = (int) $s->fetchColumn();
    if (!$id) {
        [, $j] = persist(['action' => 'read', 'payload' => $it]);   // a tela salva ao interagir
        $id = (int) ($j['event_id'] ?? 0);
    }
    return $id;
};
$EV1 = $evento(1);
$EV2 = $evento(2);
ok($EV1 > 0 && $EV2 > 0, "publicações de teste salvas (eventos $EV1 e $EV2), processo $PROC");

$conta = function (string $sql, array $p) use ($pdo): int {
    $s = $pdo->prepare($sql); $s->execute($p); return (int) $s->fetchColumn();
};
$prazos  = fn () => $conta('SELECT COUNT(*) FROM processo_prazos WHERE processo_id = ?', [$PROC]);
$ptarefas= fn () => $conta('SELECT COUNT(*) FROM processo_tarefas WHERE processo_id = ?', [$PROC]);
$tasks   = fn (int $ev) => $conta('SELECT COUNT(*) FROM tasks WHERE push_event_id = ?', [$ev]);
$procDe  = fn (int $ev) => $conta('SELECT COALESCE(processo_id, 0) FROM push_events WHERE id = ?', [$ev]);

// Ponto de partida conhecido: as duas sem vínculo (ação real unlink_process).
foreach ([$EV1, $EV2] as $ev) {
    if ($procDe($ev)) persist(['action' => 'unlink_process', 'event_id' => $ev]);
}

secao('5. create_prazo sem vínculo continua recusado');
[$code] = persist(['action' => 'create_prazo', 'event_id' => $EV1,
                   'extra' => ['descricao' => '[TESTE-AUTO] não deve gravar', 'data_limite' => $HOJE]]);
ok($code === 409, "create_prazo numa publicação sem vínculo responde 409 (obtido $code)");

secao('1. Vincular sem prazo não cria prazo nem tarefa (CA1, CA2)');
$antes = [$prazos(), $ptarefas(), $tasks($EV1)];
$hAntes = $conta("SELECT COUNT(*) FROM processo_history WHERE processo_id = ? AND acao = 'Intimação vinculada' AND descricao LIKE ?", [$PROC, '%push_event #' . $EV1]);
[$code, $j] = persist(['action' => 'link_process', 'event_id' => $EV1, 'extra' => ['processo_id' => $PROC]]);
ok($code === 200 && !empty($j['ok']), "link_process (vincular sem prazo) responde ok (HTTP $code)");
ok($procDe($EV1) === $PROC, "SELECT: push_events.processo_id = $PROC");
$depois = [$prazos(), $ptarefas(), $tasks($EV1)];
ok($antes === $depois, 'SELECT: prazos, tarefas do processo e tarefas do quadro iguais antes e depois ('
    . json_encode($antes) . ' → ' . json_encode($depois) . ')');

secao('2. Aparece no histórico e no dossiê do processo (CA3)');
$hDepois = $conta("SELECT COUNT(*) FROM processo_history WHERE processo_id = ? AND acao = 'Intimação vinculada' AND descricao LIKE ?", [$PROC, '%push_event #' . $EV1]);
ok($hDepois === $hAntes + 1, "processo_history ganhou 1 linha 'Intimação vinculada' da publicação $EV1 ($hAntes → $hDepois)");
$dossie = \App\Relatorios\Dossie::montar('processo', $PROC, [$CONTA]);
ok(is_array($dossie), 'o dossiê do processo (Relatório completo) monta');
ok(preg_match('/Intimação vinculada ao processo[^"]*push_event #' . $EV1 . '"/u', (string) json_encode($dossie, JSON_UNESCAPED_UNICODE)) === 1,
   "o dossiê do processo traz a publicação $EV1 vinculada sem prazo");

secao('3. Vincular e criar prazo continua igual (CA4)');
$p0 = $prazos();
[$code] = persist(['action' => 'link_process', 'event_id' => $EV2, 'extra' => ['processo_id' => $PROC]]);
ok($code === 200 && $procDe($EV2) === $PROC, "link_process da publicação $EV2 ok");
[$code, $j] = persist(['action' => 'create_prazo', 'event_id' => $EV2, 'extra' => [
    'descricao' => '[TESTE-AUTO] T4 prazo junto do vínculo', 'data_limite' => date('Y-m-d', strtotime('+15 days')),
    'prioridade' => 'alta', 'responsavel_id' => $UID,
]]);
ok($code === 200 && !empty($j['ok']), "create_prazo responde ok (HTTP $code)");
ok($prazos() === $p0 + 1, 'exatamente 1 prazo novo no processo (' . $p0 . ' → ' . $prazos() . ')');
$pz = $pdo->prepare('SELECT descricao, observacao, status FROM processo_prazos WHERE id = ?');
$pz->execute([(int) ($j['prazo_id'] ?? 0)]);
$pz = $pz->fetch(\PDO::FETCH_ASSOC) ?: [];
ok(($pz['descricao'] ?? '') === '[TESTE-AUTO] T4 prazo junto do vínculo', 'o prazo gravado tem a descrição enviada');
ok(str_contains((string) ($pz['observacao'] ?? ''), 'push_event #' . $EV2), 'o prazo guarda a referência da publicação');

secao('4. Prazo depois, numa publicação já vinculada sem prazo (CA5)');
$p0 = $prazos();
[$code, $j] = persist(['action' => 'create_prazo', 'event_id' => $EV1, 'extra' => [
    'descricao' => '[TESTE-AUTO] T4 prazo criado depois', 'data_limite' => date('Y-m-d', strtotime('+10 days')),
]]);
ok($code === 200 && !empty($j['ok']) && $prazos() === $p0 + 1, "create_prazo depois do vínculo cria 1 prazo (HTTP $code)");
ok($procDe($EV1) === $PROC, 'a publicação segue vinculada');

secao('6. A tela oferece as duas opções');
$php = file_get_contents(__DIR__ . '/../../public/intimacoes.php');
$js  = file_get_contents(__DIR__ . '/../../public/assets/intimacoes.js');
ok(str_contains($php, 'id="actBtnVincularSemPrazo"') && str_contains($php, '>Vincular sem prazo<'), 'botão "Vincular sem prazo" existe');
ok(str_contains($php, 'id="actBtnVincularComPrazo"') && str_contains($php, '>Vincular e criar prazo<'), 'botão "Vincular e criar prazo" existe');
ok(str_contains($php, 'id="actBtnAbrirPrazo"'), 'botão "Criar prazo para esta publicação" existe');
ok(str_contains($php, '<h2>Vincular ao processo</h2>') && !str_contains($php, '<h2>Vincular processo + criar prazo</h2>'), 'o título do modal não amarra mais vínculo e prazo');
ok(!str_contains($js, 'liberar a etapa 2'), 'nenhum texto diz que o vínculo serve para liberar o prazo');
ok(preg_match('/row\.addEventListener\(\'click\', \(\) => \{\s*\/\/ Clicar só escolhe/', $js) === 1,
   'clicar no processo só escolhe (não vincula nem abre o prazo sozinho)');

echo "\nDados de teste (não apagados): processo $PROC, eventos $EV1 e $EV2\n";
echo "\n=== $PASSES ok / $FAILS falha(s) ===\n";
exit($FAILS > 0 ? 1 : 0);
