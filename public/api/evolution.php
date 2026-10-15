<?php
/**
 * /api/evolution.php: a aba Evolution da edição CRM (painel do servidor de
 * WhatsApp da Inovaize).
 *
 *   GET                                       lê o Evolution agora, grava a foto do dia
 *                                             e devolve instâncias, resumo, por projeto,
 *                                             histórico, projetos (para o vínculo)
 *   POST {acao:'vincular', instancia, projeto_id}   liga a instância a um projeto (0 desliga)
 *   POST {acao:'custo_salvar', valor}               custo mensal do Evolution (vazio apaga)
 *
 * Só leitura no Evolution (App\Projetos\Evolution). O token de cada instância
 * nunca sai. Só a conta com a pasta Projetos ligada e só administrador: vendedor
 * da edição CRM recebe 403, conta sem o módulo 404.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Projetos\Evolution;
use App\Projetos\Projeto;

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(int $codigo, array $corpo): void
{
    http_response_code($codigo);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user_id'])) responder(401, ['error' => 'Sessão expirada.']);
$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();
if ($ctx->vendedorCrm()) responder(403, ['error' => 'Disponível só para administradores.']);
$accountId = (int) $ctx->getAccountId();
$conta = \App\Master\Account::findById($accountId) ?? [];
if (!Projeto::habilitado($conta)) responder(404, ['error' => 'A aba Evolution não está disponível nesta conta.']);
$userId = (int) $ctx->getUserId();
$pdo = \App\Core\Database::getConnection();

function painelEvolution(\PDO $pdo, int $accountId, array $conta): array
{
    $hoje = Projeto::hoje();
    [$instancias, $versao, $erro] = Evolution::coletar($pdo);
    if (!$erro) Evolution::gravarFoto($pdo, $accountId, $instancias, $hoje);
    $hist = Evolution::historico($pdo, $accountId, $hoje);
    $cfg = Evolution::config($conta);
    $st = $pdo->prepare("SELECT id, nome, parent_id, status FROM projetos WHERE account_id = ? AND deleted_at IS NULL ORDER BY nome");
    $st->execute([$accountId]);
    $projetos = []; $lista = [];
    foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $p) {
        $projetos[(int) $p['id']] = $p['nome'];
        $lista[] = ['id' => (int) $p['id'], 'nome' => $p['nome'], 'parent_id' => $p['parent_id'] ? (int) $p['parent_id'] : null, 'encerrado' => $p['status'] === 'encerrado'];
    }
    $m = Evolution::montar($instancias, $cfg['vinculos'], Evolution::sugestoes($pdo, $accountId), $projetos, $cfg['custo'], $hist['anterior']);
    return ['success' => true, 'erro' => $erro, 'versao' => $versao, 'endereco' => Evolution::endereco($pdo),
            'lido_em' => (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s'),
            'historico' => $hist['serie'], 'historico_desde' => $hist['desde'],
            'projetos' => $lista,
            'status' => Evolution::STATUS] + $m;
}

$metodo = $_SERVER['REQUEST_METHOD'];
if ($metodo === 'GET') responder(200, painelEvolution($pdo, $accountId, $conta));
if ($metodo !== 'POST') responder(405, ['error' => 'Método não permitido.']);

$in = json_decode((string) file_get_contents('php://input'), true) ?: [];
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['csrf_token'] ?? '');
if (!$csrf || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) $csrf)) responder(400, ['error' => 'Sessão inválida. Recarregue a página.']);
$audit = fn(string $evento, array $det = []) => \App\Master\Account::audit($accountId, 'evolution.' . $evento, [
    'user_id' => $userId, 'entidade' => 'evolution', 'entidade_id' => $accountId, 'detalhes' => $det,
]);

switch ((string) ($in['acao'] ?? '')) {
    case 'vincular':
        $inst = (string) ($in['instancia'] ?? '');
        $pid = (int) ($in['projeto_id'] ?? 0);
        if (!Evolution::vincular($pdo, $accountId, $inst, $pid)) responder(422, ['error' => 'Projeto ou instância inválidos.']);
        $audit('vinculo', ['instancia' => $inst, 'projeto_id' => $pid]);
        responder(200, ['success' => true]);

    case 'custo_salvar':
        $bruto = trim((string) ($in['valor'] ?? ''));
        $cent = $bruto === '' ? null : Projeto::centavos($bruto);
        if ($bruto !== '' && ($cent === null || $cent > Projeto::MAX_VALOR_CENTAVOS)) responder(422, ['error' => 'Valor inválido. Use o formato 1.234,56.']);
        Evolution::salvarCusto($pdo, $accountId, $cent);
        $audit('custo', ['centavos' => $cent]);
        responder(200, ['success' => true]);
}

responder(400, ['error' => 'Ação desconhecida.']);
