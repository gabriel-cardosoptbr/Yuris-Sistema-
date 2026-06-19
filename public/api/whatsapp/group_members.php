<?php
/**
 * api/whatsapp/group_members.php
 * ─────────────────────────────────────────────────────────────────────────────
 * P1-I (auditoria 2026-05-24): retorna a lista de membros de um grupo,
 * persistida pela última `sync.php` (que chama fetchGroupInfo). UI usa
 * isso pra mostrar "Grupo X (12 membros)" no header + lista de participantes.
 *
 * GET ?jid=120363025@g.us
 *   → { ok:true, group_jid, count, members:[{participant_jid,push_name,phone,role,added_at}] }
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/Account.php';
require_once __DIR__ . '/../../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../../app/Models/WhatsAppInstance.php';
require_once __DIR__ . '/../../../app/Models/WhatsAppMessage.php';
require_once __DIR__ . '/../../../app/Services/WhatsAppChannelAccessService.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Helpers/ErrorReporter.php';

use App\Helpers\AccountContext;

session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

try {
    $ctx       = AccountContext::fromSession();
    $accountId = $ctx->getAccountId();
    $pdo       = \App\Models\Database::getConnection();

    // Membros do grupo = 'view' no canal (deny-by-default). instance_id resolvido
    // no backend (canal próprio ou compartilhado, com a flag ligada).
    $ch         = WhatsAppChannelAccessService::resolveForRequest($pdo, $accountId, ($_GET['channel_id'] ?? null), 'view');
    $instanceId = (int)$ch['channel_id'];

    $jid = trim($_GET['jid'] ?? '');
    if ($jid === '' || !str_ends_with($jid, '@g.us')) {
        http_response_code(400);
        echo json_encode(['error' => 'jid de grupo obrigatório (deve terminar com @g.us)']);
        exit;
    }

    $model   = new WhatsAppMessage();
    $members = $model->getGroupMembers($instanceId, $jid);

    // ── Contacts da instancia (pra resolver @mencoes via LID/@s.whatsapp.net) ──
    // O JS usa esse mapping pra trocar '@219507255689296' (LID interno) pelo
    // push_name. Sem isso, mencoes ficavam sem nome resolvido.
    $pdo = \App\Models\Database::getConnection();
    $st = $pdo->prepare(
        "SELECT remote_jid, push_name, phone
           FROM whatsapp_contacts
          WHERE instance_id = ?
            AND push_name IS NOT NULL AND push_name <> ''
          LIMIT 5000"
    );
    $st->execute([$instanceId]);
    $contactsRaw = $st->fetchAll(\PDO::FETCH_ASSOC);
    // Mapa { 'jidLocalPart': { name, phone } } pra lookup O(1) no JS
    $contactsMap = [];
    foreach ($contactsRaw as $c) {
        $local = explode('@', (string)$c['remote_jid'])[0];
        if ($local === '') continue;
        $contactsMap[$local] = [
            'name'  => $c['push_name'],
            'phone' => $c['phone'] ?? '',
        ];
    }

    // Enriquece o mapa com o NOME REAL tirado do pushName das mensagens do grupo.
    // Resolve @menções tipo @191748965933068 (LID) que ficavam sem nome porque
    // contacts/group_members só tinham telefone. A chave é o local-part do LID.
    $stMsg = $pdo->prepare(
        "SELECT participant_jid,
                MAX(CASE WHEN contact_name REGEXP '[A-Za-z]'
                         AND contact_name NOT REGEXP '^[+0-9 ()-]+$'
                    THEN contact_name END) AS real_name
           FROM whatsapp_messages
          WHERE instance_id = ? AND remote_jid = ?
            AND participant_jid IS NOT NULL
          GROUP BY participant_jid"
    );
    $stMsg->execute([$instanceId, $jid]);
    foreach ($stMsg->fetchAll(\PDO::FETCH_ASSOC) as $mm) {
        if (empty($mm['real_name'])) continue;
        $local = explode('@', (string)$mm['participant_jid'])[0];
        if ($local === '') continue;
        // nome real das mensagens tem prioridade sobre telefone do contato
        $contactsMap[$local] = [
            'name'  => $mm['real_name'],
            'phone' => $contactsMap[$local]['phone'] ?? '',
        ];
    }

    echo json_encode([
        'ok'           => true,
        'group_jid'    => $jid,
        'count'        => count($members),
        'members'      => $members,
        'contacts_map' => $contactsMap,
    ]);

} catch (Throwable $e) {
    \App\Helpers\ErrorReporter::handle($e);
}
