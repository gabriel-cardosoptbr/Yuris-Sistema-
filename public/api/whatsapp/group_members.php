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

    $instModel = new WhatsAppInstance();
    $instance  = $instModel->getByAccountId($accountId);
    if (!$instance) {
        echo json_encode(['ok' => true, 'members' => [], 'count' => 0]);
        exit;
    }
    $instanceId = (int)$instance['id'];

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
