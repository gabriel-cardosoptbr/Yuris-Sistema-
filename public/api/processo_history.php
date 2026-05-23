<?php
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/Account.php';
require_once __DIR__ . '/../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../app/Helpers/TenantGuard.php';

use App\Models\Database;
use App\Helpers\AccountContext;
use App\Helpers\TenantGuard;

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$ctx       = AccountContext::fromSession();
$tenantIds = $ctx->getAccessibleAccountIds('processos');

$pdo = Database::getConnection();

// Criar tabela processo_history se não existir
$pdo->exec("CREATE TABLE IF NOT EXISTS processo_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    processo_id INT NOT NULL,
    user_email VARCHAR(150),
    acao VARCHAR(100),
    descricao TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(processo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$method      = $_SERVER['REQUEST_METHOD'];
$userDisplay = $_SESSION['user_nome'] ?? $_SESSION['user_email'] ?? 'sistema';

try {
    if ($method === 'GET') {
        $processoId = (int)($_GET['processo_id'] ?? 0);
        if (!$processoId) { http_response_code(400); echo json_encode(['error' => 'Missing processo_id']); exit; }
        TenantGuard::assertProcessoAcessivel($ctx, $processoId);

        $stmt = $pdo->prepare(
            "SELECT * FROM processo_history WHERE processo_id = :pid ORDER BY created_at DESC LIMIT 50"
        );
        $stmt->execute(['pid' => $processoId]);
        echo json_encode(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($method === 'POST') {
        $input      = json_decode(file_get_contents('php://input'), true) ?? [];
        $processoId = (int)($input['processo_id'] ?? 0);
        $acao       = trim($input['acao'] ?? '');
        $descricao  = trim($input['descricao'] ?? '');
        if (!$processoId || !$acao) { http_response_code(400); echo json_encode(['error' => 'Missing fields']); exit; }
        TenantGuard::assertProcessoAcessivel($ctx, $processoId);

        // ─── LGPD P1 (2D.3): allowlist de 'acao' ─────────────────────────────
        // Antes desta correção, usuário podia POSTar acao='Processo modificado
        // pelo Dr. Fulano' forjando entrada legítima de outro autor. Comprometia
        // a auditoria do histórico. Agora aceita só valores conhecidos do
        // sistema; qualquer outro vira 'observacao_manual' (categoria neutra).
        $ALLOWED_ACOES = [
            'observacao_manual',
            'comentario',
            'nota_interna',
            'documento_adicionado',
            'tarefa_relacionada',
            'andamento_manual',
            'reuniao_registrada',
        ];
        if (!in_array($acao, $ALLOWED_ACOES, true)) {
            $acao = 'observacao_manual';   // fallback seguro, não bloqueia uso legítimo
        }
        // ────────────────────────────────────────────────────────────────────

        // Inclui snapshot da conta do autor pra renderizar badge MATRIZ/FILIAL.
        // user_email SEMPRE da sessão (nunca do body) — protege contra forjamento.
        $pdo->prepare(
            "INSERT INTO processo_history
               (processo_id, user_email, acao, descricao,
                author_account_id, author_account_tipo, author_account_nome)
             VALUES (:pid, :user, :acao, :desc, :aid, :atipo, :anome)"
        )->execute([
            ':pid'   => $processoId,
            ':user'  => $userDisplay,     // já vem do session via $userDisplay
            ':acao'  => $acao,
            ':desc'  => $descricao,
            ':aid'   => isset($_SESSION['account_id']) ? (int)$_SESSION['account_id'] : null,
            ':atipo' => $_SESSION['account_tipo'] ?? null,
            ':anome' => $_SESSION['account_nome'] ?? null,
        ]);
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;

} catch (\Throwable $e) {
    // P1 LGPD (2D.1): em prod esconde getMessage
    require_once __DIR__ . '/../../app/Helpers/ErrorReporter.php';
    \App\Helpers\ErrorReporter::handle($e);
}
