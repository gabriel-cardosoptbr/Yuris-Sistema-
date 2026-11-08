<?php
/**
 * pessoa.php: o cartão de uma pessoa do escritório (08/11/2026).
 *
 * "O responsável tem que ser clicável, que eu possa clicar e ir para o advogado, para ver
 * quem ele é." A etiqueta do responsável (prazo e tarefa do processo) abre este cartão: quem
 * é (nome, papel, OAB, escritório, contato) e o que tem na mão agora, com os mesmos números do
 * "pop-up da pessoa" da home (App\Inicio\Painel::equipe sobre a Central e a CargaDaEquipe).
 *
 * GET ?id=N   só pessoa que a conta enxerga (AccountContext::getAccessibleUsers); qualquer
 *             outra dá 404, sem dizer se existe.
 */
require_once __DIR__ . '/_json_guard.php';
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\Database;
use App\Inicio\Painel;
use App\Tarefas\CargaDaEquipe;
use App\Tarefas\Central;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$ctx = AccountContext::fromSession();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método não suportado']);
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Informe a pessoa']);
    exit;
}

try {
    // Só quem a conta enxerga: a mesma lista que preenche o "Responsável".
    $acessiveis = [];
    foreach ($ctx->getAccessibleUsers() as $u) $acessiveis[(int) $u['id']] = $u;
    if (!isset($acessiveis[$id])) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Pessoa não encontrada']);
        exit;
    }
    $contas = array_values(array_unique(array_map('intval', array_column($acessiveis, 'account_id'))));
    $in = implode(',', array_fill(0, count($contas), '?'));
    $st = Database::getConnection()->prepare(
        "SELECT u.id, u.nome, u.login, u.telefone, u.perfil, u.role, u.is_advogado, u.oab, u.oab_uf, u.account_id, a.nome AS account_nome
           FROM users u JOIN accounts a ON a.id = u.account_id
          WHERE u.id = ? AND u.account_id IN ($in) AND u.deleted_at IS NULL LIMIT 1"
    );
    $st->execute(array_merge([$id], $contas));
    $u = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$u) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Pessoa não encontrada']);
        exit;
    }

    // O que tem na mão: os mesmos números do pop-up da pessoa na home.
    $escopo = [
        'user_id'          => $ctx->getUserId(),
        'is_admin'         => $ctx->isOwnerOrAdmin(),
        'contas_tarefas'   => $ctx->getAccessibleAccountIds('tarefas'),
        'contas_processos' => $ctx->getAccessibleAccountIds('processos'),
    ];
    $itens = Central::listar($escopo, ['situacao' => 'abertas', 'responsavel_id' => $id])['itens'] ?? [];
    $carga = CargaDaEquipe::agora($escopo['contas_tarefas'], $id);
    $numeros = Painel::equipe([$u], $carga['pessoas'] ?? [], $itens)[0] ?? [];

    $oab = trim((string) ($u['oab'] ?? ''));
    echo json_encode(['ok' => true, 'data' => [
        'id'        => (int) $u['id'],
        'nome'      => (string) $u['nome'],
        'iniciais'  => (string) ($numeros['iniciais'] ?? ''),
        'papel'     => (string) ($numeros['papel'] ?? ''),
        'oab'       => $oab !== '' ? $oab . (trim((string) $u['oab_uf']) !== '' ? '/' . trim((string) $u['oab_uf']) : '') : '',
        'escritorio'=> (string) $u['account_nome'],
        'email'     => (string) ($u['login'] ?? ''),
        'telefone'  => (string) ($u['telefone'] ?? ''),
        'estado'    => (string) ($numeros['estado'] ?? 'livre'),
        'hoje'      => (int) ($numeros['hoje'] ?? 0),
        'semana'    => (int) ($numeros['semana'] ?? 0),
        'vencidas'  => (int) ($numeros['vencidas'] ?? 0),
        'abertas'   => (int) ($numeros['abertas'] ?? 0),
        // Desempenho com o foco nesta pessoa só serve a quem vê a equipe.
        'desempenho'=> $ctx->isOwnerOrAdmin() ? '/desempenho?colaborador=' . (int) $u['id'] : '',
    ]], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    \App\Core\ErrorReporter::handle($e);
    exit;
}
