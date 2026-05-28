<?php
/**
 * /api/master/retention.php — gestão de políticas de retenção LGPD (Etapa 7).
 *
 * GET                → lista todas as policies + log de anonimizações recentes
 * PATCH body: {id, retencao_dias?, ativo?, base_legal?}
 * POST  ?action=run  → executa cron manualmente (mesmo do tick, mas via UI)
 * GET   ?logs=1      → últimas 50 anonimizações
 *
 * Acesso: super_admin com master_mode.
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/Account.php';
require_once __DIR__ . '/../../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Helpers/ApiResponse.php';
require_once __DIR__ . '/../../../app/Helpers/MasterAudit.php';

use App\Helpers\AccountContext;
use App\Helpers\ApiResponse;
use App\Helpers\MasterAudit;
use App\Models\Database;

session_start();
$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();
if (empty($_SESSION['master_mode'])) {
    ApiResponse::forbidden('Acesso somente pelo Painel Master.');
}

$pdo    = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$userId = $ctx->getUserId();

// ─── GET ────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    if (!empty($_GET['logs'])) {
        $st = $pdo->query(
            'SELECT al.*, u.nome AS executor_nome
             FROM anonymization_log al
             LEFT JOIN users u ON u.id = al.executado_por_user_id
             ORDER BY al.executado_em DESC LIMIT 50'
        );
        ApiResponse::ok($st->fetchAll(\PDO::FETCH_ASSOC));
    }

    $policies = $pdo->query(
        'SELECT * FROM retention_policies ORDER BY entidade'
    )->fetchAll(\PDO::FETCH_ASSOC);

    // Conta total de anonimizações executadas
    $countAnon = (int)$pdo->query('SELECT COUNT(*) FROM anonymization_log')->fetchColumn();

    ApiResponse::ok([
        'policies'           => $policies,
        'total_anonimizacoes' => $countAnon,
    ]);
}

// ─── CSRF para mutações ────────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (in_array($method, ['POST', 'PATCH', 'DELETE'])) {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        ApiResponse::badRequest('CSRF inválido');
    }
}

// ─── POST ?action=run — dispara cron via UI ────────────────────────────────
if ($method === 'POST' && ($_GET['action'] ?? null) === 'run') {
    $dry = !empty($input['dry_run']);
    // Reusa a mesma lógica do cron — chamada interna com $cronToken bypass
    // (já validamos super_admin acima, então é seguro).
    $tokenPath = '/api/lgpd_retention_tick.php';
    require_once __DIR__ . '/../../../app/Helpers/EnvLoader.php';
    \App\Helpers\EnvLoader::load();
    $tok = \App\Helpers\EnvLoader::get('CRON_TOKEN', '');

    $url = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
         . '' . $tokenPath
         . '?token=' . urlencode($tok)
         . ($dry ? '&dry_run=1' : '')
         . '&force=1';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($raw, true);
    if (!$data) {
        MasterAudit::log('lgpd_retention.run_failed', 'retention_policies', 0,
            'Falha ao executar cron manual', ['http' => $http]);
        ApiResponse::serverError('Falha ao executar (HTTP ' . $http . ')');
    }

    MasterAudit::log('lgpd_retention.run', 'retention_policies', 0,
        'Cron de retenção executado manualmente' . ($dry ? ' (dry run)' : ''),
        ['log' => $data['log'] ?? []]);

    ApiResponse::ok($data);
}

// ─── PATCH — edita policy ──────────────────────────────────────────────────
if ($method === 'PATCH') {
    $id = (int)($input['id'] ?? 0);
    if (!$id) ApiResponse::badRequest('id obrigatório');

    $fields = [];
    $params = ['id' => $id];
    if (isset($input['retencao_dias'])) {
        $d = (int)$input['retencao_dias'];
        if ($d < 1 || $d > 36500) ApiResponse::badRequest('retencao_dias deve estar entre 1 e 36500');
        $fields[] = 'retencao_dias = :d'; $params['d'] = $d;
    }
    if (isset($input['ativo'])) {
        $fields[] = 'ativo = :a'; $params['a'] = (int)(bool)$input['ativo'];
    }
    if (isset($input['base_legal'])) {
        $fields[] = 'base_legal = :bl'; $params['bl'] = trim((string)$input['base_legal']);
    }
    if (empty($fields)) ApiResponse::badRequest('Nada para atualizar');

    $sql = 'UPDATE retention_policies SET ' . implode(', ', $fields) . ' WHERE id = :id';
    $pdo->prepare($sql)->execute($params);

    MasterAudit::log('lgpd_retention.policy_updated', 'retention_policies', $id,
        'Política de retenção atualizada', ['changes' => array_keys($params)]);
    ApiResponse::ok(['updated' => true]);
}

ApiResponse::methodNotAllowed();
