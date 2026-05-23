<?php
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/Account.php';
require_once __DIR__ . '/../../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../../app/Models/WhatsAppInstance.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';

use App\Helpers\AccountContext;

session_start(['read_and_close' => true]);
$_uid  = $_SESSION['user_id']    ?? null;
$_csrf = $_SESSION['csrf_token'] ?? '';
header('Content-Type: application/json; charset=utf-8');
if (!$_uid) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

// ─── LGPD P0 (1.8): restringe acesso à configuração WhatsApp ──────────────────
// Antes desta correção, qualquer usuário logado podia LER e ESCREVER a chave
// global da Evolution API (evolution_api_key), o que permitia:
//   • Exfiltrar a chave e usar fora do sistema
//   • Redirecionar o webhook_url da Evolution para servidor controlado
//   • Capturar todas as mensagens recebidas
// Agora exigimos role admin/owner E escopo per-tenant via AccountContext.
$ctx       = AccountContext::fromSession();
$accountId = $ctx->getAccountId();
if (!$ctx->isOwnerOrAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Apenas owner/admin pode acessar a configuração do WhatsApp']);
    exit;
}
// ─────────────────────────────────────────────────────────────────────────────

$model  = new WhatsAppInstance();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Ofusca evolution_api_key na resposta — UI mostra "configurada" sem revelar
    $settings = $model->getSettings($accountId);
    if (!empty($settings['evolution_api_key'])) {
        $key = $settings['evolution_api_key'];
        $settings['evolution_api_key_masked'] = strlen($key) > 8
            ? str_repeat('*', strlen($key) - 4) . substr($key, -4)
            : '****';
        $settings['evolution_api_key'] = ''; // não devolve em claro
    }
    echo json_encode(['ok' => true, 'settings' => $settings]);
    exit;
}

if ($method === 'POST') {
    $csrf    = $_csrf;
    $payload = json_decode(file_get_contents('php://input'), true) ?? [];
    if (empty($payload['_csrf']) || $payload['_csrf'] !== $csrf) {
        http_response_code(403); echo json_encode(['error' => 'CSRF inválido']); exit;
    }

    $allowed = ['evolution_base_url', 'evolution_api_key', 'evolution_instance', 'webhook_enabled', 'webhook_url'];
    $touched = [];
    foreach ($allowed as $key) {
        if (isset($payload[$key])) {
            // não permite gravar string vazia em evolution_api_key (evita zerar inadvertidamente)
            if ($key === 'evolution_api_key' && trim((string)$payload[$key]) === '') continue;
            $model->saveSetting($accountId, $key, (string)$payload[$key]);
            // LGPD: nunca registramos evolution_api_key em claro na trilha — só marcamos que foi alterada.
            $touched[$key] = ($key === 'evolution_api_key') ? '***changed***' : (string)$payload[$key];
        }
    }

    // LGPD Etapa 4: registra alteração da config WhatsApp do tenant.
    \App\Models\Account::audit($accountId, 'whatsapp_settings.updated', [
        'user_id'     => (int)$_uid,
        'entidade'    => 'whatsapp_settings',
        'entidade_id' => null,
        'detalhes'    => $touched,
    ]);

    // Resposta: idem GET (oculta api_key)
    $settings = $model->getSettings($accountId);
    if (!empty($settings['evolution_api_key'])) {
        $key = $settings['evolution_api_key'];
        $settings['evolution_api_key_masked'] = strlen($key) > 8
            ? str_repeat('*', strlen($key) - 4) . substr($key, -4)
            : '****';
        $settings['evolution_api_key'] = '';
    }
    echo json_encode(['ok' => true, 'settings' => $settings]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
