<?php
/**
 * /api/lgpd/request.php
 *
 * PÚBLICO (sem auth):
 *   POST      → cria solicitação. Rate-limit por IP+email.
 *   GET ?token=X → titular acompanha status (sem login).
 *
 * Endpoint admin (super_admin) fica em /api/master/lgpd_requests.php.
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/LgpdRequest.php';
require_once __DIR__ . '/../../../app/Helpers/ApiResponse.php';
require_once __DIR__ . '/../../../app/Helpers/EnvLoader.php';
require_once __DIR__ . '/../../../app/Services/Mailer.php';

use App\Models\LgpdRequest;
use App\Models\Database;
use App\Helpers\ApiResponse;
use App\Helpers\EnvLoader;

session_start();
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];

// ─── GET ?token=X — acompanhar status ───────────────────────────────────────
if ($method === 'GET') {
    $token = trim((string)($_GET['token'] ?? ''));
    if ($token === '' || strlen($token) !== 64) {
        ApiResponse::badRequest('Token inválido');
    }
    $req = LgpdRequest::findByToken($token);
    if (!$req) ApiResponse::notFound('Solicitação não encontrada');

    // Lista eventos (sem dados sensíveis do operador — só evento, observação, timestamp)
    $events = array_map(fn($e) => [
        'evento'     => $e['evento'],
        'observacao' => $e['observacao'],
        'created_at' => $e['created_at'],
    ], LgpdRequest::listEvents((int)$req['id']));

    ApiResponse::ok(['request' => $req, 'eventos' => $events]);
}

// ─── POST — criar nova solicitação ──────────────────────────────────────────
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    // ── Rate-limit anti-spam (3 solicitações por email+IP em 24h) ──────────
    $ip    = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    $email = strtolower(trim((string)($input['titular_email'] ?? '')));
    if ($email !== '') {
        try {
            $pdo = Database::getConnection();
            $st = $pdo->prepare(
                "SELECT COUNT(*) FROM login_attempts
                 WHERE ip = :ip AND login = :rl
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 86400 SECOND)"
            );
            $st->execute(['ip' => $ip, 'rl' => '[LGPD]' . $email]);
            if ((int)$st->fetchColumn() >= 3) {
                ApiResponse::error('Limite de 3 solicitações por dia atingido.', 429);
            }
        } catch (\Throwable $_) {}
    }

    // ── Aceite explícito de tratamento dos dados da própria solicitação ────
    if (empty($input['aceito_tratamento'])) {
        ApiResponse::badRequest('É necessário concordar com o tratamento dos dados da solicitação');
    }

    try {
        $res = LgpdRequest::create([
            'titular_nome'     => $input['titular_nome']     ?? '',
            'titular_email'    => $email,
            'titular_cpf'      => $input['titular_cpf']      ?? null,
            'titular_telefone' => $input['titular_telefone'] ?? null,
            'tipo'             => $input['tipo']             ?? '',
            'descricao'        => $input['descricao']        ?? null,
        ]);
    } catch (\InvalidArgumentException $e) {
        ApiResponse::badRequest($e->getMessage());
    }

    // v2: tentativa de inferir tipo do titular + tenant automaticamente.
    // DPO pode reclassificar depois via Painel Master. Falha silenciosa
    // (mantém solicitação ainda que inferência falhe).
    try {
        $inferred = LgpdRequest::inferTitularTipo($email, $input['titular_cpf'] ?? null);
        if ($inferred) {
            LgpdRequest::setTitularRef(
                $res['id'],
                $inferred['tipo'],
                $inferred['entidade'],
                $inferred['entidade_id'],
                $inferred['account_id']
            );
            LgpdRequest::addEvent($res['id'], 'tipificacao_automatica',
                "Titular inferido como '{$inferred['tipo']}' (entidade={$inferred['entidade']} #{$inferred['entidade_id']}, account_id={$inferred['account_id']}). DPO deve confirmar.",
                null);
        }
    } catch (\Throwable $_e) {
        error_log('[lgpd/request inferir] ' . $_e->getMessage());
    }

    // Registra tentativa pro rate-limit
    try {
        $pdo = Database::getConnection();
        $pdo->prepare('INSERT INTO login_attempts (ip, login, success, created_at) VALUES (?, ?, 1, NOW())')
            ->execute([$ip, '[LGPD]' . $email]);
    } catch (\Throwable $_) {}

    // Notifica DPO via Mailer (driver atual=log; em prod vai pra SMTP)
    try {
        EnvLoader::load();
        $dpoEmail = EnvLoader::get('DPO_EMAIL', '');
        if ($dpoEmail !== '') {
            $url    = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                    . '/sistema_vendas/public/lgpd/acompanhar.php?token=' . $res['token'];
            $body   = '<p>Nova solicitação LGPD recebida.</p>'
                    . '<p><strong>Tipo:</strong> ' . htmlspecialchars((string)($input['tipo'] ?? ''))
                    . '<br><strong>Titular:</strong> ' . htmlspecialchars((string)($input['titular_nome'] ?? ''))
                    . ' (' . htmlspecialchars($email) . ')'
                    . '<br><strong>Link de acompanhamento:</strong> ' . htmlspecialchars($url)
                    . '</p><p>Acesse o Painel Master &rarr; aba LGPD para responder.</p>';
            \App\Services\Mailer::send([
                'account_id'   => null,   // notificação sistêmica
                'to_email'     => $dpoEmail,
                'to_name'      => 'DPO Yuris',
                'subject'      => 'Yuris — Nova solicitação LGPD #' . $res['id'],
                'body_html'    => $body,
                'template_key' => 'lgpd_request_new',
            ]);
        }
    } catch (\Throwable $_e) {
        // não bloqueia criação se Mailer falhar
    }

    ApiResponse::ok([
        'id'             => $res['id'],
        'token'          => $res['token'],
        'acompanhamento' => '/sistema_vendas/public/lgpd/acompanhar.php?token=' . $res['token'],
        'prazo_dias'     => LgpdRequest::PRAZO_DIAS,
    ]);
}

ApiResponse::methodNotAllowed();
