<?php
ob_start();
@ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../app/Models/Database.php';
require_once __DIR__ . '/../app/Models/WhatsAppInstance.php';
require_once __DIR__ . '/../app/Models/WhatsAppMessage.php';

session_start(['read_and_close' => true]);
ob_end_clean();

if (empty($_SESSION['user_id'])) {
    echo '<h2 style="color:red">Não logado! Faça login primeiro.</h2>';
    exit;
}

use App\Models\Database;

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Debug Chat</title>
<style>body{font-family:monospace;background:#111;color:#eee;padding:20px}
h2{color:#7EB8F7;margin-top:20px}pre{background:#1a1a2e;padding:10px;border-radius:6px;overflow:auto;font-size:12px}
.ok{color:#68D391}.err{color:#FC8181}.warn{color:#F6AD55}</style></head><body>';

echo '<h1>Debug — Chat WhatsApp</h1>';

try {
    $pdo = Database::getConnection();
    echo '<p class="ok">✓ Conexão DB OK</p>';

    $instModel = new WhatsAppInstance();
    $msgModel  = new WhatsAppMessage();

    // Settings
    $cfg = $instModel->getSettings();
    echo '<h2>Configurações (whatsapp_settings)</h2><pre>' . htmlspecialchars(print_r($cfg, true)) . '</pre>';

    $instName   = $cfg['evolution_instance'] ?? 'yuris-crm';
    $row        = $instModel->findOrCreate($instName);
    $instanceId = (int)$row['id'];
    echo '<h2>Instância (instance_id = ' . $instanceId . ')</h2><pre>' . htmlspecialchars(print_r($row, true)) . '</pre>';

    // Chats
    $chats = $msgModel->getChatList($instanceId, '');
    echo '<h2>Chats na tabela whatsapp_chats (' . count($chats) . ' registros)</h2><pre>' . htmlspecialchars(print_r($chats, true)) . '</pre>';

    // Mensagens por JID
    $stmt = $pdo->query('SELECT remote_jid, COUNT(*) as total, MAX(created_at) as ultima FROM whatsapp_messages GROUP BY remote_jid ORDER BY ultima DESC');
    $byJid = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo '<h2>Mensagens por conversa</h2><pre>' . htmlspecialchars(print_r($byJid, true)) . '</pre>';

    // Últimas 5 mensagens de cada JID
    if ($byJid) {
        foreach ($byJid as $j) {
            $jid = $j['remote_jid'];
            $msgs = $msgModel->findByJid($instanceId, $jid, 3, 0);
            echo '<h2>Últimas mensagens: ' . htmlspecialchars($jid) . '</h2>';
            echo '<pre>' . htmlspecialchars(print_r($msgs, true)) . '</pre>';
        }
    }

    // Total geral
    $stmt = $pdo->query('SELECT COUNT(*) FROM whatsapp_messages');
    echo '<h2>Total mensagens no banco: ' . $stmt->fetchColumn() . '</h2>';

    // Contatos
    $stmt = $pdo->query('SELECT COUNT(*) FROM whatsapp_contacts');
    echo '<h2>Total contatos: ' . $stmt->fetchColumn() . '</h2>';

    $stmt = $pdo->query('SELECT remote_jid, push_name, phone FROM whatsapp_contacts LIMIT 30');
    $conts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo '<h2>Contatos (primeiros 30)</h2><pre>' . htmlspecialchars(print_r($conts, true)) . '</pre>';

    // Teste direto via API
    require_once __DIR__ . '/../app/Services/EvolutionApiService.php';
    $evo = new EvolutionApiService($cfg);
    $instName = $cfg['evolution_instance'] ?? 'yuris-crm';

    // Número conectado ao CRM (via fetchInstances)
    $instances = $evo->fetchInstances();
    foreach ((array)$instances as $inst) {
        $iName = $inst['instance']['instanceName'] ?? ($inst['instanceName'] ?? null);
        if ($iName === $instName) {
            echo '<h2 class="ok">✓ Instância encontrada</h2>';
            echo '<pre>' . htmlspecialchars(print_r($inst, true)) . '</pre>';
            break;
        }
    }

    // Teste: findMessages global (últimos 30 min, sem JID)
    $since      = time() - 1800;
    $globalMsgs = $evo->request('POST', "/chat/findMessages/{$instName}", [
        'where' => ['messageTimestamp' => ['gte' => $since]],
        'limit' => 10,
        'page'  => 1,
    ]);
    echo '<h2>findMessages global (últimos 30min) — http=' . ($globalMsgs['_http'] ?? '?') . '</h2>';
    $sample = array_slice((array)$globalMsgs, 0, 4);
    echo '<pre>' . htmlspecialchars(print_r($sample, true)) . '</pre>';

    // Teste: findChats bruto
    $rawChats = $evo->findChats($instName);
    echo '<h2>findChats bruto — total=' . (is_array($rawChats) ? count($rawChats) : 'N/A') . '</h2>';
    echo '<pre>' . htmlspecialchars(print_r(array_slice((array)$rawChats, 0, 3), true)) . '</pre>';

    // Teste: findContacts via API
    $rawContacts = $evo->findContacts($instName);
    echo '<h2>findContacts (primeiros 5 registros brutos)</h2><pre>' . htmlspecialchars(print_r(array_slice((array)$rawContacts, 0, 5), true)) . '</pre>';

    // Teste: groupInfo para o grupo Team Inovaize
    if ($byJid) {
        foreach ($byJid as $j) {
            if (str_ends_with($j['remote_jid'], '@g.us')) {
                $gInfo = $evo->fetchGroupInfo($instName, $j['remote_jid']);
                echo '<h2>GroupInfo: ' . htmlspecialchars($j['remote_jid']) . '</h2><pre>' . htmlspecialchars(print_r($gInfo, true)) . '</pre>';
                break;
            }
        }
    }

} catch (Throwable $e) {
    echo '<h2 class="err">ERRO: ' . htmlspecialchars($e->getMessage()) . '</h2>';
    echo '<pre class="err">' . htmlspecialchars($e->getFile() . ':' . $e->getLine()) . "\n" . htmlspecialchars($e->getTraceAsString()) . '</pre>';
}

echo '</body></html>';
