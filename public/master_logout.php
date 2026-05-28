<?php
/**
 * Painel Master — logout dedicado.
 *
 * Destrói a sessão completamente e redireciona pra /master_login.php
 * (não pro /login.php). Mantém o isolamento conceitual entre os dois mundos.
 *
 * Aceita GET e POST. Idempotente — pode ser chamado mesmo sem sessão ativa.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

// Limpa todas as keys da sessão
foreach (array_keys($_SESSION) as $k) unset($_SESSION[$k]);

// Remove cookie da sessão (defesa em profundidade)
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']);
}

session_destroy();

header('Location: /master_login.php');
exit;
