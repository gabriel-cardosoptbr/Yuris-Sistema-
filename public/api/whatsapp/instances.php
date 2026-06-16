<?php
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/WhatsAppInstance.php';
require_once __DIR__ . '/../../../app/Models/Account.php';
require_once __DIR__ . '/../../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Services/EvolutionApiService.php';

use App\Helpers\AccountContext;

session_start(['read_and_close' => true]);
$_uid  = $_SESSION['user_id']    ?? null;
$_csrf = $_SESSION['csrf_token'] ?? '';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store'); // resposta carrega estado de conexão do tenant
if (!$_uid) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

$ctx        = AccountContext::fromSession();
$accountId  = $ctx->getAccountId();
// Instâncias de WhatsApp = CONFIGURAÇÃO da conta — cada tenant tem suas próprias conexões.
$tenantIds  = [$accountId];

// ─── Segurança (hotfix): gestão de canal é só owner/admin ─────────────────────
// Antes desta correção, QUALQUER usuário logado podia ler a config crua: o GET
// default devolvia 'settings' com evolution_api_key EM CLARO, e era possível
// disparar create/connect/restart/logout/set_webhook. Agora: a leitura de STATUS
// continua liberada (banner de conexão, sem segredos), mas QR, listagem, GET
// default e todas as mutações exigem owner/admin. A chave nunca é devolvida e
// nenhuma resposta carrega o 'raw' técnico da Evolution. (espelha config.php)
$canManage  = $ctx->isOwnerOrAdmin();
$denyManage = static function () {
    http_response_code(403);
    echo json_encode(['error' => 'Apenas owner/admin pode gerenciar a conexão WhatsApp']);
    exit;
};
// ──────────────────────────────────────────────────────────────────────────────

$model  = new WhatsAppInstance();
$cfg    = $model->getSettings($accountId);
$evo    = new EvolutionApiService($cfg);
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── GET ────────────────────────────────────────────────────────────────────
if ($method === 'GET') {

    if ($action === 'status') {
        $name = $cfg['evolution_instance'] ?? 'yuris-crm';
        $row  = $model->findOrCreate($name, '', $accountId);

        // Consulta estado real na Evolution API
        $state = $evo->getConnectionState($name);
        $status = 'close';
        if (!empty($state['instance']['state'])) {
            $status = strtolower($state['instance']['state']);
        } elseif (!empty($state['state'])) {
            $status = strtolower($state['state']);
        }

        // Sincroniza status no DB — tenta vários campos possíveis para o número
        $extra = [];
        if (!empty($state['instance']['profileName'])) $extra['profile_name'] = $state['instance']['profileName'];

        // wuid, ownerJid e number são os campos usados por diferentes versões da Evolution API
        $phoneRaw = $state['instance']['wuid']
                 ?? $state['instance']['ownerJid']
                 ?? $state['instance']['number']
                 ?? null;

        // Se não veio no connectionState, busca via fetchInstances
        if (!$phoneRaw) {
            $instances = $evo->fetchInstances();
            foreach ((array)$instances as $inst) {
                $iName = $inst['name'] ?? ($inst['instance']['instanceName'] ?? null);
                if ($iName === $name) {
                    $phoneRaw = $inst['ownerJid']
                             ?? $inst['instance']['ownerJid']
                             ?? $inst['wuid']
                             ?? $inst['instance']['wuid']
                             ?? null;
                    if (!$phoneRaw && !empty($inst['instance']['profileName'])) {
                        $extra['profile_name'] = $extra['profile_name'] ?? $inst['instance']['profileName'];
                    }
                    break;
                }
            }
        }

        if ($phoneRaw) {
            // Normaliza: remove sufixo @s.whatsapp.net e caracteres não-numéricos
            $extra['phone'] = preg_replace('/[^0-9+]/', '', explode('@', $phoneRaw)[0]);
        }

        $model->updateStatus($row['id'], $status, $extra);

        // Remove campos grandes/sensíveis da resposta de status.
        unset($row['qr_code_base64'], $row['evolution_token'], $row['webhook_url']);
        echo json_encode([
            'ok'       => true,
            'status'   => $status,
            'instance' => array_merge($row, $extra, ['status' => $status]),
        ]);
        exit;
    }

    // Daqui pra baixo (qr / list / GET default) é gestão/leitura de config: exige owner/admin.
    if (!$canManage) { $denyManage(); }

    if ($action === 'qr') {
        $name = $cfg['evolution_instance'] ?? 'yuris-crm';
        $row  = $model->findOrCreate($name, '', $accountId);
        $res  = $evo->connectInstance($name);

        $qr = $res['base64'] ?? ($res['qrcode']['base64'] ?? ($res['code'] ?? ''));
        if ($qr) {
            // Garante prefixo data URI
            if (!str_starts_with($qr, 'data:')) {
                $qr = 'data:image/png;base64,' . $qr;
            }
            $model->updateQrCode($row['id'], $qr);
        }

        echo json_encode(['ok' => true, 'qr' => $qr]);
        exit;
    }

    if ($action === 'list') {
        echo json_encode(['ok' => true, 'instances' => $model->listAll($tenantIds)]);
        exit;
    }

    // Default: retorna instância padrão com estado atual (NUNCA credenciais).
    $name = $cfg['evolution_instance'] ?? 'yuris-crm';
    $row  = $model->findOrCreate($name, '', $accountId);
    unset($row['evolution_token'], $row['webhook_url'], $row['qr_code_base64']);
    echo json_encode(['ok' => true, 'instance' => $row]);
    exit;
}

// ── POST ───────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true) ?? [];
    if (empty($payload['_csrf']) || $payload['_csrf'] !== $_csrf) {
        http_response_code(403); echo json_encode(['error' => 'CSRF inválido']); exit;
    }

    $action = $payload['action'] ?? '';

    if ($action === 'create') {
        $name       = preg_replace('/[^a-zA-Z0-9_-]/', '', $payload['name'] ?? $cfg['evolution_instance']);
        $webhookUrl = $payload['webhook_url'] ?? '';
        $res        = $evo->createInstance($name, $webhookUrl);

        if (!empty($res['_error'])) {
            echo json_encode(['ok' => false, 'error' => $res['_error']]); exit;
        }

        $row = $model->findOrCreate($name, $payload['display_name'] ?? '', $accountId);

        // Salva QR code se retornado na criação
        $qr = $res['qrcode']['base64'] ?? ($res['base64'] ?? '');
        if ($qr) {
            if (!str_starts_with($qr, 'data:')) $qr = 'data:image/png;base64,' . $qr;
            $model->updateQrCode($row['id'], $qr);
        }

        echo json_encode(['ok' => true, 'instance' => $model->find((int)$row['id'], $tenantIds), 'qr' => $qr]);
        exit;
    }

    if ($action === 'connect') {
        $name = $cfg['evolution_instance'] ?? 'yuris-crm';
        $row  = $model->findOrCreate($name, '', $accountId);
        $res  = $evo->connectInstance($name);

        $qr = $res['base64'] ?? ($res['qrcode']['base64'] ?? '');
        if (!$qr && !empty($res['code'])) $qr = $res['code'];
        if ($qr && !str_starts_with($qr, 'data:')) $qr = 'data:image/png;base64,' . $qr;
        if ($qr) $model->updateQrCode($row['id'], $qr);

        echo json_encode(['ok' => true, 'qr' => $qr]);
        exit;
    }

    if ($action === 'restart') {
        $name = $cfg['evolution_instance'] ?? 'yuris-crm';
        $res  = $evo->restartInstance($name);
        // Verifica se voltou com QR (sessão expirou)
        $qr = $res['base64'] ?? ($res['qrcode']['base64'] ?? '');
        if ($qr && !str_starts_with($qr, 'data:')) $qr = 'data:image/png;base64,' . $qr;
        echo json_encode(['ok' => true, 'qr' => $qr ?: null]);
        exit;
    }

    if ($action === 'logout') {
        $name = $cfg['evolution_instance'] ?? 'yuris-crm';
        $row  = $model->findByName($name, $tenantIds);
        if ($row) {
            $evo->logoutInstance($name);
            $model->updateStatus($row['id'], 'close');
            $model->clearQrCode($row['id']);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'set_webhook') {
        $name = $cfg['evolution_instance'] ?? 'yuris-crm';
        $url  = trim($payload['url'] ?? '');
        if (!$url) { echo json_encode(['ok' => false, 'error' => 'URL obrigatória']); exit; }

        // Anexa ?token=<apikey do tenant> à URL enviada à Evolution. Garante que o
        // webhook.php identifique o tenant mesmo quando a Evolution não repassa
        // headers customizados (a query string SEMPRE é enviada). Não duplica se o
        // usuário já tiver colocado token. A URL "limpa" (sem token) é a que fica
        // salva/exibida na tela — o token é detalhe interno da integração.
        $tenantKey = (string)($cfg['evolution_api_key'] ?? '');
        $urlFinal  = $url;
        if ($tenantKey !== '' && !preg_match('/[?&]token=/i', $url)) {
            $urlFinal .= (strpos($url, '?') === false ? '?' : '&') . 'token=' . urlencode($tenantKey);
        }

        $res = $evo->setWebhook($name, $urlFinal);
        $model->saveSetting($accountId, 'webhook_url', $url);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Ação desconhecida']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
