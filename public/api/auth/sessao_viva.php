<?php
/**
 * /api/auth/sessao_viva.php: GET. Mantém a sessão viva enquanto uma tela fica aberta.
 *
 * As APIs que as telas consultam em ciclo (o quadro de prospecção, o sino) abrem a
 * sessão com read_and_close: só leem e nunca regravam. O PHP apaga a sessão que
 * passa session.gc_maxlifetime (24 min) sem ser regravada, então quem deixava o
 * quadro aberto perdia a sessão no meio do dia sem saber, e o quadro esvaziava.
 * Este endpoint abre a sessão normal (e a regrava), uma vez a cada poucos minutos.
 *
 * Responde { ok: true, logado: bool }. Não estende sessão que já acabou: sem
 * usuário na sessão, devolve 401 e a tela manda entrar de novo.
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
if (empty($_SESSION['user_id']) || empty($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'logado' => false]);
    exit;
}
// Grava algo que muda: garante a regravação do arquivo da sessão mesmo onde o
// PHP pularia a escrita de uma sessão idêntica (session.lazy_write).
$_SESSION['ultima_atividade'] = time();
session_write_close();

echo json_encode(['ok' => true, 'logado' => true]);
