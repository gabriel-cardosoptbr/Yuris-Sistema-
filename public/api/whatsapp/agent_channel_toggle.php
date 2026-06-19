<?php
/**
 * GET/POST /api/whatsapp/agent_channel_toggle.php
 *
 * Liga/desliga o agente de IA NO CANAL INTEIRO (global), alternando
 * agent_configs.enabled da instancia. Atalho do header do Chat WhatsApp; o lugar
 * completo de config continua sendo agente.php.
 *
 * Nivel: owner/admin (e config do canal, nao acao de atendimento). NAO cria config:
 * exige que o agente ja tenha sido configurado em agente.php. Para LIGAR, o canal
 * precisa estar conectado (whatsapp_instances.status='open'), mesmo guard do agent_settings.
 *
 * Convive com agent_takeover.php (pausa POR conversa, agent_paused). No webhook o bot
 * responde se: enabled=1 (ESTE toggle) E status='open' E agent_paused=0 (conversa).
 * Logo: desligar aqui = desliga geral; ligar aqui + "Assumir" numa conversa = geral
 * ligado, mas aquela conversa fica com o humano.
 *
 *   GET  ?instance_id=ID                       -> { ok, instance_id, has_agent, enabled, connected, channel_status, agent_name }
 *   POST { instance_id, enabled:0|1, _csrf }   -> idem (estado novo) + saved:true
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';

use App\Helpers\AccountContext;
use App\Models\Database;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$uid  = $_SESSION['user_id']    ?? null;
$csrf = $_SESSION['csrf_token'] ?? '';
if (!$uid) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

$ctx = AccountContext::fromSession();
if (!$ctx->isOwnerOrAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Apenas owner/admin pode ligar ou desligar o agente do canal']);
    exit;
}

// Escopo de contas acessiveis (matriz ve filiais; filial ve propria + matriz).
$scope = $ctx->getAccessibleAccountIds();
$mid   = $ctx->getPipelineAccountId();
if ($mid > 0 && !in_array($mid, $scope, true)) $scope[] = $mid;
$scope = array_values(array_unique(array_filter(array_map('intval', $scope), fn($v) => $v > 0)));
if (empty($scope)) { http_response_code(403); echo json_encode(['error' => 'Sem acesso']); exit; }

$pdo = Database::getConnection();
$ph = []; $params = [];
foreach ($scope as $i => $aid) { $k = "a{$i}"; $ph[] = ":{$k}"; $params[$k] = $aid; }
$inAcc = implode(',', $ph);

$resolveInstance = function (int $instId) use ($pdo, $inAcc, $params): ?array {
    if ($instId <= 0) return null;
    $st = $pdo->prepare("SELECT i.id, i.account_id, i.status,
                                COALESCE(NULLIF(i.display_name,''), i.instance_name) AS channel_name
                           FROM whatsapp_instances i
                          WHERE i.id = :iid AND i.account_id IN ($inAcc) LIMIT 1");
    $st->execute($params + ['iid' => $instId]);
    return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
};

$agentState = function (array $inst) use ($pdo): array {
    $st = $pdo->prepare("SELECT id, enabled, name FROM agent_configs WHERE whatsapp_instance_id = ? LIMIT 1");
    $st->execute([(int)$inst['id']]);
    $cfg = $st->fetch(\PDO::FETCH_ASSOC);
    return [
        'ok'             => true,
        'instance_id'    => (int)$inst['id'],
        'has_agent'      => $cfg ? true : false,
        'enabled'        => $cfg ? (bool)$cfg['enabled'] : false,
        'agent_name'     => $cfg['name'] ?? null,
        'channel_status' => $inst['status'] ?: 'close',
        'connected'      => ($inst['status'] === 'open'),
    ];
};

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $inst = $resolveInstance((int)($_GET['instance_id'] ?? 0));
        if (!$inst) { http_response_code(404); echo json_encode(['error' => 'Canal não encontrado no seu acesso']); exit; }
        echo json_encode($agentState($inst));
        exit;
    }

    if ($method === 'POST') {
        $in  = json_decode(file_get_contents('php://input'), true) ?? [];
        $tok = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['_csrf'] ?? ($in['csrf_token'] ?? null));
        if (!$tok || !hash_equals((string)$csrf, (string)$tok)) {
            http_response_code(400); echo json_encode(['error' => 'CSRF inválido']); exit;
        }

        $inst = $resolveInstance((int)($in['instance_id'] ?? 0));
        if (!$inst) { http_response_code(404); echo json_encode(['error' => 'Canal não encontrado no seu acesso']); exit; }

        $enabled = !empty($in['enabled']) ? 1 : 0;

        $chk = $pdo->prepare("SELECT id FROM agent_configs WHERE whatsapp_instance_id = ? LIMIT 1");
        $chk->execute([(int)$inst['id']]);
        if (!$chk->fetchColumn()) {
            http_response_code(400);
            echo json_encode(['error' => 'Configure o agente neste canal antes, na tela Agente de IA.', 'need_setup' => true]);
            exit;
        }
        if ($enabled === 1 && ($inst['status'] ?? 'close') !== 'open') {
            http_response_code(409);
            echo json_encode(['error' => 'Conecte o WhatsApp antes de ligar o agente.']);
            exit;
        }

        $upd = $pdo->prepare("UPDATE agent_configs
                                 SET enabled = :en, status = :st, updated_by = :ub
                               WHERE whatsapp_instance_id = :iid");
        $upd->execute([
            'en' => $enabled, 'st' => $enabled ? 'active' : 'inactive',
            'ub' => (int)$uid, 'iid' => (int)$inst['id'],
        ]);

        echo json_encode($agentState($inst) + ['saved' => true]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (\Throwable $e) {
    error_log('[agent_channel_toggle] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao alterar o agente do canal']);
}
