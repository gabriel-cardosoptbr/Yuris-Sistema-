<?php
/**
 * sync.php — Sincroniza chats e mensagens via findMessages (não findChats).
 * findChats é quebrado no Evolution v2.x; todos os sistemas sérios usam
 * findMessages + agrupamento por remoteJid.
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/Account.php';
require_once __DIR__ . '/../../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../../app/Models/WhatsAppInstance.php';
require_once __DIR__ . '/../../../app/Models/WhatsAppMessage.php';
require_once __DIR__ . '/../../../app/Services/EvolutionApiService.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';

use App\Models\Database;
use App\Helpers\AccountContext;

session_start(['read_and_close' => true]);
$_uid  = $_SESSION['user_id']    ?? null;
$_csrf = $_SESSION['csrf_token'] ?? '';
header('Content-Type: application/json; charset=utf-8');
if (!$_uid) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }

$payload = json_decode(file_get_contents('php://input'), true) ?? [];
if (empty($payload['_csrf']) || $payload['_csrf'] !== $_csrf) {
    http_response_code(403); echo json_encode(['error'=>'CSRF inválido']); exit;
}

// P0 LGPD (1.8): contexto de tenant — settings/instance now per-tenant
$ctx       = AccountContext::fromSession();
$accountId = $ctx->getAccountId();

try {
    $instModel  = new WhatsAppInstance();
    $msgModel   = new WhatsAppMessage();
    $cfg        = $instModel->getSettings($accountId);
    $name       = $cfg['evolution_instance'] ?? 'yuris-crm';
    $row        = $instModel->findOrCreate($name, '', $accountId);
    $instanceId = (int)$row['id'];
    $evo        = new EvolutionApiService($cfg);
    $pdo        = Database::getConnection();

    $synced   = 0;
    $messages = 0;
    $contacts = 0;

    // 1. Buscar nomes reais dos grupos
    $groupMap = [];
    $apiGroups = $evo->fetchAllGroups($name);
    if (is_array($apiGroups)) {
        foreach ($apiGroups as $g) {
            if (empty($g['id'])) continue;
            $groupMap[$g['id']] = ['name' => $g['subject'] ?? null, 'pic' => $g['pictureUrl'] ?? null];
        }
    }

    // 1b. Buscar contatos individuais (nomes + fotos de perfil)
    $contactMap = [];
    $apiContacts = $evo->findContacts($name);
    $contactList = is_array($apiContacts) ? (isset($apiContacts[0]) ? $apiContacts : ($apiContacts['contacts'] ?? [])) : [];
    foreach ($contactList as $c) {
        $cJid = $c['id'] ?? null;
        if (!$cJid) continue;
        $cName = $c['pushName'] ?? $c['name'] ?? null;
        $cPic  = $c['profilePicUrl'] ?? null;
        if ($cName && !preg_match('/^\d{12,}$/', (string)$cName)) {
            $contactMap[$cJid] = ['name' => $cName, 'pic' => $cPic];
        }
    }

    // 2. Buscar mensagens paginadas — as mais recentes (últimas 20 páginas)
    // A API pagina do mais antigo (p.1) ao mais recente (última página)
    $allMessages = [];
    $probe      = $evo->request('POST', "/chat/findMessages/{$name}", ['limit' => 50, 'page' => 1]);
    $totalPages = (int)($probe['messages']['pages'] ?? 1);
    $startPage  = max(1, $totalPages - 19); // últimas 20 páginas = mais recentes

    for ($page = $startPage; $page <= $totalPages; $page++) {
        $apiResp = $evo->request('POST', "/chat/findMessages/{$name}", [
            'limit'         => 50,
            'page'          => $page,
            'downloadMedia' => true,
        ]);
        $records = $apiResp['messages']['records'] ?? [];
        if (empty($records)) continue;
        $allMessages = array_merge($allMessages, $records);
    }

    // 2c. Mapa telefone(dígitos) → NOME real, pra exibir nome em vez de número em
    // grupos. Fontes: contatos (findContacts) + pushName das próprias mensagens.
    // Usado tanto no save das mensagens quanto no preenchimento de group_members.
    $nameByPhone = [];
    foreach ($contactMap as $cJid => $cInfo) {
        $cd = preg_replace('/[^0-9]/', '', explode('@', (string)$cJid)[0]);
        if ($cd && !empty($cInfo['name'])) $nameByPhone[$cd] = $cInfo['name'];
    }
    foreach ($allMessages as $rr) {
        $pj = $rr['key']['participant'] ?? null;
        $pn = $rr['pushName'] ?? null;
        if ($pj && $pn && !preg_match('/^\d{6,}$/', (string)$pn)) {
            $pd = preg_replace('/[^0-9]/', '', explode('@', (string)$pj)[0]);
            if ($pd && !isset($nameByPhone[$pd])) $nameByPhone[$pd] = $pn;
        }
    }

    // 3. Derivar lista de chats únicos a partir das mensagens
    $jidMap = [];
    foreach ($allMessages as $r) {
        $key2 = $r['key'] ?? [];
        $jid  = $key2['remoteJid'] ?? null;
        if (!$jid) continue;
        if (!isset($jidMap[$jid])) {
            $jidMap[$jid] = ['pushName' => $r['pushName'] ?? null];
        }
    }

    foreach ($jidMap as $jid => $info) {
        $isGroup  = str_ends_with($jid, '@g.us') ? 1 : 0;
        $cname    = $isGroup
            ? ($groupMap[$jid]['name'] ?? $info['pushName'] ?? null)
            : ($contactMap[$jid]['name'] ?? $info['pushName'] ?? null);
        // Não armazena LIDs como nomes
        if ($cname && preg_match('/^\d{12,}$/', (string)$cname)) $cname = null;
        $phone    = $isGroup ? null : preg_replace('/[^0-9]/', '', explode('@', $jid)[0]);
        $pic      = $isGroup
            ? ($groupMap[$jid]['pic'] ?? null)
            : ($contactMap[$jid]['pic'] ?? null);

        $pdo->prepare(
            'INSERT INTO whatsapp_chats
             (instance_id, remote_jid, contact_name, phone, is_group, profile_pic_url, unread_count)
             VALUES (?,?,?,?,?,?,0)
             ON DUPLICATE KEY UPDATE
               contact_name    = IF(VALUES(contact_name) IS NOT NULL AND VALUES(contact_name) != "", VALUES(contact_name), contact_name),
               profile_pic_url = IF(VALUES(profile_pic_url) IS NOT NULL, VALUES(profile_pic_url), profile_pic_url)'
        )->execute([$instanceId, $jid, $cname, $phone, $isGroup, $pic]);
        $synced++;
    }

    // 4. Salvar todas as mensagens no banco
    // Cap de downloads de binário por execução pra não estourar o tempo do sync
    // (cada getBase64 é uma chamada HTTP à Evolution). Acima do cap, cai no thumbnail.
    $mediaFetched    = 0;
    $MEDIA_FETCH_CAP = 60;
    foreach ($allMessages as $r) {
        if (!is_array($r) || empty($r['key'])) continue;
        $key2   = $r['key'];
        $wamid  = $key2['id']              ?? null;
        $remJid = $key2['remoteJid']       ?? null;
        $fromMe = (bool)($key2['fromMe']   ?? false);
        $participantJid = $key2['participant'] ?? null;
        if (!$remJid) continue;

        $msgTypeRaw = $r['messageType'] ?? 'text';
        $msgObj     = $r['message']     ?? [];
        $ts         = $r['messageTimestamp'] ?? time();
        $push       = $r['pushName'] ?? null;
        // Em grupo, quando não veio nome legível, resolve pelo mapa de nomes
        // (contato/pushName) e, em último caso, telefone FORMATADO — nunca o
        // número cru. Assim o autor aparece com nome (ou telefone bonito), não "5511…".
        if ($participantJid && (!$push || preg_match('/^\d{6,}$/', (string)$push))) {
            $pd = preg_replace('/[^0-9]/', '', explode('@', (string)$participantJid)[0]);
            if ($pd && isset($nameByPhone[$pd])) {
                $push = $nameByPhone[$pd];
            } elseif ($pd && strlen($pd) >= 10) {
                $push = formatBrPhone($pd);
            }
        }

        $type = match ($msgTypeRaw) {
            'imageMessage'    => 'image',
            'videoMessage'    => 'video',
            'audioMessage'    => 'audio',
            'documentMessage' => 'document',
            'stickerMessage'  => 'sticker',
            default           => 'text',
        };
        $content  = $msgObj['conversation'] ?? ($msgObj['extendedTextMessage']['text'] ?? null);
        $mediaUrl = $msgObj[$msgTypeRaw]['url']      ?? null;
        $caption  = $msgObj[$msgTypeRaw]['caption']  ?? null;
        $fname    = $msgObj[$msgTypeRaw]['fileName']  ?? null;
        $mime     = $msgObj[$msgTypeRaw]['mimetype']  ?? null;

        $isMedia    = in_array($type, ['image','video','audio','document','sticker']);
        $rawPayload = $isMedia ? json_encode($r, JSON_UNESCAPED_UNICODE) : null;

        // Mídia: baixa o BINÁRIO REAL (pra mídia sincronizada também abrir/baixar).
        // Antes só guardava o jpegThumbnail (imagem minúscula) e nada pra áudio/vídeo/doc.
        $mediaBase64 = null;
        $mediaIsFull = 0;
        if ($isMedia) {
            // 1) downloadMedia=true às vezes já traz o base64 embutido no retorno.
            $embedded = $r['base64']
                     ?? ($msgObj['base64'] ?? null)
                     ?? ($msgObj[$msgTypeRaw]['base64'] ?? null);
            if (is_string($embedded) && strlen($embedded) > 256) {
                $mediaBase64 = str_contains($embedded, ',') ? explode(',', $embedded, 2)[1] : $embedded;
                $mediaIsFull = 1;
            }
            // 2) Senão, baixa via getBase64 (capado pra não estourar o tempo do sync).
            if (!$mediaBase64 && $mediaFetched < $MEDIA_FETCH_CAP) {
                try {
                    $b64 = $evo->getMediaBase64($name, $r);
                    if ($b64) {
                        $mediaBase64 = str_contains($b64, ',') ? explode(',', $b64, 2)[1] : $b64;
                        $mediaIsFull = 1;
                        $mediaFetched++;
                    }
                } catch (\Throwable $_) {}
            }
            // 3) Último recurso: thumbnail embarcado (não depende de CDN).
            if (!$mediaBase64) {
                $thumb = $msgObj[$msgTypeRaw]['jpegThumbnail'] ?? null;
                if ($thumb) {
                    $mediaBase64 = str_contains($thumb, ',') ? explode(',', $thumb, 2)[1] : $thumb;
                    if (!$mime) $mime = 'image/jpeg';
                }
            }
        }

        $msgModel->save([
            'instance_id'     => $instanceId,
            'wamid'           => $wamid,
            'remote_jid'      => $remJid,
            'participant_jid' => $participantJid,
            'contact_name'    => $push,
            'phone'           => preg_replace('/[^0-9]/', '', explode('@', $remJid)[0]),
            'message_type'    => $type,
            'message_content' => $content,
            'caption'         => $caption,
            'media_url'       => $mediaUrl,
            'media_mimetype'  => $mime,
            'media_filename'  => $fname,
            'media_base64'    => $mediaBase64,
            'media_is_full'   => $mediaIsFull,
            'direction'       => $fromMe ? 'outbound' : 'inbound',
            'status'          => $fromMe ? 'sent' : 'delivered',
            'created_at'      => date('Y-m-d H:i:s', (int)$ts),
            'raw_payload'     => $rawPayload,
        ]);
        $messages++;
    }

    // 5. Sincronizar participantes dos grupos (LID → telefone real)
    // Grava em DUAS tabelas:
    //   a) whatsapp_contacts   — facilita lookup LID/JID para nome em qualquer contexto
    //   b) whatsapp_group_members — vincula explicitamente ao grupo + role (admin/member)
    //
    // Bug fix 2026-05-25: antes só gravava em whatsapp_contacts e a tabela
    // whatsapp_group_members ficava vazia → modal de membros usava fallback
    // frágil pelos last messages do chat (sem role, sem completude).
    foreach (array_keys($groupMap) as $gJid) {
        $gInfo = $evo->fetchGroupInfo($name, $gJid);
        foreach ($gInfo['participants'] ?? [] as $p) {
            $pLid   = $p['id']          ?? null;
            $pPhone = $p['phoneNumber'] ?? null;
            if (!$pLid) continue;
            $phoneNum    = $pPhone ? preg_replace('/[^0-9]/', '', explode('@', $pPhone)[0]) : null;
            // Nome REAL quando conhecido (contato/pushName); telefone formatado só como fallback.
            $realName    = ($phoneNum && isset($nameByPhone[$phoneNum])) ? $nameByPhone[$phoneNum] : null;
            $displayName = $realName ?? ($phoneNum ? formatBrPhone($phoneNum) : null);

            // a) Mapa global de contatos (necessário pra resolver @menções de LID)
            $pdo->prepare(
                'INSERT INTO whatsapp_contacts (instance_id, remote_jid, push_name, phone)
                 VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                   phone     = IF(VALUES(phone) IS NOT NULL, VALUES(phone), phone),
                   push_name = IF(VALUES(push_name) IS NOT NULL, VALUES(push_name), push_name)'
            )->execute([$instanceId, $pLid, $displayName, $phoneNum]);
            $contacts++;

            // b) Vínculo grupo → membro + role
            //    Evolution retorna p.admin = 'admin' | 'superadmin' | null (member)
            $role = match ($p['admin'] ?? null) {
                'superadmin' => 'superadmin',
                'admin'      => 'admin',
                default      => 'member',
            };
            $pdo->prepare(
                'INSERT INTO whatsapp_group_members
                   (account_id, instance_id, group_jid, participant_jid, push_name, phone, role)
                 VALUES (?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                   push_name = IF(VALUES(push_name) IS NOT NULL, VALUES(push_name), push_name),
                   phone     = IF(VALUES(phone)     IS NOT NULL, VALUES(phone),     phone),
                   role      = VALUES(role)'
            )->execute([$accountId, $instanceId, $gJid, $pLid, $displayName, $phoneNum, $role]);
        }
    }

    echo json_encode([
        'ok'       => true,
        'synced'   => $synced,
        'messages' => $messages,
        'contacts' => $contacts,
    ]);

} catch (Throwable $e) {
    require_once __DIR__ . '/../../../app/Helpers/ErrorReporter.php';
    \App\Helpers\ErrorReporter::handle($e);  // P1 LGPD (2D.1)
}

function formatBrPhone(string $d): string {
    $l = strlen($d);
    if ($l === 13) return '+' . substr($d,0,2) . ' (' . substr($d,2,2) . ') ' . substr($d,4,5) . '-' . substr($d,9);
    if ($l === 12) return '+' . substr($d,0,2) . ' (' . substr($d,2,2) . ') ' . substr($d,4,4) . '-' . substr($d,8);
    if ($l === 11) return '(' . substr($d,0,2) . ') ' . substr($d,2,5) . '-' . substr($d,7);
    return '+' . $d;
}
