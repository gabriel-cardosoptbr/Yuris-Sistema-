<?php
/**
 * /api/auth/csrf.php: GET. O token de proteção do formulário de login, da sessão
 * de AGORA.
 *
 * A tela de login guarda o token da sessão em que foi aberta. O PHP apaga a
 * sessão parada há mais de 24 min (session.gc_maxlifetime), e o Chrome reabre a
 * aba de ontem sem buscar a página de novo: o token da tela já não bate com o
 * da sessão, e o login voltava com "Token inválido." sem conferir a senha (o
 * Kauê, da Inovaize, ficou sem entrar em 28/10/2026). A tela pede este token
 * logo antes de enviar (assets/login-token.js).
 *
 * Responde { ok: true, token }. Só a própria página lê a resposta (sem CORS),
 * então o token continua fora do alcance de outro site.
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'GET only']);
    exit;
}

session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$token = (string) $_SESSION['csrf_token'];
session_write_close();

echo json_encode(['ok' => true, 'token' => $token]);
