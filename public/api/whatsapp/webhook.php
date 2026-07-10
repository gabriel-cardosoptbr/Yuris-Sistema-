<?php
/**
 * webhook.php — recebe eventos da Evolution API e salva no banco.
 *
 * Este endpoint é chamado pela Evolution API, portanto:
 *  - Não exige sessão PHP
 *  - Não exige CSRF
 *  - Valida opcionalmente via header apikey
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/WhatsAppInstance.php';
require_once __DIR__ . '/../../../app/Models/WhatsAppMessage.php';
require_once __DIR__ . '/../../../app/Services/WebhookDispatcher.php';
require_once __DIR__ . '/../../../app/Services/EvolutionApiService.php';
require_once __DIR__ . '/../../../app/Services/WhatsAppWebhookParser.php';     // parsers puros do payload (strangler Pass 1)
require_once __DIR__ . '/../../../app/Services/WhatsAppWebhookEntitySync.php'; // persistencia de entidades contato/chat/grupo (strangler Pass 2)
require_once __DIR__ . '/../../../app/Services/WhatsAppAgentBridge.php';       // caminho do agente IA: gating/flush/decrypt/envio (strangler Pass 3)
require_once __DIR__ . '/../../../app/Helpers/Crypto.php';     // decifra api_key do agente (GCM / APP_ENCRYPTION_KEY)
require_once __DIR__ . '/../../../app/Helpers/TotpHelper.php'; // fallback p/ api_key legada (CBC / MFA_ENCRYPTION_KEY)

use App\Models\Database;
use App\Services\WhatsAppWebhookParser;
use App\Services\WhatsAppWebhookEntitySync;
use App\Services\WhatsAppAgentBridge;
use App\Helpers\Crypto;
use App\Helpers\TotpHelper;

header('Content-Type: application/json; charset=utf-8');

// ALTA #5 / guardrail (d): bufferiza a saída para que, em ambientes SEM PHP-FPM,
// flushResponse() consiga emitir Content-Length + fechar a conexão antes de
// processar o LLM. Em PHP-FPM o fastcgi_finish_request() cobre isso de qualquer
// forma; o buffer aqui é inofensivo (a resposta é pequena).
ob_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$raw     = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload inválido']);
    exit;
}

$event        = strtolower($payload['event']    ?? '');
$instanceName = $payload['instance']            ?? '';
$data         = $payload['data']                ?? [];

if (!$instanceName) {
    http_response_code(400);
    echo json_encode(['error' => 'Instance name ausente']);
    exit;
}

try {
    $instModel  = new WhatsAppInstance();
    $msgModel   = new WhatsAppMessage();

    // ─── LGPD P0 (1.8): identificação do tenant via apikey ──────────────────
    // A Evolution API envia POST sem session — antes da correção, o webhook
    // chamava findOrCreate($instanceName) SEM accountId, criando instância
    // órfã ou sobrescrevendo a do outro tenant (UNIQUE global de instance_name).
    //
    // Agora resolvemos o tenant pela apikey enviada — cada tenant tem sua
    // apikey distinta em whatsapp_settings (per-tenant após migration 046).
    // Compatibilidade: opcionalmente aceita ?account=N&token=hmac no URL para
    // ambientes que queiram identificar tenant por path (Opção A do plano).
    // Identificação do tenant: testamos TODAS as chaves candidatas (header E
    // ?token na URL) e aceitamos se QUALQUER uma identificar o tenant.
    //
    // Por que tentar as duas: a Evolution API, ao disparar o webhook, manda no
    // header `apikey` a chave DELA (a AUTHENTICATION_API_KEY global do .env, ou a
    // apikey da instância) — que NÃO é a evolution_api_key do tenant gravada no
    // Yuris. Se priorizássemos o header (como era antes), ele "venceria", não
    // bateria com nenhum tenant e retornaria 401 — descartando o ?token correto
    // que mandamos na URL. Testando ambas, o ?token (chave do tenant) sempre tem
    // chance de identificar, independente do que a Evolution põe no header.
    //
    // O ?token é a própria evolution_api_key do tenant e trafega só entre a
    // Evolution e o Yuris (mesma infra), sem cruzar fronteira de terceiros.
    $candidatos = [];
    foreach ([
        $_SERVER['HTTP_APIKEY']  ?? '',
        $_SERVER['HTTP_API_KEY'] ?? '',
        $_GET['token']           ?? '',
        $_GET['apikey']          ?? '',
    ] as $cand) {
        $cand = trim((string)$cand);
        if ($cand !== '' && !in_array($cand, $candidatos, true)) $candidatos[] = $cand;
    }
    if (empty($candidatos)) {
        http_response_code(401);
        echo json_encode(['error' => 'apikey obrigatória']);
        exit;
    }

    // Primeira chave candidata que identificar o tenant vence (timing-safe —
    // busca exata em coluna indexada).
    $accountId = null;
    foreach ($candidatos as $cand) {
        $accountId = $instModel->findAccountByApiKey($cand);
        if ($accountId !== null) break;
    }
    if ($accountId === null) {
        http_response_code(401);
        echo json_encode(['error' => 'apikey não bate com nenhum tenant configurado']);
        exit;
    }

    // Agora carrega settings DESSE tenant especificamente
    $cfg = $instModel->getSettings($accountId);
    if (empty($cfg['evolution_api_key'])) {
        // sanity check redundante — não deveria ocorrer pois findAccountByApiKey achou
        http_response_code(503);
        echo json_encode(['error' => 'Configuração inconsistente']);
        exit;
    }

    // Cria/encontra instância DENTRO do tenant identificado pela apikey
    $row        = $instModel->findOrCreate($instanceName, '', $accountId);
    $instanceId = (int)$row['id'];
    // ────────────────────────────────────────────────────────────────────────

    switch ($event) {

        // ── Mensagens recebidas ou enviadas ─────────────────────────────
        case 'messages.upsert':
        case 'send_message':
            $msgs = $data;
            if (isset($data['key'])) $msgs = [$data]; // único
            foreach ($msgs as $msg) {
                // C2 (auditoria): isola cada mensagem — uma excecao numa nao pode pular as
                // demais nem o flushResponse/runAgentReply do restante do lote.
                try {
                    handleMessageUpsert($msg, $instanceId, $msgModel, $accountId);
                } catch (\Throwable $e) {
                    error_log('[whatsapp/webhook] handleMessageUpsert falhou (mensagem pulada): ' . $e->getMessage());
                }
            }
            break;

        // ── Atualização de status (enviado, entregue, lido) ─────────────
        case 'messages.update':
            $updates = $data;
            if (isset($data['key'])) $updates = [$data];
            foreach ($updates as $upd) {
                $wamid  = $upd['key']['id']  ?? null;
                $status = strtolower($upd['update']['status'] ?? '');
                if ($wamid && $status) {
                    $mapped = match ($status) {
                        'server_ack','delivery_ack' => 'delivered',
                        'read'                      => 'read',
                        'played'                    => 'read',
                        default                     => 'sent',
                    };
                    $msgModel->updateStatus($instanceId, $wamid, $mapped);
                }
            }
            break;

        // ── Atualização de conexão ───────────────────────────────────────
        case 'connection.update':
            $state = strtolower($data['state'] ?? ($data['connection'] ?? 'close'));
            $allowed = ['open', 'close', 'connecting'];
            if (!in_array($state, $allowed, true)) $state = 'close';

            $extra = [];
            if (!empty($data['profileName'])) $extra['profile_name'] = $data['profileName'];
            if (!empty($data['wuid']))        $extra['phone']        = $data['wuid'];

            $instModel->updateStatus($instanceId, $state, $extra);

            if ($state === 'open') {
                $instModel->clearQrCode($instanceId);
            }
            break;

        // ── QR Code atualizado ───────────────────────────────────────────
        case 'qrcode.updated':
            $qr = $data['qrcode']['base64'] ?? ($data['base64'] ?? ($data['code'] ?? ''));
            if ($qr) {
                if (!str_starts_with($qr, 'data:')) {
                    $qr = 'data:image/png;base64,' . $qr;
                }
                $instModel->updateQrCode($instanceId, $qr);
            }
            break;

        // ── Atualização de contato ───────────────────────────────────────
        case 'contacts.update':
        case 'contacts.upsert':
            WhatsAppWebhookEntitySync::syncContacts($accountId, $instanceId, $data);
            break;

        // ── Chats upsert (sincronização inicial) ─────────────────────────
        case 'chats.upsert':
        case 'chats.update':
            WhatsAppWebhookEntitySync::syncChats($accountId, $instanceId, $data);
            break;

        // ── Grupos: nome do grupo + participantes ────────────────────────
        // Mantém membros/nome atualizados em tempo real, sem depender do
        // "Sincronizar" manual. Defensivo: shape varia por versão da Evolution,
        // então um payload inesperado NÃO derruba o webhook (try/catch local).
        case 'groups.upsert':
        case 'groups.update':
            WhatsAppWebhookEntitySync::syncGroups($accountId, $instanceId, $data);
            break;

        case 'group-participants.update':
        case 'groups.participants.update':
            WhatsAppWebhookEntitySync::syncGroupParticipants($accountId, $instanceId, $data);
            break;
    }

    // 4B (cursor de eventos): sinaliza novidade NESTE canal para o poll barato do front
    // (chat.js/poll.php) refazer lista + conversa aberta so quando muda. Cobre os eventos
    // que alteram a conversa/lista; connection/qr ficam de fora (o status ja e lido a
    // parte). Best-effort (bumpEvents nunca derruba o webhook).
    if (in_array($event, [
        'messages.upsert', 'send_message', 'messages.update',
        'contacts.update', 'contacts.upsert',
        'chats.upsert', 'chats.update',
        'groups.upsert', 'groups.update',
        'group-participants.update', 'groups.participants.update',
    ], true)) {
        $instModel->bumpEvents($instanceId);
    }

    echo json_encode(['ok' => true, 'event' => $event]);

    // ── ALTA #5: atendimento automático via LLM (após responder 200) ──────────
    // GUARDRAIL (d): o webhook da Evolution NUNCA pode travar/atrasar. A geração
    // de resposta via LLM (cURL ~15s) + envio (sendText) acontece SOMENTE depois
    // de devolvermos o 200 e liberarmos a conexão. flushResponse() fecha a conexão
    // com a Evolution (fastcgi_finish_request quando em FPM; senão flush + ignore_
    // user_abort) — a Evolution recebe o 200 imediatamente e nós seguimos o
    // processamento em "background" no mesmo processo. Qualquer falha no LLM é
    // engolida em runAgentReply (try/catch) e só vai pro log.
    if (!empty($GLOBALS['__agent_tasks'])) {
        WhatsAppAgentBridge::flushResponse();
        foreach ($GLOBALS['__agent_tasks'] as $task) {
            WhatsAppAgentBridge::runAgentReply($task);
        }
    }

} catch (Throwable $e) {
    // Nao vaza getMessage/file/line na resposta (LGPD/seguranca — auditoria 2026-06-01).
    // Loga server-side pra diagnostico; cliente recebe so mensagem generica.
    error_log('[whatsapp/webhook] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno']);
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function handleMessageUpsert(array $msg, int $instanceId, WhatsAppMessage $model, int $accountId): void
{
    $key       = $msg['key']       ?? [];
    $message   = $msg['message']   ?? [];
    $wamid     = $key['id']        ?? null;
    $remoteJid = $key['remoteJid'] ?? null;
    $fromMe    = (bool)($key['fromMe'] ?? false);
    // participant: JID real do autor da mensagem em grupos. Em chat 1:1 vem null.
    // Sem isso, resolveSenderName mostra 'Você' ou o nome errado em grupos.
    $participantJid = $key['participant'] ?? ($msg['participant'] ?? null);

    if (!$remoteJid) return;
    // Ignora status/broadcast e newsletter: nao sao conversas (nao viram chat "0").
    if (str_ends_with($remoteJid, '@broadcast') || str_contains($remoteJid, '@newsletter')) return;
    // Prevencao da duplicacao @lid x telefone: se o chat 1:1 vier como id de privacidade
    // @lid e ja conhecermos o numero real (de grupos/contatos), grava sob o JID do telefone.
    $remoteJid = WhatsAppMessage::resolvePhoneJid(Database::getConnection(), $instanceId, $remoteJid);

    $pushName  = $msg['pushName']          ?? null;
    $ts        = $msg['messageTimestamp']  ?? time();
    $createdAt = date('Y-m-d H:i:s', is_numeric($ts) ? (int)$ts : time());

    [$msgType, $msgContent, $caption, $mediaUrl, $mimetype, $filename] =
        WhatsAppWebhookParser::extractMessageContent($message);

    // ── Reaction recebida via webhook ────────────────────────────────────────
    // Grava em whatsapp_reactions (UPSERT por reactor) em vez de criar uma
    // msg ghost. Sem isso o painel não atualizava pílulas quando alguém reagia
    // pelo celular.
    if ($msgType === 'reaction') {
        $rm        = $message['reactionMessage'] ?? [];
        $tgtKey    = $rm['key'] ?? [];
        $tgtWamid  = $tgtKey['id'] ?? null;
        $emoji     = $rm['text'] ?? '';
        $reactorJid = $participantJid ?: ($fromMe ? 'me' : $remoteJid);

        if ($tgtWamid !== null) {
            $model->upsertReaction([
                'account_id'   => $accountId,
                'instance_id'  => $instanceId,
                'target_wamid' => $tgtWamid,
                'reactor_jid'  => $fromMe ? 'me' : $reactorJid,
                'reactor_name' => $pushName,
                'emoji'        => $emoji,
                'is_from_me'   => $fromMe ? 1 : 0,
            ]);
        }
        return; // não salva como mensagem
    }

    // ── Quoted (reply) — extrai stanzaId da msg citada pra exibir preview ───
    // Evolution v2: reply em TEXTO SIMPLES vem como messageType=conversation e o
    // contextInfo (com stanzaId) fica no TOPO do payload (irmão de "message"), NÃO
    // dentro de extendedTextMessage. Sem checar o topo, citar um texto não capturava
    // a citação (bug 2026-05-31). Reply em mídia já vem no sub-objeto da mídia.
    $quotedWamid = WhatsAppWebhookParser::extractQuotedWamid($message);
    if (!$quotedWamid && !empty($msg['contextInfo']['stanzaId'])) {
        $quotedWamid = $msg['contextInfo']['stanzaId'];
    }
    // Snapshot da citação (autor + texto) que o WhatsApp manda EMBUTIDO no
    // contextInfo. Garante que a citação apareça mesmo se a mensagem original
    // não estiver sincronizada no YURIS. Ver migration 089.
    [$quotedSenderName, $quotedText] = WhatsAppWebhookParser::extractQuotedSnapshot($message, $msg);

    // Para mídias: tenta baixar base64 completo via Evolution (mídia ainda está em cache local)
    $mediaBase64 = null;
    $mediaIsFull = 0;  // 1 = binário completo (sobrescreve thumbnail); 0 = só thumbnail
    $isMediaType = in_array($msgType, ['image', 'video', 'audio', 'sticker', 'document']);
    if ($isMediaType) {
        try {
            $instModel = new WhatsAppInstance();
            // P0 LGPD (1.8): settings per-tenant — resolve pelo accountId do webhook
            $cfg       = $instModel->getSettings($accountId);
            $evo       = new EvolutionApiService($cfg);
            $name      = $cfg['evolution_instance'] ?? 'yuris-crm';
            // E1: este download roda ANTES do 200 do webhook; timeout curto pra nao
            // pendurar o worker PHP-FPM se a Evolution demorar (o thumbnail abaixo cobre).
            $evo->setTimeout(3);
            $b64 = $evo->getMediaBase64($name, $msg);
            if ($b64) {
                $mediaBase64 = str_contains($b64, ',') ? explode(',', $b64, 2)[1] : $b64;
                $mediaIsFull = 1;
            }
        } catch (\Throwable $_) {}

        // Fallback: thumbnail embarcado (jpegThumbnail)
        if (!$mediaBase64) {
            $subMap = ['image'=>'imageMessage','video'=>'videoMessage','sticker'=>'stickerMessage','document'=>'documentMessage'];
            $subKey = $subMap[$msgType] ?? null;
            if ($subKey) {
                $thumb = $message[$subKey]['jpegThumbnail'] ?? null;
                if ($thumb) {
                    $mediaBase64 = str_contains($thumb, ',') ? explode(',', $thumb, 2)[1] : $thumb;
                }
            }
        }
    }

    $phone = preg_replace('/[^0-9]/', '', explode('@', $remoteJid)[0]);

    // ALTA #5: o Agente IA só pode responder a mensagem GENUINAMENTE NOVA. A
    // Evolution reenvia histórico (replay) ao reconectar e sync/webhook podem
    // correr juntos — sem isto, a MESMA mensagem dispararia o LLM várias vezes
    // (custo por token!). Checamos a existência do wamid ANTES do save (o save()
    // já é idempotente para o histórico de mensagens, mas retorna o mesmo id em
    // insert e update, então não dá pra distinguir pelo retorno). Só vale p/ inbound.
    $isNewInbound = false;
    if (!$fromMe) {
        if (empty($wamid)) {
            // Sem wamid não há como deduplicar — trata como nova (caso raro).
            $isNewInbound = true;
        } else {
            try {
                $pdoDup = Database::getConnection();
                $dupChk = $pdoDup->prepare(
                    'SELECT 1 FROM whatsapp_messages WHERE instance_id = ? AND wamid = ? LIMIT 1'
                );
                $dupChk->execute([$instanceId, $wamid]);
                $isNewInbound = ($dupChk->fetchColumn() === false);
            } catch (\Throwable $_) {
                // Em dúvida, NÃO dispara o agente (mais seguro/barato que duplicar).
                $isNewInbound = false;
            }
        }
    }

    $savedId = $model->save([
        'account_id'      => $accountId,  // P0 LGPD: passa explícito (defesa em profundidade)
        'instance_id'     => $instanceId,
        'wamid'           => $wamid,
        'remote_jid'      => $remoteJid,
        'participant_jid' => $participantJid,  // autor real em grupos
        'contact_name'    => $pushName,
        'phone'           => $phone,
        'message_type'    => $msgType,
        'message_content' => $msgContent,
        'caption'         => $caption,
        'media_url'       => $mediaUrl,
        'media_mimetype'  => $mimetype,
        'media_filename'  => $filename,
        'media_base64'    => $mediaBase64,
        'media_is_full'   => $mediaIsFull,
        'direction'       => $fromMe ? 'outbound' : 'inbound',
        'status'          => $fromMe ? 'sent' : 'delivered',
        'quoted_wamid'    => $quotedWamid,  // reply ao qual a msg responde (pode ser null)
        'quoted_sender_name' => $quotedSenderName, // snapshot do autor citado (fallback)
        'quoted_text'        => $quotedText,       // snapshot do texto citado (fallback)
        'raw_payload'     => json_encode($msg),
        'created_at'      => $createdAt,
    ]);

    // Fire webhook only for inbound messages (received from contacts)
    if (!$fromMe) {
        // P0 LGPD: resolve account_id da instância para não vazar evento cross-tenant
        $pdoInst = \App\Models\Database::getConnection();
        $instAccStmt = $pdoInst->prepare('SELECT account_id FROM whatsapp_instances WHERE id = ? LIMIT 1');
        $instAccStmt->execute([$instanceId]);
        $instAcc = $instAccStmt->fetchColumn();
        $ownerAcc = $instAcc !== false && $instAcc !== null ? (int)$instAcc : null;
        \App\Services\WebhookDispatcher::fire($ownerAcc, 'whatsapp.mensagem', \App\Services\WebhookDispatcher::buildPayload('whatsapp.mensagem', [
            'entity'    => 'whatsapp_message',
            'entity_id' => $savedId,
            'data' => [
                'wamid'        => $wamid,
                'remote_jid'   => $remoteJid,
                'phone'        => $phone,
                'contact_name' => $pushName,
                'message_type' => $msgType,
                'content'      => $msgContent,
                'caption'      => $caption,
                'created_at'   => $createdAt,
            ],
        ]));

        // ── ALTA #5: enfileira resposta automática do Agente IA (LLM) ─────────
        // Só ENFILEIRA aqui (checa guardrails + carrega o agent_config); o trabalho
        // pesado (cURL ao LLM + envio) roda DEPOIS do 200, em runAgentReply().
        // Guardrails verificados em maybeQueueAgentReply: texto, chat individual,
        // toggle enabled. fromMe já está excluído por estarmos dentro de !$fromMe;
        // $isNewInbound exclui replays/duplicatas (não re-dispara o LLM).
        if ($isNewInbound) {
            WhatsAppAgentBridge::maybeQueueAgentReply($accountId, $instanceId, $remoteJid, $msgType, $msgContent, $wamid, $pushName);
        }
    } else {
        // fromMe: distingue o ECO do proprio bot (ignora, anti-loop) do ENVIO MANUAL por um
        // humano via Yuris/celular (sinal de atendimento humano -> pausa o bot na conversa).
        WhatsAppAgentBridge::maybeHandleHumanSend($accountId, $instanceId, $remoteJid, $wamid, $msgContent);
    }
}
