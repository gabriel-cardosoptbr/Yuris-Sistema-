<?php
/**
 * media.php — Proxy de mídia com fallback duplo:
 * 1. Tenta URL direta com API key (funciona se Evolution armazena localmente)
 * 2. Tenta getBase64FromMediaMessage com raw_payload
 * 3. Retorna erro JSON se ambos falharem
 */
ob_start();
@ini_set('display_errors', '0');

require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/Account.php';
require_once __DIR__ . '/../../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../../app/Models/WhatsAppInstance.php';
require_once __DIR__ . '/../../../app/Services/EvolutionApiService.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';

use App\Models\Database;
use App\Helpers\AccountContext;

session_start(['read_and_close' => true]);
$_uid = $_SESSION['user_id'] ?? null;
ob_end_clean();

if (!$_uid) { http_response_code(401); exit; }

// ─── LGPD P0: tenant enforcement ─────────────────────────────────────────────
// Antes desta correção, qualquer usuário autenticado podia chamar
//   GET /api/whatsapp/media.php?msg_id=N
// e baixar mídia (imagens, áudios, PDFs, vídeos) de QUALQUER mensagem de
// QUALQUER conta — vazamento massivo de documentos jurídicos, atestados etc.
// Agora exigimos que a mensagem pertença a uma instance vinculada às contas
// acessíveis pela sessão atual (matriz + filiais com sync_whatsapp ativo).
$ctx           = AccountContext::fromSession();
$accessibleIds = $ctx->getAccessibleAccountIds('whatsapp');
if (empty($accessibleIds)) {
    http_response_code(403);
    echo 'Sem contas acessíveis';
    exit;
}
$inClause = implode(',', array_map('intval', $accessibleIds));
// ─────────────────────────────────────────────────────────────────────────────

// Modo diagnóstico: retorna JSON com info do que acontece
$debug = !empty($_GET['debug']);

$msgId = (int)($_GET['msg_id'] ?? 0);
if (!$msgId) { http_response_code(400); echo 'msg_id obrigatório'; exit; }

try {
    $pdo = Database::getConnection();

    // P0 LGPD: dupla verificação de ownership do tenant.
    //   • m.account_id IN (...)   → mensagens já migradas (após hardening 037)
    //   • wi.account_id IN (...)  → fallback via instância (mensagens antigas
    //                               podem ter account_id NULL, mas instance_id válido)
    // Se nenhuma das duas bater, retorna 404 (não 403, para não vazar existência).
    $stmt = $pdo->prepare(
        "SELECT m.id, m.wamid, m.remote_jid, m.direction, m.message_type,
                m.media_mimetype, m.media_filename, m.media_url, m.media_base64, m.raw_payload
         FROM whatsapp_messages m
         LEFT JOIN whatsapp_instances wi ON wi.id = m.instance_id
         WHERE m.id = ?
           AND (m.account_id IN ($inClause) OR wi.account_id IN ($inClause))
         LIMIT 1"
    );
    $stmt->execute([$msgId]);
    $msg = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$msg) { http_response_code(404); echo 'Mensagem não encontrada'; exit; }

    $instModel = new WhatsAppInstance();
    // P0 LGPD: usa accountId resolvido pelo tenant guard acima
    $cfg       = $instModel->getSettings($ctx->getAccountId());
    $name      = $cfg['evolution_instance'] ?? 'yuris-crm';
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

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => array_filter(["apikey: $apiKey"]),
            CURLOPT_SSL_VERIFYPEER => false,
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
    if ($debug) {
        // P1 LGPD (2D.1): em prod esconde getMessage/trace; em dev mantém debug
        require_once __DIR__ . '/../../../app/Helpers/ErrorReporter.php';
        \App\Helpers\ErrorReporter::handle($e);
    } else {
        http_response_code(500);
        echo 'Erro: ' . $e->getMessage();
    }
}
