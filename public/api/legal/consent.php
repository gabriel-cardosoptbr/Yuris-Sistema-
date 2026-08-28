<?php
/**
 * /api/legal/consent.php
 *
 * GET                                         → lista consentimentos do user atual
 * POST  body: { finalidade, base_legal?, fonte?, evidencia? }   → grant
 * DELETE body: { finalidade }                                   → revoke
 *
 * Consentimentos anônimos (sem login) usam titular_email no body.
 *
 * CSRF obrigatório em POST/DELETE.
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Usuarios\Consent;
use App\Core\ApiResponse;

session_start();
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$userId = $_SESSION['user_id']    ?? null;
$accountId = $_SESSION['account_id'] ?? null;

// ─── GET — lista consentimentos do titular ──────────────────────────────────
if ($method === 'GET') {
    $email = trim((string)($_GET['email'] ?? ''));
    if (!$userId && $email === '') {
        ApiResponse::badRequest('Faça login OU informe email do titular');
    }
    $list = Consent::listAll($userId, $userId ? null : $email);
    // Não devolve IP/UA/evidencia plain — só o essencial
    $clean = array_map(fn($r) => [
        'id'            => (int)$r['id'],
        'finalidade'    => $r['finalidade'],
        'base_legal'    => $r['base_legal'],
        'status'        => $r['status'],
        'concedido_em'  => $r['concedido_em'],
        'revogado_em'   => $r['revogado_em'],
        'fonte'         => $r['fonte'],
    ], $list);
    ApiResponse::ok($clean);
}

// ─── CSRF pra mutações ──────────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$csrf  = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
    ApiResponse::badRequest('CSRF inválido');
}

$finalidade = trim((string)($input['finalidade'] ?? ''));
if ($finalidade === '') ApiResponse::badRequest('finalidade obrigatória');

$email = trim((string)($input['titular_email'] ?? ''));
if (!$userId && $email === '') {
    ApiResponse::badRequest('Sem login: titular_email obrigatório');
}

// ─── POST — grant ───────────────────────────────────────────────────────────
if ($method === 'POST') {
    try {
        $id = Consent::grant([
            'user_id'         => $userId,
            'account_id'      => $accountId,
            'titular_email'   => $userId ? null : $email,
            'finalidade'      => $finalidade,
            'base_legal'      => $input['base_legal']      ?? 'consentimento',
            'fonte'           => $input['fonte']           ?? null,
            'evidencia'       => $input['evidencia']       ?? null,
            'versao_termo_id' => $input['versao_termo_id'] ?? null,
        ]);
        ApiResponse::ok(['id' => $id]);
    } catch (\InvalidArgumentException $e) {
        ApiResponse::badRequest($e->getMessage());
    }
}

// ─── DELETE — revoke ────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $affected = Consent::revoke(
        $userId,
        $userId ? null : $email,
        $finalidade
    );
    ApiResponse::ok(['revoked' => $affected]);
}

ApiResponse::methodNotAllowed();
