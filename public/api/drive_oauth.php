<?php
/**
 * Conexão da conta Google (OAuth) para o Drive dos clientes. Ver app/Drive.
 *
 *   GET ?iniciar=1&volta=/clientes   administrador: vai para o Google (state na sessão)
 *   GET ?code=...&state=...          volta do Google: confere o state e grava a conexão
 *
 * O endereço de volta é sempre interno (começa com "/" e não com "//"). O
 * resultado vai para a sessão (drive_flash) e a tela mostra.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Ambientes\Ambiente;
use App\Core\AccountContext;
use App\Drive\Api;
use App\Drive\Conexao;

session_start();
if (empty($_SESSION['user_id'])) { header('Location: /login'); exit; }
$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();
$acc = (int) $ctx->getAccountId();

function drvVolta(string $v): string
{
    return (str_starts_with($v, '/') && !str_starts_with($v, '//') && !str_contains($v, "\n")) ? $v : '/empresa';
}
function drvFim(string $volta, bool $ok, string $msg): never
{
    $_SESSION['drive_flash'] = ['ok' => $ok, 'msg' => $msg];
    unset($_SESSION['drive_oauth']);
    session_write_close();
    header('Location: ' . drvVolta($volta));
    exit;
}

if (!$ctx->isOwnerOrAdmin()) drvFim('/empresa', false, 'Só um administrador conecta a conta Google.');
if (!Ambiente::habilitado(\App\Master\Account::findById($acc) ?? [])) drvFim('/dashboard', false, 'Ambientes não estão ligados nesta conta.');
if (!Api::configurado()) drvFim((string) ($_GET['volta'] ?? '/empresa'), false, 'O Google Drive ainda não foi configurado no servidor (GOOGLE_DRIVE_CLIENT_ID e GOOGLE_DRIVE_CLIENT_SECRET).');
$host = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '');
$host = preg_replace('/[^A-Za-z0-9.:-]/', '', explode(',', $host)[0]);

if (!empty($_GET['iniciar'])) {
    $state = bin2hex(random_bytes(24));
    $_SESSION['drive_oauth'] = ['state' => $state, 'acc' => $acc, 'uid' => (int) $ctx->getUserId(), 'volta' => drvVolta((string) ($_GET['volta'] ?? '/empresa')), 'exp' => time() + 600];
    session_write_close();
    header('Location: ' . Conexao::urlAutorizacao($state, $host));
    exit;
}

$pend = $_SESSION['drive_oauth'] ?? null;
$volta = $pend['volta'] ?? '/empresa';
if (!$pend || ($pend['exp'] ?? 0) < time() || !hash_equals((string) $pend['state'], (string) ($_GET['state'] ?? '')) || (int) $pend['acc'] !== $acc) {
    drvFim($volta, false, 'A autorização expirou ou não confere. Clique em "Conectar" de novo.');
}
if (!empty($_GET['error'])) drvFim($volta, false, 'A conexão com o Google foi cancelada.');
try {
    $e = Conexao::concluir($acc, (int) $pend['uid'], (string) ($_GET['code'] ?? ''), $host);
    drvFim($volta, true, 'Google Drive conectado com ' . $e['email'] . '.');
} catch (\App\Drive\DriveErro $e) {
    drvFim($volta, false, $e->getMessage());
} catch (\Throwable $e) {
    error_log('[drive_oauth] ' . $e->getMessage());
    drvFim($volta, false, 'Não foi possível concluir a conexão com o Google.');
}
