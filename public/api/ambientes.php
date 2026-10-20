<?php
/**
 * Ambientes da Inovaize (App\Ambientes): os números das Visões Gerais.
 *
 *   GET  ?visao=empresa|software|digital [&mes=AAAA-MM]
 *   GET  ?visao=comercial_areas [&mes=AAAA-MM]   leads abertos e vendas do mês por área do serviço
 *   POST {acao:'lead_area', card_id, area}         software, digital, ambos ou '' (o que o lead quer comprar)
 *
 * Só na conta com o módulo ligado. Cada visão confere no servidor se a pessoa
 * entra naquele ambiente (Ambiente::podeEntrar), a mesma regra do seletor e das
 * páginas. Vendedor da edição CRM só vê os próprios clientes e leads.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Ambientes\Ambiente;
use App\Ambientes\AreaDoCliente;
use App\Ambientes\Visao;
use App\Core\AccountContext;
use App\Tarefas\TaskBoard;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ambResponder(array $corpo, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user_id'])) ambResponder(['ok' => false, 'error' => 'Sessão expirada.'], 401);
$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();
$acc = (int) $ctx->getAccountId();
$uid = (int) $ctx->getUserId();
$admin = $ctx->isOwnerOrAdmin();
if (!Ambiente::habilitado(\App\Master\Account::findById($acc) ?? [])) ambResponder(['ok' => false, 'error' => 'Ambientes não estão ligados nesta conta.'], 404);
$pode = [Ambiente::class, 'podePagina'];
$vendedor = AccountContext::vendedorDaSessao();
$mes = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['mes'] ?? '')) ? (string) $_GET['mes'] : date('Y-m');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $visao = (string) ($_GET['visao'] ?? '');
        $amb = $visao === 'comercial_areas' ? 'comercial' : $visao;
        if (!Ambiente::valido($amb) || !Ambiente::podeEntrar($amb, $pode, $admin)) ambResponder(['ok' => false, 'error' => 'Sem permissão para este ambiente.'], 403);
        $quadros = TaskBoard::findForUser($uid, $ctx->getAccessibleAccountIds('tarefas') ?: [$acc], $admin);
        $dados = match ($visao) {
            'empresa'  => Visao::empresa($acc, $quadros, $mes, \App\Contratos\Permissao::podeVer()),
            'software' => Visao::software($acc, $quadros),
            'digital'  => Visao::digital($acc, $quadros, AreaDoCliente::clientesDaArea($acc, 'digital', $vendedor), $mes),
            'comercial_areas' => Visao::comercialPorArea($acc, $mes, $vendedor),
        };
        ambResponder(['ok' => true, 'data' => $dados]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') ambResponder(['ok' => false, 'error' => 'Método não permitido.'], 405);
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['csrf_token'] ?? '');
    if (!$csrf || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) $csrf)) ambResponder(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.'], 419);

    if (($in['acao'] ?? '') === 'lead_area') {
        if (!Ambiente::podePagina('prospeccao')) ambResponder(['ok' => false, 'error' => 'Sem permissão para a Prospecção.'], 403);
        $area = (string) ($in['area'] ?? '');
        if (!in_array($area, ['', 'software', 'digital', 'ambos'], true)) ambResponder(['ok' => false, 'error' => 'Área inválida.'], 400);
        // Vendedor só no lead dele (Entidade::resolver já aplica a regra); outra conta: 404.
        $card = \App\Crm\Entidade::resolver('card', (int) ($in['card_id'] ?? 0), $ctx->getAccessibleAccountIds('prospeccao') ?: [$acc]);
        if ($card === null) ambResponder(['ok' => false, 'error' => 'Lead não encontrado.'], 404);
        $ctx->assertCanWrite('card', (int) $card['id']);
        \App\Core\Database::getConnection()->prepare('UPDATE cards SET area_servico = ? WHERE id = ? AND account_id = ?')
            ->execute([$area ?: null, (int) $card['id'], (int) $card['account_id']]);
        ambResponder(['ok' => true, 'area' => $area ?: null]);
    }
    ambResponder(['ok' => false, 'error' => 'Ação desconhecida.'], 400);
} catch (\InvalidArgumentException $e) {
    ambResponder(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (\Throwable $e) {
    error_log('[api/ambientes] ' . $e->getMessage());
    ambResponder(['ok' => false, 'error' => 'Não foi possível carregar agora.'], 500);
}
