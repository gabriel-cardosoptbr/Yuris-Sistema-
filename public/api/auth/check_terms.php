<?php
/**
 * /api/auth/check_terms.php — GET ?email=user@example.com
 *
 * Responde { ok:true, accepted: bool } indicando se o e-mail já registrou
 * aceite ativo dos termos (finalidade=termos_uso_login) em lgpd_consents,
 * cobrindo aceites feitos como anônimo (titular_email) E como user logado.
 *
 * Usado pelo login.php pra esconder o checkbox quando o user já aceitou
 * antes — independente do navegador (consulta o servidor).
 *
 * Não vaza se o e-mail existe no sistema: sempre retorna 200 com bool.
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Database;
use App\Core\ApiResponse;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'GET only']);
    exit;
}

$email = trim((string)($_GET['email'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => true, 'accepted' => false]);
    exit;
}

try {
    $pdo = Database::getConnection();
    // Procura aceite ativo de termos pra esse e-mail, considerando:
    //   1) consents anônimos (titular_email)
    //   2) consents do user logado (user_id resolvido via tabela users)
    // OBS: tabela users usa coluna `login` (e-mail), não `email`.
    $stmt = $pdo->prepare(
        "SELECT 1
           FROM lgpd_consents lc
           LEFT JOIN users u ON u.id = lc.user_id
          WHERE lc.status = 'ativo'
            AND lc.finalidade = :fin
            AND (lc.titular_email = :email OR u.login = :email)
          LIMIT 1"
    );
    $stmt->execute(['fin' => 'termos_uso_login', 'email' => $email]);
    $accepted = (bool)$stmt->fetchColumn();

    echo json_encode(['ok' => true, 'accepted' => $accepted]);
} catch (\Throwable $e) {
    // Não exibe detalhes do erro pra pre-login (info disclosure).
    error_log('[check_terms] ' . $e->getMessage());
    echo json_encode(['ok' => true, 'accepted' => false]);
}
