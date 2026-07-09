<?php
/**
 * wa_invariants.php — Suite de invariantes do modulo WhatsApp + Agente de IA.
 * Onda 4 / Bloco 4A. Faz parte do PROTOCOLO DE DEPLOY: rodar apos as migrations e
 * antes do graceful, local e em prod. READ-ONLY (nao altera dados).
 *
 *   FAIL (exit!=0) = quebra de invariante ESTRUTURAL/DADO que indica bug real
 *                    (FK sumida, dedup nao garantido, cross-tenant, novo escritor
 *                    de pausa fora do repo, endpoint de agente sem authz por grant).
 *   WARN           = divida CONHECIDA (ex.: pausa ainda escrita fora do repo ate o 4C)
 *                    ou aviso que exige olho humano (prompt mestre mudou de hash).
 *   SKIP           = pre-condicao ausente (coluna/arquivo nao existe neste ambiente).
 *
 * Uso local: C:\xampp\php\php.exe scripts/tests/wa_invariants.php
 * Uso prod:  docker exec yuris_app php /var/www/html/scripts/tests/wa_invariants.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/app/Models/Database.php';

use App\Models\Database;

/**
 * Baseline do prompt mestre (ai_prompts.template, name='pre_atendimento_universal').
 * REGRA SAGRADA: prompt mestre INTOCAVEL. Se este hash nao bater, alguem tocou no
 * prompt (ou local!=prod). E WARN, nao FAIL: se a mudanca foi intencional/aprovada,
 * atualize este baseline; se nao foi, investigue. Gerado 2026-07-09 (len 17763).
 */
const EXPECTED_PROMPT_HASH = 'f249346fc1ff5c1cb547a2f80f753deed09b44d7fe9afe068458d32c76b40590';

$pdo = Database::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$FAILS = 0; $WARNS = 0; $PASSES = 0;
function pass(string $m): void { global $PASSES; $PASSES++; echo "  [PASS] $m\n"; }
function fail(string $m): void { global $FAILS;  $FAILS++;  echo "  [FAIL] $m\n"; }
function warn(string $m): void { global $WARNS;  $WARNS++;  echo "  [WARN] $m\n"; }
function skip(string $m): void { echo "  [SKIP] $m\n"; }
function section(string $t): void { echo "\n== $t ==\n"; }

function colExists(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1");
    $st->execute([$table, $col]); return (bool)$st->fetchColumn();
}
function tableExists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1");
    $st->execute([$table]); return (bool)$st->fetchColumn();
}
function fkExists(PDO $pdo, string $name): bool {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND constraint_type='FOREIGN KEY' AND constraint_name=? LIMIT 1");
    $st->execute([$name]); return (bool)$st->fetchColumn();
}
/** Existe indice UNIQUE cujo conjunto de colunas contem TODAS as pedidas? */
function uniqueOnCols(PDO $pdo, string $table, array $cols): bool {
    $st = $pdo->prepare(
        "SELECT index_name, GROUP_CONCAT(column_name) AS cols
           FROM information_schema.statistics
          WHERE table_schema=DATABASE() AND table_name=? AND non_unique=0
          GROUP BY index_name"
    );
    $st->execute([$table]);
    $need = array_map('strtolower', $cols);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ix) {
        $have = array_map('strtolower', explode(',', (string)$ix['cols']));
        if (!array_diff($need, $have)) return true; // have ⊇ need
    }
    return false;
}
function countq(PDO $pdo, string $sql): int { return (int)$pdo->query($sql)->fetchColumn(); }

/** Le .php recursivamente e retorna [path => conteudo] para os que casam $needle. */
function scanPhp(string $dir, string $needle): array {
    $out = [];
    if (!is_dir($dir)) return $out;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') continue;
        $c = @file_get_contents($f->getPathname());
        if ($c !== false && stripos($c, $needle) !== false) $out[$f->getPathname()] = $c;
    }
    return $out;
}

echo "===================================================================\n";
echo " wa_invariants — DB: " . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
echo "===================================================================\n";

// -------------------------------------------------------------------------
section('SCHEMA — integridade referencial e unicidade');
// -------------------------------------------------------------------------
$fks = [
    'fk_wa_msg_inst','fk_wa_chat_inst','fk_wa_ctt_inst','fk_wa_gm_inst','fk_wa_rx_inst','fk_wa_cp_inst',
    'fk_wa_msg_acc','fk_wa_chat_acc','fk_wa_ctt_acc','fk_wa_gm_acc','fk_wa_rx_acc','fk_wa_cp_acc',
];
$fkOk = 0;
foreach ($fks as $fk) { if (fkExists($pdo, $fk)) $fkOk++; else fail("FK ausente: $fk (migration 106)"); }
if ($fkOk === count($fks)) pass("12 FKs instance_id/account_id presentes (migration 106)");

uniqueOnCols($pdo, 'whatsapp_messages', ['instance_id','wamid'])
    ? pass("UNIQUE(instance_id,wamid) em whatsapp_messages")
    : fail("sem UNIQUE(instance_id,wamid) em whatsapp_messages — dedup inbound advisory");
uniqueOnCols($pdo, 'ai_intake_messages', ['session_id','wamid','direction'])
    ? pass("UNIQUE(session_id,wamid,direction) em ai_intake_messages (C1/migration 103)")
    : fail("sem UNIQUE(session_id,wamid,direction) em ai_intake_messages — LLM pode rodar 2x");
uniqueOnCols($pdo, 'agent_configs', ['whatsapp_instance_id'])
    ? pass("UNIQUE(whatsapp_instance_id) em agent_configs (1 agente por canal)")
    : fail("sem UNIQUE em agent_configs.whatsapp_instance_id — 2 agentes por canal possiveis");

// -------------------------------------------------------------------------
section('SCHEMA — colunas/tabela da migration 107 (Onda 4)');
// -------------------------------------------------------------------------
$c107 = [
    ['whatsapp_instances','events_seq'], ['whatsapp_instances','last_event_at'],
    ['whatsapp_chats','agent_paused_by'], ['whatsapp_chats','agent_paused_at'],
    ['ai_models','price_in_per_mtok'], ['ai_models','price_out_per_mtok'], ['ai_models','price_cached_in_per_mtok'],
];
$okc = 0;
foreach ($c107 as [$t,$c]) { if (colExists($pdo,$t,$c)) $okc++; else fail("coluna 107 ausente: $t.$c"); }
if ($okc === count($c107)) pass(count($c107) . " colunas da 107 presentes (events_seq/paused_by/precos)");
tableExists($pdo,'ai_agent_events')
    ? pass("tabela ai_agent_events presente (observabilidade 4D)")
    : fail("tabela ai_agent_events ausente (migration 107)");

// -------------------------------------------------------------------------
section('DADOS — isolamento e consistencia');
// -------------------------------------------------------------------------
foreach (['whatsapp_messages','whatsapp_chats','whatsapp_contacts'] as $t) {
    try {
        $n = countq($pdo, "SELECT COUNT(*) FROM $t x JOIN whatsapp_instances i ON i.id=x.instance_id WHERE x.account_id IS NOT NULL AND x.account_id<>i.account_id");
        $n === 0 ? pass("$t: 0 linha com account_id divergente do dono da instancia")
                 : fail("$t: $n linha(s) com account_id != account_id da instancia (cross-tenant)");
    } catch (\Throwable $e) { skip("$t cross-tenant: " . $e->getMessage()); }
}
try {
    $n = countq($pdo, "SELECT COUNT(*) FROM whatsapp_chat_processos cp LEFT JOIN whatsapp_instances i ON i.id=cp.instance_id WHERE i.id IS NULL");
    $n === 0 ? pass("whatsapp_chat_processos: 0 orfao de instancia")
             : fail("whatsapp_chat_processos: $n orfao(s) de instance_id");
} catch (\Throwable $e) { skip("chat_processos orfaos: " . $e->getMessage()); }
try {
    $n = countq($pdo, "SELECT COUNT(*) FROM (SELECT channel_id, remote_jid FROM ai_intake_sessions WHERE closed_at IS NULL GROUP BY channel_id, remote_jid HAVING COUNT(*)>1) d");
    $n === 0 ? pass("ai_intake_sessions: 0 sessao ativa duplicada por (canal,jid)")
             : fail("ai_intake_sessions: $n par (canal,jid) com >1 sessao ativa");
} catch (\Throwable $e) { skip("sessao ativa duplicada: " . $e->getMessage()); }
// Pausa <-> sessao: dessincronia e a classe de bug do P0. Ate o 4C consolidar o
// escritor, dados legados podem estar dessincronizados -> WARN (vira FAIL pos-4C).
try {
    $n = countq($pdo,
        "SELECT COUNT(*) FROM ai_intake_sessions s
           JOIN whatsapp_chats c ON c.instance_id=s.channel_id AND c.remote_jid=s.remote_jid
          WHERE s.closed_at IS NULL
            AND ( (s.controller_mode IN('human_takeover','awaiting_human','bot_paused') AND COALESCE(c.agent_paused,0)=0)
               OR (s.controller_mode='bot_active' AND c.agent_paused=1) )");
    $n === 0 ? pass("pausa<->sessao: 0 dessincronia entre controller_mode e agent_paused")
             : warn("pausa<->sessao: $n conversa(s) com controller_mode e agent_paused divergentes (consolidar no 4C)");
} catch (\Throwable $e) { skip("pausa<->sessao: " . $e->getMessage()); }

// -------------------------------------------------------------------------
section('ESTATICAS — invariantes sagradas no codigo');
// -------------------------------------------------------------------------
// B1/B2: os 3 endpoints de CONTROLE do agente autorizam por GRANT (resolveForRequest),
// nunca por hierarquia (getAccessibleAccountIds). (Endpoints data-plane read-only PODEM
// usar getAccessibleAccountIds legitimamente; por isso a checagem e so nestes 3.)
$ctrl = [
    $ROOT.'/public/api/whatsapp/agent_takeover.php',
    $ROOT.'/public/api/whatsapp/agent_channel_toggle.php',
    $ROOT.'/public/api/agent_settings.php',
];
foreach ($ctrl as $f) {
    $name = basename($f);
    if (!is_file($f)) { skip("$name inexistente"); continue; }
    $c = (string)file_get_contents($f);
    stripos($c,'resolveForRequest') !== false
        ? pass("$name autoriza por grant (resolveForRequest)")
        : fail("$name NAO usa resolveForRequest (regressao B1/B2)");
    stripos($c,'getAccessibleAccountIds') === false
        ? pass("$name sem authz por hierarquia (getAccessibleAccountIds)")
        : fail("$name volta a autorizar por hierarquia (getAccessibleAccountIds) — cross-tenant");
}

// Escritor unico da pausa: SO IntakeSessionRepository deveria escrever agent_paused.
// Divida conhecida (2026-07-09): IntakeEngine (handoff) e agent_takeover escrevem direto.
// Isso e WARN ate o 4C rotear pelo repo; escritor NOVO alem desses = FAIL (regressao).
$known = ['IntakeEngine.php','agent_takeover.php'];
$writers = [];
foreach ([scanPhp($ROOT.'/app','agent_paused'), scanPhp($ROOT.'/public','agent_paused')] as $set) {
    foreach ($set as $path => $c) {
        if (basename($path) === 'IntakeSessionRepository.php') continue; // o escritor legitimo
        if (preg_match('/UPDATE\s+whatsapp_chats\s+SET\s+agent_paused|SET\s+agent_paused\s*=/i', $c)) {
            $writers[basename($path)] = true;
        }
    }
}
$writers = array_keys($writers);
$novos = array_diff($writers, $known);
if ($novos) fail("NOVO escritor de agent_paused fora do repo (rotear por IntakeSessionRepository): " . implode(', ', $novos));
if ($writers && !$novos) warn("agent_paused escrito fora do repo em: " . implode(', ', $writers) . " (divida conhecida, consolidar no 4C)");
if (!$writers) pass("agent_paused so escrito por IntakeSessionRepository");

// flushResponse antes do runAgentReply (200 rapido antes do LLM).
$wh = $ROOT.'/public/api/whatsapp/webhook.php';
if (is_file($wh)) {
    $c = (string)file_get_contents($wh);
    $posFlush = strpos($c, 'flushResponse()');
    // Primeira CHAMADA de runAgentReply (ignora a definicao "function runAgentReply(").
    $call = false; $off = 0;
    while (($p = strpos($c, 'runAgentReply(', $off)) !== false) {
        $pre = substr($c, max(0,$p-9), 9);
        if (stripos($pre, 'function ') === false) { $call = $p; break; }
        $off = $p + 1;
    }
    if ($posFlush === false || $call === false) {
        skip("flushResponse/runAgentReply nao localizados no webhook (checar manualmente)");
    } else {
        $posFlush < $call
            ? pass("webhook: flushResponse() antes da chamada de runAgentReply (200 rapido)")
            : fail("webhook: runAgentReply chamado ANTES de flushResponse (quebra o 200 rapido)");
    }
} else { skip("webhook.php inexistente"); }

// Hash-guard do prompt mestre (INTOCAVEL).
try {
    $tpl = $pdo->query("SELECT template FROM ai_prompts WHERE name='pre_atendimento_universal' AND active=1 ORDER BY id DESC LIMIT 1")->fetchColumn();
    if ($tpl === false) {
        skip("prompt mestre 'pre_atendimento_universal' nao encontrado no banco");
    } else {
        $h = hash('sha256', (string)$tpl);
        if (EXPECTED_PROMPT_HASH === '') {
            echo "  [INFO] hash atual do prompt mestre: $h (embuta em EXPECTED_PROMPT_HASH)\n";
        } elseif (hash_equals(EXPECTED_PROMPT_HASH, $h)) {
            pass("prompt mestre inalterado (hash bate com o baseline)");
        } else {
            warn("prompt mestre MUDOU de hash: baseline " . substr(EXPECTED_PROMPT_HASH,0,12) . "… atual " . substr($h,0,12) . "… — se intencional/aprovado, atualize o baseline; senao, investigue");
        }
    }
} catch (\Throwable $e) { skip("hash do prompt mestre: " . $e->getMessage()); }

// -------------------------------------------------------------------------
echo "\n===================================================================\n";
echo " RESULTADO: $PASSES PASS · $WARNS WARN · $FAILS FAIL\n";
echo "===================================================================\n";
exit($FAILS > 0 ? 1 : 0);
