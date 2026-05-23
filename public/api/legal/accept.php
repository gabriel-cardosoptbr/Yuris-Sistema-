<?php
/**
 * /api/legal/accept.php
 *
 * POST  body: { legal_document_id: N, contexto: 'login'|'signup'|... [, titular_email] }
 *
 * Registra aceite de termo legal por user logado OU titular anônimo (email).
 * Anti-replay: se já existe aceite recente do MESMO documento+user, não duplica.
 *
 * CSRF obrigatório.
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/LegalDocument.php';
require_once __DIR__ . '/../../../app/Models/TermAcceptance.php';
require_once __DIR__ . '/../../../app/Helpers/ApiResponse.php';

use App\Models\LegalDocument;
use App\Models\TermAcceptance;
use App\Helpers\ApiResponse;

session_start();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') ApiResponse::methodNotAllowed();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$csrf  = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
    ApiResponse::badRequest('CSRF inválido');
}

$docId = (int)($input['legal_document_id'] ?? 0);
$ctx   = trim((string)($input['contexto'] ?? 'unknown'));
if (!$docId) ApiResponse::badRequest('legal_document_id obrigatório');

// Confirma que o documento existe e é o vigente do tipo
$doc = LegalDocument::findById($docId);
if (!$doc || !(int)$doc['ativo']) {
    ApiResponse::badRequest('Documento não encontrado ou inativo');
}

$userId    = $_SESSION['user_id']    ?? null;
$accountId = $_SESSION['account_id'] ?? null;
$email     = trim((string)($input['titular_email'] ?? ''));

if (!$userId && $email === '') {
    ApiResponse::badRequest('Aceite anônimo exige titular_email');
}

// Anti-replay: já aceitou EXATAMENTE este documento recentemente? não duplica
if ($userId) {
    $pdo = \App\Models\Database::getConnection();
    $st = $pdo->prepare(
        "SELECT id FROM term_acceptances
         WHERE user_id = :uid AND legal_document_id = :doc
         ORDER BY accepted_at DESC LIMIT 1"
    );
    $st->execute(['uid' => $userId, 'doc' => $docId]);
    $existingId = $st->fetchColumn();
    if ($existingId) {
        ApiResponse::ok(['id' => (int)$existingId, 'already_accepted' => true]);
    }
}

$id = TermAcceptance::record([
    'legal_document_id' => $docId,
    'user_id'           => $userId,
    'account_id'        => $accountId,
    'titular_email'     => $userId ? null : $email,
    'contexto'          => $ctx,
]);

ApiResponse::ok(['id' => $id]);
