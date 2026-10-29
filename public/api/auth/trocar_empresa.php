<?php
/**
 * /api/auth/trocar_empresa.php: a mesma pessoa em mais de uma conta (29/10/2026).
 *
 *   GET                                  -> { ok, empresas: [{user_id, account_id, conta, cor, atual}] }
 *   POST { user_id } + X-CSRF-Token      -> { ok, ir: '/dashboard' }
 *
 * Troca a sessão para o usuário da pessoa na outra conta, pela mesma rotina do
 * login (App\Usuarios\PessoaVinculada::trocar). Só entre usuários ligados como
 * a mesma pessoa; o destino vem da lista do servidor, nunca do corpo cru.
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Usuarios\PessoaVinculada;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

session_start();
$uid = (int) ($_SESSION['user_id'] ?? 0);
if ($uid <= 0) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Entre de novo.']); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    session_write_close();
    echo json_encode(['ok' => true, 'empresas' => PessoaVinculada::contas($uid)], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false]); exit; }

$csrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if ($csrf === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.']); exit;
}
$in = json_decode((string) file_get_contents('php://input'), true) ?: [];
try {
    $r = PessoaVinculada::trocar($uid, (int) ($in['user_id'] ?? 0));
} catch (\Throwable $e) {
    error_log('[trocar_empresa] ' . $e->getMessage());
    $r = ['ok' => false, 'error' => 'Não foi possível trocar de empresa.'];
}
if (!$r['ok']) http_response_code(422);
echo json_encode($r['ok'] ? ['ok' => true, 'ir' => '/dashboard'] : $r, JSON_UNESCAPED_UNICODE);
