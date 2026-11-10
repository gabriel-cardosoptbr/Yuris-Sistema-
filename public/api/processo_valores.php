<?php
/**
 * processo_valores.php — valores do processo, resultado, carteira e projeção
 * (T3, 10/11/2026, migration 180). Regras em App\Processos\ValoresDoProcesso.
 *
 * GET  ?processo_id=ID          valores gravados + resumo das parcelas no Financeiro
 * GET  ?carteira=1              potencial, em aberto e projetado x obtido dos encerrados
 * GET  ?projecao=1[&meses=12]   previsto e recebido por mês (receitas com vencimento)
 * POST {processo_id, valor_causa, valor_estimado, resultado_tipo, valor_resultado,
 *       resultado_forma, resultado_parcelas, resultado_primeiro_vencimento}
 *       grava e sincroniza o Financeiro (só as parcelas pendentes mudam).
 *
 * Só com o módulo jurídico. Acesso ao processo pelo escopo canônico
 * (TenantGuard::assertProcessoAcessivel). POST exige mesma origem ou CSRF.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\TenantGuard;
use App\Processos\ValoresDoProcesso;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error' => 'Não autenticado']); exit; }

$ctx = AccountContext::fromSession();
$ctx->assertModuloJuridicoDisponivel();
$userId = $ctx->getUserId();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!empty($_GET['processo_id'])) {
            $id = (int) $_GET['processo_id'];
            TenantGuard::assertProcessoAcessivel($ctx, $id);
            echo json_encode(['data' => ValoresDoProcesso::doProcesso($id), 'tipos' => ValoresDoProcesso::TIPOS]);
            exit;
        }
        if (!empty($_GET['carteira'])) {
            echo json_encode(['data' => ValoresDoProcesso::carteira($ctx->getAccessibleAccountIds('processos'))]);
            exit;
        }
        if (!empty($_GET['projecao'])) {
            if ($ctx->vendedorCrm()) { http_response_code(403); echo json_encode(['error' => 'Financeiro disponível só para administradores.']); exit; }
            $contas = $ctx->getAccessibleAccountIds('financas') ?: [$ctx->getAccountId()];
            echo json_encode(['data' => ValoresDoProcesso::projecaoMensal($contas, (int) ($_GET['meses'] ?? 12),
                                                                           ['so_processos' => !empty($_GET['so_processos'])])]);
            exit;
        }
        http_response_code(400);
        echo json_encode(['error' => 'Consulta inválida']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Método não permitido']); exit; }
    TenantGuard::requireSameOriginOrCsrf();
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int) ($input['processo_id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'processo_id obrigatório']); exit; }
    TenantGuard::assertProcessoAcessivel($ctx, $id);
    $dados = ValoresDoProcesso::salvar($id, $input, $userId);
    echo json_encode(['success' => true, 'data' => $dados]);
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    \App\Core\ErrorReporter::handle($e);
}
