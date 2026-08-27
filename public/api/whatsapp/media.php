<?php
/**
 * media.php — Proxy de mídia com fallback duplo:
 * 1. Tenta URL direta com API key (funciona se Evolution armazena localmente)
 * 2. Tenta getBase64FromMediaMessage com raw_payload
 * 3. Retorna erro JSON se ambos falharem
 */
ob_start();
@ini_set('display_errors', '0');

require_once __DIR__ . '/../../../app/Core/Database.php';
require_once __DIR__ . '/../../../app/Master/Account.php';
require_once __DIR__ . '/../../../app/Master/ResourceShare.php';
require_once __DIR__ . '/../../../app/WhatsAppAgente/WhatsAppInstance.php';
require_once __DIR__ . '/../../../app/WhatsAppAgente/EvolutionApiService.php';
require_once __DIR__ . '/../../../app/WhatsAppAgente/WhatsAppChannelAccessService.php';
require_once __DIR__ . '/../../../app/Core/AccountContext.php';
require_once __DIR__ . '/../../../app/Core/EnvLoader.php';   // B4: EVOLUTION_TLS_VERIFY

use App\Core\Database;
use App\Core\AccountContext;

session_start(['read_and_close' => true]);
$_uid = $_SESSION['user_id'] ?? null;
ob_end_clean();

if (!$_uid) { http_response_code(401); exit; }

// ─── Fase 2: autorização por CANAL ────────────────────────────────────────────
// Antes (LGPD P0) a mídia era escopada por getAccessibleAccountIds('whatsapp')
// (matriz+filiais). Agora a regra é o GRANT explícito de canal: a conta precisa
// de 'view' sobre o canal DONO da mensagem. A instância/credenciais são
// resolvidas no backend a partir do dono do canal — nunca do front. Mantém o
// 404 anti-enumeração (não revela existência da mídia a quem não tem acesso).
$ctx       = AccountContext::fromSession();
$accountId = $ctx->getAccountId();
// ─────────────────────────────────────────────────────────────────────────────

// Modo diagnóstico: retorna JSON com info do que acontece
$debug = !empty($_GET['debug']);

$msgId = (int)($_GET['msg_id'] ?? 0);
if (!$msgId) { http_response_code(400); echo 'msg_id obrigatório'; exit; }

try {
    $pdo = Database::getConnection();

    // 1) Resolve a mensagem só pelo id (descobre o canal dono). 404 se inexistente.
    $stmt = $pdo->prepare(
        "SELECT m.id, m.instance_id, m.wamid, m.remote_jid, m.direction, m.message_type,
                m.media_mimetype, m.media_filename, m.media_url, m.media_base64, m.raw_payload
         FROM whatsapp_messages m
         WHERE m.id = ?
         LIMIT 1"
    );
    $stmt->execute([$msgId]);
    $msg = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$msg) { http_response_code(404); echo 'Mensagem não encontrada'; exit; }

    // 2) Autorização de canal: a conta precisa de 'view' sobre o canal dono da
    //    mensagem. Usa check() (não assert) para devolver 404 — não 403 — e não
    //    revelar a existência da mídia a quem não tem acesso (anti-enumeração).
    $chk = WhatsAppChannelAccessService::check($pdo, $accountId, (int)$msg['instance_id'], 'view');
    if (!$chk) { http_response_code(404); echo 'Mensagem não encontrada'; exit; }

    // debug=1 expõe respostas cruas da Evolution + erros internos — restrito ao DONO
    // do canal (conta com acesso compartilhado nunca usa o modo diagnóstico). Fase 2 E.
    $debug = $debug && ($chk['access_type'] === 'owner');

    $instModel = new WhatsAppInstance();
    // 3) Credenciais SEMPRE do DONO do canal (nunca da conta requisitante/front).
    $cfg       = $instModel->getSettings((int)$chk['owner_account_id']);
    $name      = $chk['instance_name'];
    $apiKey    = $cfg['evolution_api_key']  ?? '';
    $baseUrl   = rtrim($cfg['evolution_base_url'] ?? 'http://localhost:8080', '/');
    $evo       = new EvolutionApiService($cfg);

    $log = [];
    $msgType = $msg['message_type'] ?? 'text';
    $isOutbound = ($msg['direction'] ?? 'inbound') === 'outbound';
    // WhatsApp CDN criptografa TODOS os arquivos — URL direta só pode funcionar se
    // a Evolution API armazena o arquivo localmente. Para mensagens RECEBIDAS,
    // vamos sempre tentar a URL direta primeiro (pode funcionar se Evolution tem local).
    // Para mensagens ENVIADAS com base64 já salvo, o cache do passo 1 resolve.
    $skipDirectUrl = in_array($msgType, ['audio', 'video', 'document', 'sticker'])
                  || ($isOutbound && !empty($msg['media_base64'])); // outbound já tem cache, pula URL

    // ── 1. Cache: base64 já salvo no banco ──────────────────────────────────
    $base64 = $msg['media_base64'] ?? null;
    if ($base64) { $log[] = 'fonte: cache DB'; }

    // ── 2. Tenta URL direta via proxy curl (apenas imagens — podem estar em cache local do Evolution) ────
    if (!$base64 && !$skipDirectUrl && !empty($msg['media_url'])) {
        $url = $msg['media_url'];
        $log[] = "tentando URL direta: $url";

        // B4 (auditoria): so manda a apikey da Evolution se a URL for do PROPRIO host da
        // Evolution. media_url normalmente e o CDN do WhatsApp (host diferente) — mandar a
        // credencial pra la vazaria a chave via MITM/redirect. TLS verify configuravel (mesmo
        // padrao do EvolutionApiService) e redirect so via HTTPS (nunca rebaixa pra HTTP).
        $sameHost  = parse_url($url, PHP_URL_HOST) !== null
                  && parse_url($url, PHP_URL_HOST) === parse_url($baseUrl, PHP_URL_HOST);
        $tlsVerify = !in_array(strtolower(\App\Core\EnvLoader::get('EVOLUTION_TLS_VERIFY', 'true')), ['false','0','no','off'], true);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 3,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT         => 15,
            CURLOPT_HTTPHEADER      => $sameHost ? array_filter(["apikey: $apiKey"]) : [],
            CURLOPT_SSL_VERIFYPEER  => $tlsVerify,
            CURLOPT_SSL_VERIFYHOST  => $tlsVerify ? 2 : 0,
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $mime   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
        curl_close($ch);

        // Valida magic bytes para garantir que não é conteúdo criptografado
        $isValidImage = false;
        if ($body && strlen($body) > 4) {
            $magic = substr($body, 0, 4);
            $isValidImage = str_starts_with($magic, "\xFF\xD8")      // JPEG
                         || str_starts_with($magic, "\x89PNG")       // PNG
                         || str_starts_with($magic, "GIF8")          // GIF
                         || str_starts_with($magic, "RIFF")          // WebP
                         || str_starts_with($magic, "OggS");         // OGG audio
        }

        $isMedia = $status >= 200 && $status < 300
                && $body !== false
                && strlen($body) > 100
                && !str_contains($mime, 'json')
                && !str_contains($mime, 'html')
                && $isValidImage;  // só aceita se conteúdo for mídia real (não criptografado)

        if ($isMedia) {
            $log[] = "URL direta OK ($status, " . strlen($body) . " bytes)";
            // Cacheia como base64 apenas se pequeno o suficiente
            $base64 = base64_encode($body);
            if (strlen($base64) < 4_000_000) {
                try {
                    $pdo->prepare('UPDATE whatsapp_messages SET media_base64 = ? WHERE id = ?')
                        ->execute([$base64, $msgId]);
                } catch (\Throwable $_) {}
            }

            // Entrega direto sem precisar decodificar de volta
            // Prefere MIME do banco (mais confiável que o CDN que retorna octet-stream)
            $finalMime = explode(';', ($msg['media_mimetype'] ?: $mime ?: 'application/octet-stream'))[0];
            if ($debug) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'fonte'=>'url_direta','mime'=>$finalMime,'bytes'=>strlen($body),'log'=>$log]); exit; }
            header('Content-Type: ' . $finalMime);
            header('Cache-Control: private, max-age=86400');
            header('Content-Length: ' . strlen($body));
            echo $body;
            exit;
        }
        $log[] = "URL direta FALHOU: HTTP $status, mime=$mime, bytes=" . strlen((string)$body);
    }

    // ── 3. Tenta getBase64FromMediaMessage com raw_payload ──────────────────
    if (!$base64 && !empty($msg['raw_payload'])) {
        $rawPayload = json_decode($msg['raw_payload'], true);
        $log[] = 'tentando getBase64FromMediaMessage';

        if ($rawPayload) {
            // Em debug: retorna resposta raw da API para diagnóstico
            if ($debug) {
                $rawResp = $evo->getMediaBase64Raw($name, $rawPayload);
                header('Content-Type: application/json');
                echo json_encode([
                    'log'          => $log,
                    'api_response' => $rawResp,
                    'has_url'      => !empty($msg['media_url']),
                    'has_payload'  => true,
                    'payload_keys' => array_keys($rawPayload),
                    'msg_type'     => $rawPayload['messageType'] ?? 'unknown',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                exit;
            }

            $b64 = $evo->getMediaBase64($name, $rawPayload);
            if ($b64) {
                // Remove prefixo data:mime;base64, antes de validar
                $b64clean = $b64;
                if (str_contains($b64clean, ',')) {
                    $b64clean = explode(',', $b64clean, 2)[1];
                }
                // Valida magic bytes do binário resultante
                $binCheck = base64_decode(substr($b64clean, 0, 12));
                $validDecrypted = $binCheck && (
                    str_starts_with($binCheck, "\xFF\xD8")  // JPEG
                 || str_starts_with($binCheck, "\x89PNG")  // PNG
                 || str_starts_with($binCheck, "GIF8")     // GIF
                 || str_starts_with($binCheck, "RIFF")     // WebP
                 || str_starts_with($binCheck, "OggS")     // OGG
                 || str_starts_with($binCheck, "ID3")      // MP3
                );
                $log[] = 'getBase64 OK, tamanho=' . strlen($b64) . ', magic_ok=' . ($validDecrypted ? 'SIM' : 'NAO');
                $base64 = $b64;
                // Só cacheia se conteúdo é mídia real (não criptografado)
                if ($validDecrypted && strlen($b64) < 4_000_000) {
                    try {
                        $pdo->prepare('UPDATE whatsapp_messages SET media_base64 = ? WHERE id = ?')
                            ->execute([$b64clean, $msgId]);  // salva sem prefixo
                    } catch (\Throwable $cacheErr) {
                        $log[] = 'cache DB ignorado: ' . $cacheErr->getMessage();
                    }
                } else {
                    $log[] = 'base64 grande demais para cachear (' . round(strlen($b64)/1024/1024, 1) . 'MB) — servindo sem cache';
                }
            } else {
                $log[] = 'getBase64 retornou vazio/null';
            }
        } else {
            $log[] = 'raw_payload inválido (parse error)';
        }
    } elseif (!$base64) {
        $log[] = 'raw_payload não disponível — sincronize para atualizar';
    }

    if ($debug) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => (bool)$base64, 'log' => $log, 'has_url' => !empty($msg['media_url']), 'has_payload' => !empty($msg['raw_payload']), 'has_cache' => !empty($msg['media_base64'])]);
        exit;
    }

    if (!$base64) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Mídia não disponível', 'log' => $log]);
        exit;
    }

    // Remove prefixo data:mime;base64, se existir
    if (str_contains($base64, ',')) {
        [$prefix, $base64] = explode(',', $base64, 2);
        // Extrai mime do prefixo se disponível
        if (preg_match('/data:([^;]+)/', $prefix, $m)) {
            $msg['media_mimetype'] = $msg['media_mimetype'] ?: $m[1];
        }
    }

    $binary = base64_decode($base64);
    $mime   = explode(';', $msg['media_mimetype'] ?: 'application/octet-stream')[0];

    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=86400');
    header('Content-Length: ' . strlen($binary));
    if ($msg['media_filename']) {
        header('Content-Disposition: inline; filename="' . addslashes($msg['media_filename']) . '"');
    }
    echo $binary;

} catch (\Throwable $e) {
    // P1 LGPD (2D.1) / auditoria 2026-06-01 MEDIA #32: NUNCA vaza getMessage/trace
    // ao cliente em prod. Loga server-side e devolve mensagem generica.
    // Antes, o ramo !$debug fazia `echo 'Erro: ' . $e->getMessage()`, expondo
    // nomes de tabela/coluna/path. Agora ambos os ramos passam pelo ErrorReporter.
    require_once __DIR__ . '/../../../app/Core/ErrorReporter.php';
    if ($debug) {
        \App\Core\ErrorReporter::handle($e);
    } else {
        // Resposta nao-JSON (este endpoint serve binario): loga e devolve texto generico.
        \App\Core\ErrorReporter::log($e, 'whatsapp/media');
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo 'Erro ao carregar mídia';
    }
}
