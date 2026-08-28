<?php
/**
 * B3 Fase B — ATIVA o webhook_token (2o fator) na Evolution de UM canal.
 *
 * Faz a Evolution passar a ENVIAR o header 'X-Webhook-Token: <webhook_token>' em cada
 * webhook, SEM mudar mais nada: a URL e os eventos ATUAIS do webhook (lidos do
 * getWebhook) sao PRESERVADOS; so o header e adicionado (--apply) ou removido
 * (--rollback). O webhook.php (Fase A, prod) valida esse header em modo COMPATIVEL:
 * enquanto a Evolution nao manda, a entrega segue (COMPAT); quando manda e bate, o 2o
 * fator fica ativo (OK); token presente e errado -> 401.
 *
 * SEGURO por padrao: sem --apply/--rollback roda em DRY-RUN e NAO toca a Evolution.
 * NUNCA imprime o token/apikey em claro (mascara em todo output). --apply so age se o
 * webhook atual ja apontar pro Yuris (senao aborta e pede repoint). Guard de roteamento:
 * garante o ?token=<apikey> na URL. Espelha o _phase2_repoint_silvana.php.
 *
 * Uso (no container de prod):
 *   docker exec -w /var/www/html yuris_app php scripts/_phaseB_webhook_token.php <account_id>            # DRY-RUN
 *   docker exec -w /var/www/html yuris_app php scripts/_phaseB_webhook_token.php <account_id> --gen      # gera o token no DB se faltar (nao toca a Evolution)
 *   docker exec -w /var/www/html yuris_app php scripts/_phaseB_webhook_token.php <account_id> --apply    # BACKUP + adiciona o header + verifica
 *   docker exec -w /var/www/html yuris_app php scripts/_phaseB_webhook_token.php <account_id> --rollback # remove o header (preservando url/eventos)
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\WhatsAppAgente\EvolutionApiService;
use App\WhatsAppAgente\WhatsAppInstance;

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$ACCOUNT_ID = isset($argv[1]) ? (int) $argv[1] : 0;
$APPLY    = in_array('--apply', $argv, true);
$ROLLBACK = in_array('--rollback', $argv, true);
$GEN      = in_array('--gen', $argv, true);
if ($ACCOUNT_ID <= 0) {
    fwrite(STDERR, "Uso: php scripts/_phaseB_webhook_token.php <account_id> [--gen] [--apply|--rollback]\n");
    exit(2);
}

const HEADER_NAME = 'X-Webhook-Token';
// Fallback de eventos SO se a Evolution nao devolver os atuais (igual REPOINT_EVENTS do
// ai_webhook.php; SEM GROUPS_UPDATE, que da HTTP 400 nesta versao).
$EVENTS_FALLBACK = ['MESSAGES_UPSERT', 'MESSAGES_UPDATE', 'SEND_MESSAGE', 'CONTACTS_UPSERT', 'CONTACTS_UPDATE', 'CHATS_UPSERT', 'CONNECTION_UPDATE', 'GROUPS_UPSERT', 'GROUP_PARTICIPANTS_UPDATE'];

$pdo = Database::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$wi  = new WhatsAppInstance();
$cfg = $wi->getSettings($ACCOUNT_ID);
$base = (string) ($cfg['evolution_base_url'] ?? '');
$key  = (string) ($cfg['evolution_api_key']  ?? '');
$inst = (string) ($cfg['evolution_instance'] ?? '');
$tok  = trim((string) ($cfg['webhook_token'] ?? ''));
if ($base === '' || $key === '' || $inst === '') {
    fwrite(STDERR, "ABORT: conta {$ACCOUNT_ID} sem config Evolution completa (base/key/instance).\n");
    exit(1);
}

// ── Exibicao SEGURA: NUNCA imprimir o token/apikey em claro ──────────────────
$maskVal = fn(string $s): string => $s === '' ? '(vazio)' : substr($s, 0, 4) . '...' . substr($s, -3) . ' (len ' . strlen($s) . ')';
$redactUrl = fn(string $u): string => (string) preg_replace('/((?:token|apikey|wtoken)=)[^&]+/i', '$1***', $u);
$redactHeaders = function ($h) use ($maskVal): string {
    if (!is_array($h)) return json_encode($h);
    $out = [];
    foreach ($h as $k => $v) {
        $sensitive = is_string($k) && in_array(strtolower($k), ['x-webhook-token', 'apikey', 'token'], true) && is_string($v);
        $out[$k] = $sensitive ? $maskVal($v) : $v;
    }
    return json_encode($out, JSON_UNESCAPED_SLASHES);
};
$extractUrl    = fn($w): string => (string) ($w['url'] ?? $w['webhook']['url'] ?? '');
$extractEvents = function ($w): array { $e = $w['events'] ?? $w['webhook']['events'] ?? null; return is_array($e) ? $e : []; };

echo "== B3 Fase B — ativar webhook_token (conta #{$ACCOUNT_ID}, instancia '{$inst}') ==\n";
echo "base={$base}  apikey=" . $maskVal($key) . "  webhook_token=" . $maskVal($tok) . "\n\n";

// Garante que ha um webhook_token (gera se faltar e --gen). Grava no DB; NAO toca a Evolution.
if ($tok === '') {
    if ($GEN) {
        $tok = bin2hex(random_bytes(32)); // 256 bits
        $wi->saveSetting($ACCOUNT_ID, 'webhook_token', $tok);
        echo "[GEN] webhook_token gerado e gravado: " . $maskVal($tok) . "\n\n";
    } else {
        fwrite(STDERR, "ABORT: conta sem webhook_token. Gere no Painel Master (botao 'Gerar token') ou rode com --gen.\n");
        exit(1);
    }
}

$svc = new EvolutionApiService($cfg);
$svc->setTimeout(15);

// ── DRY-RUN (default): nao toca a Evolution ──────────────────────────────────
if (!$APPLY && !$ROLLBACK) {
    echo "== DRY-RUN (nao toca a Evolution) ==\n";
    echo "No --apply: leria o webhook ATUAL (getWebhook), preservaria url + eventos e\n";
    echo "adicionaria o header " . HEADER_NAME . ": " . $maskVal($tok) . " na instancia '{$inst}'.\n";
    echo "So aplica se o webhook atual ja apontar pro Yuris (senao aborta e pede repoint).\n";
    echo "\nPara aplicar de verdade: rode com --apply.\n";
    exit(0);
}

// ── BACKUP (read-only na Evolution) ──────────────────────────────────────────
$before     = $svc->getWebhook($inst);
$curUrl     = $extractUrl($before);
$curEvents  = $extractEvents($before);
echo "== BACKUP (webhook ATUAL, antes de mexer) ==\n";
echo "url=" . ($curUrl === '' ? '(null)' : $redactUrl($curUrl)) . "\n";
echo "events=" . (implode(',', $curEvents) ?: '(nenhum)') . "\n";
echo "headers=" . $redactHeaders($before['headers'] ?? $before['webhook']['headers'] ?? null) . "\n\n";

// Guard 1: o webhook precisa ja apontar pro Yuris (nao ativar 2o fator sobre destino externo/n8n).
if (stripos($curUrl, 'webhook.php') === false) {
    fwrite(STDERR, "ABORT: o webhook atual nao aponta pro Yuris (url=" . ($curUrl === '' ? '(null)' : $redactUrl($curUrl)) . ").\n"
        . "Reaponte pro Yuris primeiro (Painel Master, botao '-> Yuris'); depois rode a Fase B.\n");
    exit(1);
}
// Guard 2: garante o roteamento do tenant (?token=<apikey>) na URL preservada.
if (!preg_match('/[?&](token|apikey)=/i', $curUrl)) {
    $curUrl .= (strpos($curUrl, '?') === false ? '?' : '&') . 'token=' . urlencode($key);
    echo "[info] URL sem token de roteamento — adicionado ?token=<apikey>.\n";
}
if (!$curEvents) { $curEvents = $EVENTS_FALLBACK; echo "[info] Evolution nao devolveu eventos — usando o conjunto canonico.\n"; }

// Trata falha de transporte (http=0 / _error) alem de http>=400.
$isFail = function (array $res): ?string {
    $http = (int) ($res['_http'] ?? 0);
    if ($http >= 400) return "http {$http}";
    if ($http === 0)  return 'sem resposta (http=0)';
    if (isset($res['_error']) && $res['_error'] !== '') return 'erro de transporte: ' . $res['_error'];
    return null;
};

// ── ROLLBACK: re-seta SEM o header (preservando url/eventos atuais) ───────────
if ($ROLLBACK) {
    echo "== ROLLBACK: setWebhook SEM o header " . HEADER_NAME . " (url/eventos preservados) ==\n";
    // [] + autoAttachToken=FALSE: opt-out explicito da blindagem, para REMOVER o cracha de proposito.
    $res = $svc->setWebhook($inst, $curUrl, $curEvents, [], false);
    if (($err = $isFail($res)) !== null) { fwrite(STDERR, "FALHA no rollback ({$err}). Confira manualmente.\n"); exit(1); }
    echo "setWebhook http=" . ((int) ($res['_http'] ?? 0)) . " [OK] header removido (estado anterior restaurado).\n";
    exit(0);
}

// ── APLICA: adiciona o header X-Webhook-Token, preservando url/eventos ────────
echo "== APLICANDO: adiciona o header " . HEADER_NAME . " (url/eventos preservados) ==\n";
$res = $svc->setWebhook($inst, $curUrl, $curEvents, [HEADER_NAME => $tok]);
if (($err = $isFail($res)) !== null) {
    fwrite(STDERR, "FALHA ao aplicar ({$err}). Estado provavelmente intacto; rode --rollback se necessario.\n");
    exit(1);
}
echo "setWebhook http=" . ((int) ($res['_http'] ?? 0)) . " OK\n\n";

// VERIFICA (com output mascarado).
$after    = $svc->getWebhook($inst);
$hdrAfter = $after['headers'] ?? $after['webhook']['headers'] ?? null;
echo "== VERIFICACAO (apos aplicar) ==\n";
echo "url agora=" . $redactUrl($extractUrl($after)) . "\n";
echo "headers agora=" . $redactHeaders($hdrAfter) . "\n";
$okHdr = is_array($hdrAfter) && (isset($hdrAfter[HEADER_NAME]) || isset($hdrAfter[strtolower(HEADER_NAME)]));
echo ($okHdr
    ? "[OK] Evolution registrou o header " . HEADER_NAME . " na config do webhook.\n"
    : "[ATENCAO] nao confirmei o header no getWebhook (pode ser so a exibicao da Evolution). Confirme AO VIVO.\n");

echo "\n== PROXIMO PASSO (ao vivo) ==\n";
echo "Mande UMA mensagem de teste pro numero. No log do webhook (docker logs yuris_app | grep webhook_token):\n";
echo "  - SUMIU o '... webhook_token configurado mas ausente ... (modo compat ...)'  => header honrado, 2o fator ATIVO (OK).\n";
echo "  - AINDA aparece 'modo compat'  => a Evolution NAO envia header custom; usar fallback ?wtoken= (me avise).\n";
echo "Rollback (se precisar): php scripts/_phaseB_webhook_token.php {$ACCOUNT_ID} --rollback\n";
