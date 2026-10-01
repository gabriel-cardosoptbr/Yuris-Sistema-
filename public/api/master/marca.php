<?php
/**
 * Painel Master: marca de uma conta da edição CRM comercial.
 *
 *   GET  ?account_id=N  → edição da conta e a marca (resolvida e como está gravada)
 *   POST {account_id, marca:{nome, subtitulo, cor, dominio, agente_nome, agente_webhook},
 *         logo?, icone?}
 *        logo/icone: data URL da imagem nova, "remover" para tirar, ausente mantém.
 *
 * Só super admin, CSRF obrigatório na escrita, auditoria em MasterAudit.
 * Só edita conta que JÁ é da edição CRM: trocar a edição de uma conta existente
 * esconderia ou mostraria o jurídico com dado dentro, e não é feito por aqui.
 * Ver App\Master\Marca.
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\ApiResponse;
use App\Core\Database;
use App\Master\Account;
use App\Master\Marca;
use App\Master\MasterAudit;

session_start();
$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();

$method = $_SERVER['REQUEST_METHOD'];
$input  = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];

if ($method === 'POST') {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) ApiResponse::badRequest('CSRF inválido');
} elseif ($method !== 'GET') {
    ApiResponse::methodNotAllowed();
}

$accountId = (int) ($method === 'GET' ? ($_GET['account_id'] ?? 0) : ($input['account_id'] ?? 0));
if ($accountId <= 0) ApiResponse::badRequest('account_id é obrigatório');

$pdo   = Database::getConnection();
$conta = Account::findById($accountId);
if (!$conta) ApiResponse::notFound('Conta não encontrada');

$edicao = Account::getProduto($conta) === 'fleetiflow' ? 'crm' : 'yuris';

$resposta = static function (array $conta) use ($edicao): array {
    $cfg = json_decode((string) ($conta['configuracoes'] ?? ''), true);
    return [
        'account_id' => (int) $conta['id'],
        'edicao'     => $edicao,
        'marca'      => $edicao === 'crm' ? Marca::daConta($conta) : null,
        'gravada'    => is_array($cfg['marca'] ?? null) ? $cfg['marca'] : null,
    ];
};

if ($method === 'GET') {
    ApiResponse::ok($resposta($conta));
}

if ($edicao !== 'crm') {
    ApiResponse::badRequest('Esta conta é da edição jurídica (Yuris). Marca própria é só para a edição CRM.');
}

try {
    $marca = Marca::normalizar(is_array($input['marca'] ?? null) ? $input['marca'] : []);
    $bins  = [];
    foreach (Marca::TIPOS as $t) {
        $v = $input[$t] ?? null;
        if ($v === 'remover') {
            $bins[$t] = null;
        } elseif (is_string($v) && $v !== '') {
            $bin = Marca::decodificarUpload($v);
            Marca::validarImagem($bin);
            $bins[$t] = $bin;
        }
    }
} catch (\InvalidArgumentException $e) {
    ApiResponse::badRequest($e->getMessage());
}

if ($marca['dominio'] !== null && Marca::contaPorDominio($pdo, $marca['dominio'], $accountId) !== null) {
    ApiResponse::badRequest('Este domínio já é usado pela marca de outra conta.');
}

try {
    $pdo->beginTransaction();
    $hashes = [];
    foreach ($bins as $t => $bin) {
        if ($bin === null) {
            Marca::removerArquivo($pdo, $accountId, $t);
            $hashes[$t] = null;
        } else {
            $hashes[$t] = Marca::salvarArquivo($pdo, $accountId, $t, $bin);
        }
    }
    Marca::gravar($pdo, $accountId, $marca, $hashes);
    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[master/marca] ' . $e->getMessage());
    ApiResponse::serverError('Não foi possível salvar a marca.');
}

MasterAudit::log(
    'account.marca',
    'account',
    $accountId,
    "Marca da conta '{$conta['nome']}' atualizada: {$marca['nome']}",
    [
        'nome'    => $marca['nome'],
        'cor'     => $marca['cor'],
        'dominio' => $marca['dominio'],
        'agente'  => $marca['agente_nome'] !== '' ? $marca['agente_nome'] : null,
        'agente_webhook_definido' => $marca['agente_webhook'] !== null,
        'imagens' => array_map(static fn($h) => $h === null ? 'removida' : 'nova', $hashes),
    ]
);

ApiResponse::ok($resposta(Account::findById($accountId)));
