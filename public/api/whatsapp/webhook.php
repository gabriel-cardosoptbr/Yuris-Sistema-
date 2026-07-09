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
require_once __DIR__ . '/../../../app/Helpers/Crypto.php';     // decifra api_key do agente (GCM / APP_ENCRYPTION_KEY)
require_once __DIR__ . '/../../../app/Helpers/TotpHelper.php'; // fallback p/ api_key legada (CBC / MFA_ENCRYPTION_KEY)

use App\Models\Database;
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
                handleMessageUpsert($msg, $instanceId, $msgModel, $accountId);
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
                    $msgModel->updateStatus($wamid, $mapped);
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
            // Persiste o nome do contato em DOIS lugares:
            //   a) whatsapp_chats.contact_name  → nome no topo da conversa 1:1
            //   b) whatsapp_contacts.push_name  → resolve nome em grupos e 1:1 (JOINs)
            // Respeita is_manual_name (rename manual do usuário não é sobrescrito).
            $contacts = $data;
            if (isset($data['id'])) $contacts = [$data];
            $pdo = Database::getConnection();
            foreach ($contacts as $c) {
                if (!is_array($c)) continue;
                $jid  = $c['id'] ?? null;
                $name = $c['pushName'] ?? ($c['name'] ?? ($c['verifiedName'] ?? null));
                if (!$jid || !$name) continue;
                // Ignora "nome" que é só número (não é nome de verdade)
                if (preg_match('/^\d{6,}$/', (string)$name)) continue;
                // Ignora auto-nome ("Voce"/"you"/"eu"): rotularia o chat errado.
                if (in_array(mb_strtolower(trim((string)$name)), ['voce','você','you','eu'], true)) continue;
                $isGroup = str_ends_with((string)$jid, '@g.us') ? 1 : 0;
                // @lid (id de privacidade do WhatsApp) NAO e telefone discavel: nao derivar
                // phone dos seus digitos. Antes gravava o numero interno do @lid como
                // "telefone real" em whatsapp_contacts.phone, poluindo a coluna e enganando
                // resolvePhoneJid (que passava a "resolver" o @lid pra um numero invalido).
                // So @s.whatsapp.net vira phone. Mesmo padrao do sync.php.
                $phone   = ($isGroup || str_ends_with((string)$jid, '@lid'))
                    ? null
                    : preg_replace('/[^0-9]/', '', explode('@', (string)$jid)[0]);

                // a) nome de exibição no chat (não sobrescreve rename manual)
                $pdo->prepare(
                    'UPDATE whatsapp_chats SET contact_name = ?
                     WHERE instance_id = ? AND remote_jid = ? AND COALESCE(is_manual_name,0) = 0'
                )->execute([$name, $instanceId, $jid]);

                // b) tabela de contatos (alimenta a resolução de nome em grupos/1:1)
                $pdo->prepare(
                    'INSERT INTO whatsapp_contacts (account_id, instance_id, remote_jid, push_name, phone, is_group)
                     VALUES (?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE
                       push_name = IF(COALESCE(is_manual_name,0) = 0, VALUES(push_name), push_name),
                       phone     = IF(VALUES(phone) IS NOT NULL AND VALUES(phone) <> "", VALUES(phone), phone)'
                )->execute([$accountId, $instanceId, $jid, $name, $phone, $isGroup]);
            }
            break;

        // ── Chats upsert (sincronização inicial) ─────────────────────────
        case 'chats.upsert':
        case 'chats.update':
            $chats = $data;
            if (isset($data['id'])) $chats = [$data];
            $pdo = Database::getConnection();
            foreach ($chats as $c) {
                $jid      = $c['id']       ?? null;
                $name     = $c['name']     ?? null;
                $unread   = (int)($c['unreadCount'] ?? 0);
                if (!$jid) continue;
                // Ignora status/broadcast e newsletter: nao sao conversas (nao viram chat "0").
                if (str_ends_with((string)$jid, '@broadcast') || str_contains((string)$jid, '@newsletter')) continue;
                $jid      = WhatsAppMessage::resolvePhoneJid($pdo, (int)$instanceId, $jid); // @lid -> telefone quando conhecido
                $isGroup  = str_ends_with((string)$jid, '@g.us') ? 1 : 0;
                // Nao pre-cria shell @lid 1:1 vazio (fantasma): so se ja houver mensagem sob o jid.
                if (!$isGroup && str_ends_with((string)$jid, '@lid')) {
                    $ex = $pdo->prepare('SELECT 1 FROM whatsapp_messages WHERE instance_id = ? AND remote_jid = ? LIMIT 1');
                    $ex->execute([(int)$instanceId, $jid]);
                    if (!$ex->fetchColumn()) continue;
                }
                // Nao rotula com auto-nome ("Voce" etc.)
                $cn = trim((string)$name);
                if ($cn !== '' && in_array(mb_strtolower($cn), ['voce','você','you','eu'], true)) $cn = '';
                $name = $cn !== '' ? $cn : null;
                $s = $pdo->prepare(
                    'INSERT INTO whatsapp_chats (account_id, instance_id, remote_jid, contact_name, unread_count, is_group)
                     VALUES (?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE
                       account_id   = IF(account_id IS NULL, VALUES(account_id), account_id),
                       contact_name = IF(COALESCE(is_manual_name,0)=0 AND VALUES(contact_name) IS NOT NULL AND VALUES(contact_name) <> "", VALUES(contact_name), contact_name),
                       unread_count = VALUES(unread_count)'
                );
                $s->execute([$accountId, $instanceId, $jid, $name, $unread, $isGroup]);
            }
            break;

        // ── Grupos: nome do grupo + participantes ────────────────────────
        // Mantém membros/nome atualizados em tempo real, sem depender do
        // "Sincronizar" manual. Defensivo: shape varia por versão da Evolution,
        // então um payload inesperado NÃO derruba o webhook (try/catch local).
        case 'groups.upsert':
        case 'groups.update':
            try {
                $pdo    = Database::getConnection();
                $groups = $data;
                if (isset($data['id'])) $groups = [$data];
                foreach ($groups as $g) {
                    if (!is_array($g)) continue;
                    $gjid = $g['id'] ?? null;
                    if (!$gjid) continue;
                    $subj = $g['subject'] ?? ($g['subjectName'] ?? null);
                    // Foto do grupo: grava a pictureUrl quando vier no evento (antes só o
                    // nome era persistido → grupo novo ficava sem foto até "Sincronizar").
                    $gpic = $g['pictureUrl'] ?? ($g['profilePictureUrl'] ?? ($g['profilePicUrl'] ?? null));
                    if ($subj || $gpic) {
                        $pdo->prepare(
                            'INSERT INTO whatsapp_chats (account_id, instance_id, remote_jid, contact_name, profile_pic_url, is_group)
                             VALUES (?,?,?,?,?,1)
                             ON DUPLICATE KEY UPDATE
                               account_id      = IF(account_id IS NULL, VALUES(account_id), account_id),
                               contact_name    = IF(COALESCE(is_manual_name,0)=0 AND VALUES(contact_name) IS NOT NULL AND VALUES(contact_name) <> "", VALUES(contact_name), contact_name),
                               profile_pic_url = IF(VALUES(profile_pic_url) IS NOT NULL AND VALUES(profile_pic_url) <> "", VALUES(profile_pic_url), profile_pic_url)'
                        )->execute([$accountId, $instanceId, $gjid, $subj, $gpic]);
                    }
                    if (!empty($g['participants']) && is_array($g['participants'])) {
                        upsertGroupParticipants($pdo, $accountId, $instanceId, $gjid, $g['participants']);
                    }
                }
            } catch (\Throwable $_) { /* não derruba o webhook */ }
            break;

        case 'group-participants.update':
        case 'groups.participants.update':
            try {
                $pdo    = Database::getConnection();
                $gjid   = $data['id'] ?? ($data['groupJid'] ?? null);
                $action = strtolower((string)($data['action'] ?? 'add'));
                $parts  = $data['participants'] ?? [];
                if ($gjid && is_array($parts)) {
                    if (in_array($action, ['remove','leave'], true)) {
                        $del = $pdo->prepare('DELETE FROM whatsapp_group_members WHERE instance_id = ? AND group_jid = ? AND participant_jid = ?');
                        foreach ($parts as $pj) { if (is_string($pj) && $pj !== '') $del->execute([$instanceId, $gjid, $pj]); }
                    } else {
                        $defaultRole = $action === 'promote' ? 'admin' : 'member';
                        upsertGroupParticipants($pdo, $accountId, $instanceId, $gjid, $parts, $defaultRole);
                    }
                }
            } catch (\Throwable $_) { /* não derruba o webhook */ }
            break;
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
        flushResponse();
        foreach ($GLOBALS['__agent_tasks'] as $task) {
            runAgentReply($task);
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
        extractMessageContent($message);

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
    $quotedWamid = extractQuotedWamid($message);
    if (!$quotedWamid && !empty($msg['contextInfo']['stanzaId'])) {
        $quotedWamid = $msg['contextInfo']['stanzaId'];
    }
    // Snapshot da citação (autor + texto) que o WhatsApp manda EMBUTIDO no
    // contextInfo. Garante que a citação apareça mesmo se a mensagem original
    // não estiver sincronizada no YURIS. Ver migration 089.
    [$quotedSenderName, $quotedText] = extractQuotedSnapshot($message, $msg);

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
            maybeQueueAgentReply($accountId, $instanceId, $remoteJid, $msgType, $msgContent, $wamid, $pushName);
        }
    } else {
        // fromMe: distingue o ECO do proprio bot (ignora, anti-loop) do ENVIO MANUAL por um
        // humano via Yuris/celular (sinal de atendimento humano -> pausa o bot na conversa).
        maybeHandleHumanSend($accountId, $instanceId, $remoteJid, $wamid, $msgContent);
    }
}

/**
 * ALTA #5 — avalia se a mensagem recebida deve disparar o Agente IA e, em caso
 * afirmativo, enfileira a tarefa em $GLOBALS['__agent_tasks'] (não envia nada aqui).
 *
 * GUARDRAILS (revisados pelo dono):
 *   (a) responde só CHAT INDIVIDUAL — ignora grupos (@g.us);
 *   (b) fromMe já foi excluído pelo chamador (estamos em !$fromMe) — evita loop:
 *       a própria resposta do agente volta como fromMe=true e não reentra;
 *   (e) respeita o toggle: só dispara se houver agent_config com enabled=1.
 *   + só mensagens de TEXTO com conteúdo não-vazio.
 */
function maybeQueueAgentReply(int $accountId, int $instanceId, ?string $remoteJid, string $msgType, ?string $msgContent, ?string $wamid = null, ?string $pushName = null): void
{
    try {
        // (a) só chat individual — grupos terminam em @g.us
        if (!$remoteJid || str_ends_with($remoteJid, '@g.us')) return;
        // Ignora JIDs de sistema/broadcast (status@broadcast, newsletter etc.)
        if (str_ends_with($remoteJid, '@broadcast') || str_contains($remoteJid, '@newsletter')) return;
        // texto + midia (a midia vira confirmacao de recebimento no motor, sem chamar IA).
        $allowed = ['text', 'image', 'audio', 'document', 'video', 'sticker'];
        if (!in_array($msgType, $allowed, true)) return;
        $userText = trim((string)$msgContent);
        if ($msgType === 'text' && $userText === '') return;

        $pdo = Database::getConnection();

        // Takeover (botão "Assumir conversa" OU envio manual humano): se a conversa esta
        // pausada, o agente nao responde. agent_paused e por conversa (instance+jid).
        try {
            $stPause = $pdo->prepare(
                'SELECT agent_paused FROM whatsapp_chats WHERE instance_id = ? AND remote_jid = ? LIMIT 1'
            );
            $stPause->execute([$instanceId, $remoteJid]);
            if ((int)$stPause->fetchColumn() === 1) return; // conversa assumida por humano
        } catch (\Throwable $_p) {
            error_log('[whatsapp/agent] agent_paused indisponível (migration 082?): ' . $_p->getMessage());
            return;
        }

        // FONTE ÚNICA DA VERDADE: seleciona o agente PELA INSTÂNCIA (1 agente por canal),
        // so dispara com o canal conectado (status=open). Carrega o config COMPLETO (config
        // rica da migration 097) para o motor de pre-atendimento.
        $st  = $pdo->prepare(
            'SELECT ac.*
               FROM agent_configs ac
               JOIN whatsapp_instances wi ON wi.id = ac.whatsapp_instance_id
              WHERE ac.whatsapp_instance_id = ? AND ac.enabled = 1
                AND wi.status = "open"
              LIMIT 1'
        );
        $st->execute([$instanceId]);
        $cfg = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$cfg) return; // sem agente ativo conectado para este canal → não responde

        // Provider: o do agente, ou OpenAI por padrao (a chave OpenAI e GLOBAL, no Master).
        $provider = strtolower(trim((string)($cfg['provider'] ?? ''))) ?: 'openai';
        if (!in_array($provider, ['openai', 'anthropic'], true)) {
            error_log('[whatsapp/agent] provider não suportado para auto-resposta: ' . $provider);
            return;
        }

        // Chave: a do canal (agent_configs.api_key_enc) tem prioridade; senao, para OpenAI,
        // usa a Security Key GLOBAL cadastrada no Painel Master (vale p/ todas as instancias).
        $apiKey = decryptAgentApiKey($cfg['api_key_enc'] !== null ? (string)$cfg['api_key_enc'] : null);
        if (($apiKey === null || $apiKey === '') && $provider === 'openai') {
            require_once __DIR__ . '/../../../app/Helpers/AiSettings.php';
            $apiKey = \App\Helpers\AiSettings::openAiKey($pdo);
        }
        if ($apiKey === null || $apiKey === '') {
            error_log('[whatsapp/agent] sem chave de IA (nem por canal, nem global OpenAI) — auto-resposta abortada');
            return;
        }
        $cfg['provider'] = $provider; // provider resolvido segue no task

        // O trabalho pesado (1 chamada de IA + envio + efeitos) roda DEPOIS do 200, em
        // runAgentReply() -> IntakeEngine. Aqui so enfileiramos.
        $GLOBALS['__agent_tasks'][] = [
            'cfg'         => $cfg,            // config completa do agente (dono do canal)
            'api_key'     => $apiKey,
            'provider'    => $provider,
            'account_id'  => $accountId,
            'instance_id' => $instanceId,
            'remote_jid'  => $remoteJid,
            'user_text'   => $userText,
            'msg_type'    => $msgType,
            'wamid'       => $wamid,
            'push_name'   => $pushName,
        ];
    } catch (\Throwable $e) {
        // Nunca deixa a avaliação do agente derrubar o webhook.
        error_log('[whatsapp/agent] maybeQueueAgentReply falhou: ' . $e->getMessage());
    }
}

/**
 * Mensagem fromMe (saida): distingue o ECO do proprio bot do ENVIO MANUAL humano.
 *  - eco do bot (wamid no ledger ai_intake_messages.origin='bot', ou conteudo == ultima
 *    resposta do bot): ignora (anti-loop; nao reprocessa, nao pausa).
 *  - envio manual de um humano numa conversa com sessao ativa do agente: pausa o bot
 *    (human takeover na mesma conversa, mesma instancia).
 * NUNCA usa apenas fromMe para decidir (fromMe da Evolution e historicamente nao confiavel).
 */
function maybeHandleHumanSend(int $accountId, int $instanceId, ?string $remoteJid, ?string $wamid, ?string $msgContent): void
{
    try {
        if (!$remoteJid || str_ends_with($remoteJid, '@g.us') || str_ends_with($remoteJid, '@broadcast') || str_contains($remoteJid, '@newsletter')) return;
        require_once __DIR__ . '/../../../app/Services/AiIntake/IntakeSessionRepository.php';
        $pdo  = Database::getConnection();
        $repo = new \App\Services\AiIntake\IntakeSessionRepository($pdo);

        // 1) eco do proprio bot por wamid -> ignora
        if ($repo->isBotEcho($wamid)) return;

        // ha sessao ativa do agente nesta conversa?
        $sess = $repo->findActiveSession($instanceId, $remoteJid);
        if (!$sess) return;

        // 2) defesa extra: conteudo identico a ultima resposta do bot -> tambem e eco
        $txt = trim((string)$msgContent);
        if ($txt !== '') {
            $st = $pdo->prepare("SELECT content FROM ai_intake_messages WHERE session_id = ? AND origin = 'bot' ORDER BY id DESC LIMIT 1");
            $st->execute([(int)$sess['id']]);
            $last = (string)($st->fetchColumn() ?: '');
            if ($last !== '' && mb_strtolower(trim($last)) === mb_strtolower($txt)) return;
        }

        // 3) envio manual humano -> pausa o bot (se ainda nao pausado)
        if (!in_array($sess['controller_mode'] ?? '', ['human_takeover', 'bot_paused'], true)) {
            $repo->pauseForHuman($instanceId, $remoteJid, null);
            error_log('[whatsapp/agent] envio manual humano detectado -> bot pausado em ' . $remoteJid);
        }
    } catch (\Throwable $e) {
        error_log('[whatsapp/agent] maybeHandleHumanSend falhou: ' . $e->getMessage());
    }
}

function extractMessageContent(array $message): array
{
    // text
    if (!empty($message['conversation'])) {
        return ['text', $message['conversation'], null, null, null, null];
    }
    if (!empty($message['extendedTextMessage']['text'])) {
        return ['text', $message['extendedTextMessage']['text'], null, null, null, null];
    }
    // image
    if (!empty($message['imageMessage'])) {
        $m = $message['imageMessage'];
        return ['image', null, $m['caption'] ?? null, $m['url'] ?? null, $m['mimetype'] ?? 'image/jpeg', null];
    }
    // video
    if (!empty($message['videoMessage'])) {
        $m = $message['videoMessage'];
        return ['video', null, $m['caption'] ?? null, $m['url'] ?? null, $m['mimetype'] ?? 'video/mp4', null];
    }
    // document
    if (!empty($message['documentMessage'])) {
        $m = $message['documentMessage'];
        return ['document', null, $m['caption'] ?? null, $m['url'] ?? null, $m['mimetype'] ?? null, $m['fileName'] ?? null];
    }
    if (!empty($message['documentWithCaptionMessage']['message']['documentMessage'])) {
        $m = $message['documentWithCaptionMessage']['message']['documentMessage'];
        return ['document', null, $m['caption'] ?? null, $m['url'] ?? null, $m['mimetype'] ?? null, $m['fileName'] ?? null];
    }
    // audio
    if (!empty($message['audioMessage'])) {
        $m = $message['audioMessage'];
        return ['audio', null, null, $m['url'] ?? null, $m['mimetype'] ?? 'audio/ogg', null];
    }
    // sticker
    if (!empty($message['stickerMessage'])) {
        return ['sticker', null, null, $message['stickerMessage']['url'] ?? null, 'image/webp', null];
    }
    // reaction — sinalizador; handler trata separado (grava em whatsapp_reactions)
    if (!empty($message['reactionMessage'])) {
        return ['reaction', $message['reactionMessage']['text'] ?? '👍', null, null, null, null];
    }
    // location → mostra como msg de texto com label amigavel (antes virava "nao suportada")
    if (!empty($message['locationMessage'])) {
        $loc = $message['locationMessage'];
        $lat = $loc['degreesLatitude']  ?? '';
        $lng = $loc['degreesLongitude'] ?? '';
        $name = $loc['name'] ?? '';
        $text = '📍 Localização' . ($name ? ': ' . $name : '') . ($lat && $lng ? " ($lat, $lng)" : '');
        return ['text', $text, null, null, null, null];
    }
    // contato compartilhado
    if (!empty($message['contactMessage'])) {
        $name = $message['contactMessage']['displayName'] ?? 'Contato';
        return ['text', '👤 Contato compartilhado: ' . $name, null, null, null, null];
    }
    // enquete (pollCreationMessage)
    if (!empty($message['pollCreationMessage'])) {
        $title = $message['pollCreationMessage']['name'] ?? 'Enquete';
        return ['text', '📊 ' . $title, null, null, null, null];
    }

    return ['text', null, null, null, null, null];
}

/**
 * UPSERT de participantes de grupo em whatsapp_group_members.
 * Aceita itens como string (JID puro) ou objeto {id, admin, phoneNumber, pushName}.
 * Nunca grava número como "nome"; telefone vai na coluna própria.
 */
function upsertGroupParticipants(PDO $pdo, ?int $accountId, int $instanceId, string $groupJid, array $participants, string $defaultRole = 'member'): void
{
    $ins = $pdo->prepare(
        'INSERT INTO whatsapp_group_members
           (account_id, instance_id, group_jid, participant_jid, push_name, phone, role)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           push_name = IF(VALUES(push_name) IS NOT NULL, VALUES(push_name), push_name),
           phone     = IF(VALUES(phone)     IS NOT NULL, VALUES(phone),     phone),
           role      = VALUES(role)'
    );
    foreach ($participants as $p) {
        if (is_string($p)) {
            $pj = $p; $admin = null; $phoneJid = null; $pn = null;
        } elseif (is_array($p)) {
            $pj       = $p['id'] ?? ($p['jid'] ?? null);
            $admin    = $p['admin'] ?? null;
            $phoneJid = $p['phoneNumber'] ?? null;
            $pn       = $p['pushName'] ?? ($p['name'] ?? null);
        } else {
            continue;
        }
        if (!$pj) continue;
        $src    = $phoneJid ?: $pj;
        $digits = preg_replace('/[^0-9]/', '', explode('@', (string)$src)[0]);
        $phone  = ($digits && strlen($digits) >= 10 && strlen($digits) < 14) ? $digits : null;
        if ($pn !== null && preg_match('/^\d{6,}$/', (string)$pn)) $pn = null; // número não é nome
        $role   = match ($admin) {
            'superadmin' => 'superadmin',
            'admin'      => 'admin',
            default      => $defaultRole,
        };
        $ins->execute([(int)($accountId ?? 0), $instanceId, $groupJid, $pj, $pn, $phone, $role]);
    }
}

/**
 * Extrai o stanzaId da mensagem citada (reply).
 * Localizado em vários paths dependendo do tipo da msg que carrega o reply.
 */
function extractQuotedWamid(array $message): ?string
{
    // Reply em texto simples
    if (!empty($message['extendedTextMessage']['contextInfo']['stanzaId'])) {
        return $message['extendedTextMessage']['contextInfo']['stanzaId'];
    }
    // Reply em imagem/video/audio/sticker (todos têm contextInfo dentro do sub)
    foreach (['imageMessage', 'videoMessage', 'audioMessage', 'stickerMessage', 'documentMessage'] as $sub) {
        if (!empty($message[$sub]['contextInfo']['stanzaId'])) {
            return $message[$sub]['contextInfo']['stanzaId'];
        }
    }
    return null;
}

/**
 * Extrai o SNAPSHOT da mensagem citada (autor + trecho de texto) embutido no
 * contextInfo do payload. O WhatsApp sempre manda esse trecho junto da resposta,
 * então conseguimos exibir a citação mesmo sem ter a mensagem original no banco.
 * Procura o contextInfo em: topo do payload ($msg) → extendedTextMessage → subs de mídia.
 * Retorna [senderName|null, text|null].
 */
function extractQuotedSnapshot(array $message, array $msg): array
{
    // Acha o primeiro contextInfo disponível (topo > texto > mídia)
    $ci = $msg['contextInfo']
        ?? ($message['extendedTextMessage']['contextInfo'] ?? null);
    if (!$ci) {
        foreach (['imageMessage', 'videoMessage', 'audioMessage', 'stickerMessage', 'documentMessage'] as $sub) {
            if (!empty($message[$sub]['contextInfo'])) { $ci = $message[$sub]['contextInfo']; break; }
        }
    }
    if (!is_array($ci)) return [null, null];

    // Autor da mensagem citada (JID). Só guardamos se for telefone real — o nome
    // de verdade é resolvido depois no Model. Aqui guardamos o número como dica.
    $participant = $ci['participant'] ?? null;
    $senderName = null;
    if ($participant && !str_contains((string)$participant, '@lid')) {
        $digits = preg_replace('/\D/', '', explode('@', (string)$participant)[0]);
        if ($digits !== '') $senderName = $digits; // Model tenta trocar por nome real
    }

    // Texto da mensagem citada (vários formatos possíveis no quotedMessage)
    $qm = $ci['quotedMessage'] ?? [];
    $text = null;
    if (is_array($qm)) {
        $text = $qm['conversation']
            ?? ($qm['extendedTextMessage']['text'] ?? null)
            ?? ($qm['imageMessage']['caption'] ?? null)
            ?? ($qm['videoMessage']['caption'] ?? null)
            ?? ($qm['documentMessage']['caption'] ?? null)
            ?? null;
        if ($text === null) {
            if (isset($qm['imageMessage']))      $text = '📷 Imagem';
            elseif (isset($qm['videoMessage']))  $text = '🎥 Vídeo';
            elseif (isset($qm['audioMessage']))  $text = '🎵 Áudio';
            elseif (isset($qm['documentMessage'])) $text = '📄 Documento';
            elseif (isset($qm['stickerMessage'])) $text = '✨ Sticker';
        }
    }
    if (is_string($text) && mb_strlen($text) > 480) $text = mb_substr($text, 0, 477) . '…';

    return [$senderName, $text];
}

// ── ALTA #5: Agente IA (atendimento automático via LLM) ───────────────────────

/**
 * Garante que a resposta HTTP (200) já enviada chegue à Evolution e a conexão
 * seja LIBERADA antes de processarmos o LLM — para o webhook nunca travar (d).
 *
 *  • PHP-FPM: fastcgi_finish_request() devolve a resposta e libera o worker para
 *    o que vier depois (a Evolution recebe o 200 na hora).
 *  • mod_php / servidor embutido: fecha os buffers de saída na marra e marca
 *    ignore_user_abort(true) para o script continuar mesmo se a Evolution já
 *    tiver desconectado. set_time_limit(0) evita matar o processo no meio do
 *    envio (a chamada cURL tem timeout próprio e curto).
 */
function flushResponse(): void
{
    ignore_user_abort(true);
    @set_time_limit(0); // o cURL ao LLM tem timeout próprio e curto (15s)

    // Sinaliza fim de resposta ANTES de fechar — só funciona se headers não saíram.
    if (!headers_sent()) {
        @header('Connection: close');
        @header('Content-Length: ' . (string)ob_get_length());
    }

    if (function_exists('fastcgi_finish_request')) {
        // PHP-FPM: descarrega o buffer na resposta e libera o worker.
        @fastcgi_finish_request();
        return;
    }
    // Fallback sem FPM (mod_php / servidor embutido): empurra o buffer e fecha o que der.
    while (ob_get_level() > 0) { @ob_end_flush(); }
    @flush();
}

/**
 * Decifra a api_key do agente com compatibilidade de chave (espelha BAIXA #2 do
 * agent_settings.php): tenta o formato NOVO (Crypto / AES-256-GCM, "v1:…") e, se
 * falhar, cai para o LEGADO (TotpHelper / AES-256-CBC). Retorna null se nenhum
 * decifrar — o chamador então NÃO responde (em vez de quebrar).
 */
function decryptAgentApiKey(?string $enc): ?string
{
    if ($enc === null || $enc === '') return null;
    try {
        return Crypto::decrypt($enc);
    } catch (\Throwable $_) {
        try {
            return TotpHelper::decryptSecret($enc);
        } catch (\Throwable $_e) {
            return null;
        }
    }
}

/**
 * Executa a resposta automática: chama o LLM e, se obtiver texto, envia via
 * EvolutionApiService::sendText. TUDO em try/catch — guardrail (c)/(d): se o LLM
 * falhar/demorar, apenas loga e segue; nunca propaga exceção (já estamos depois
 * do 200, mas mantemos o processo saudável).
 *
 * @param array{account_id:int,instance_id:int,remote_jid:string,provider:string,api_key:string,prompt:string,user_text:string} $task
 */
function runAgentReply(array $task): void
{
    try {
        require_once __DIR__ . '/../../../app/Services/AiIntake/IntakeEngine.php';
        require_once __DIR__ . '/../../../app/Services/AiIntake/OpenAiProvider.php';
        require_once __DIR__ . '/../../../app/Services/AiIntake/AnthropicProvider.php';

        $apiKey   = (string)($task['api_key'] ?? '');
        $provider = ($task['provider'] === 'anthropic')
            ? new \App\Services\AiIntake\AnthropicProvider($apiKey)
            : new \App\Services\AiIntake\OpenAiProvider($apiKey);

        $pdo    = Database::getConnection();
        $engine = new \App\Services\AiIntake\IntakeEngine($pdo, $provider);
        $cfg    = is_array($task['cfg'] ?? null) ? $task['cfg'] : [];

        // 1 chamada de IA + controle deterministico + efeitos (handoff) ficam no motor.
        $result = $engine->handleInbound(
            $cfg,
            (int)$task['instance_id'],
            (string)$task['remote_jid'],
            null,
            (string)($task['user_text'] ?? ''),
            (string)($task['msg_type'] ?? 'text'),
            $task['wamid'] ?? null
        );

        if (empty($result['should_send']) || empty($result['reply'])) return;
        $reply = (string)$result['reply'];
        if (mb_strlen($reply) > 4096) $reply = mb_substr($reply, 0, 4096);

        // Envia pela MESMA instancia — credenciais do DONO do canal (account do agent_config).
        $ownerAcc  = (int)($cfg['account_id'] ?? $task['account_id']);
        $instModel = new WhatsAppInstance();
        $sett      = $instModel->getSettings($ownerAcc);
        $evo       = new EvolutionApiService($sett);
        $name      = $sett['evolution_instance'] ?? 'yuris-crm';
        $resp      = $evo->sendText($name, (string)$task['remote_jid'], $reply);
        if (!empty($resp['_error'])) {
            error_log('[whatsapp/agent] sendText falhou: ' . $resp['_error']);
            return;
        }

        // Anti-loop: registra o wamid que o BOT acabou de enviar (ledger). Quando o eco
        // (fromMe) voltar pelo webhook, isBotEcho() reconhece e ignora.
        $wamidOut = $resp['key']['id'] ?? ($resp['id'] ?? null);
        if (!empty($result['ai_message_id']) && $wamidOut) {
            try { $engine->repo()->attachWamid((int)$result['ai_message_id'], (string)$wamidOut, null); }
            catch (\Throwable $_) {}
        }

        // TEMPO REAL: persiste a resposta do bot em whatsapp_messages JA (igual ao envio
        // manual em send.php), em vez de esperar o eco fromMe voltar pelo webhook. Isso faz
        // a mensagem do robo aparecer NA HORA no preview da lista (upsertChat) e dentro da
        // conversa aberta. Dedup por (instance_id, wamid): quando o eco chegar, o save()
        // encontra a linha e so atualiza o status (nao duplica).
        // created_at do outbound: usa o messageTimestamp da Evolution (mesmo relogio do
        // inbound) quando disponivel. Sem isso, o relogio do servidor (se adiantado)
        // gravaria a msg do bot "no futuro" e empurraria o cursor do poll a frente,
        // escondendo a proxima msg do cliente ate reentrar. Fallback: hora local.
        $evoTs     = $resp['messageTimestamp'] ?? null;
        $createdAt = (is_numeric($evoTs) && (int)$evoTs > 0) ? date('Y-m-d H:i:s', (int)$evoTs) : date('Y-m-d H:i:s');
        try {
            require_once __DIR__ . '/../../../app/Models/WhatsAppMessage.php';
            (new WhatsAppMessage())->save([
                'account_id'      => $ownerAcc,
                'instance_id'     => (int)$task['instance_id'],
                'wamid'           => $wamidOut,
                'remote_jid'      => (string)$task['remote_jid'],
                'message_type'    => 'text',
                'message_content' => $reply,
                'direction'       => 'outbound',
                'status'          => 'sent',
                'created_at'      => $createdAt,
            ]);
        } catch (\Throwable $_) { /* espelho local nao pode derrubar o envio ja feito */ }
    } catch (\Throwable $e) {
        // Não vaza detalhe; só log server-side (LGPD). Ja estamos depois do 200.
        error_log('[whatsapp/agent] runAgentReply falhou: ' . $e->getMessage());
    }
}
