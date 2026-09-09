<?php

/**
 * /api/crm_interacoes.php — contatos registrados e notas internas (bloco D).
 *
 * GET    ?entidade=cliente|card&id=N[&limite=N]
 * POST   {entidade, id, tipo, direcao?, assunto?, conteudo?, ocorrido_em?, duracao_min?}
 * PATCH  {id, assunto?, conteudo?, ocorrido_em?, duracao_min?}
 * DELETE {id}
 *
 * ---------------------------------------------------------------------------
 * O TIPO NAO MUDA DEPOIS DE CRIADO
 * ---------------------------------------------------------------------------
 * PATCH aceita assunto, conteudo, data e duracao. Nao aceita `tipo`. Uma
 * ligacao que vira reuniao nao e correcao, e outro fato: a duracao passa a
 * significar outra coisa e a direcao tambem. Quem errou o tipo remove e registra
 * de novo, e as duas coisas ficam no historico, que e o comportamento honesto.
 *
 * ---------------------------------------------------------------------------
 * QUEM PODE EDITAR E REMOVER
 * ---------------------------------------------------------------------------
 * Qualquer usuario com acesso ao modulo da entidade. NAO se restringe ao autor,
 * ao contrario do /api/task_attachments.php, que so deixa quem subiu apagar.
 *
 * O motivo e o caso de uso real do escritorio: o advogado registra o
 * atendimento, a secretaria corrige o telefone que ficou errado, e o socio tira
 * a nota duplicada. Amarrar no autor obrigaria a chamar quem escreveu para
 * consertar erro de digitacao. O rastro nao se perde: `interacao_editada` e
 * `interacao_removida` gravam quem mexeu, e a remocao e soft.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Crm\Entidade;
use App\Crm\Interacao;

$metodo = $_SERVER['REQUEST_METHOD'];
if ($metodo === 'GET') {
    session_start(['read_and_close' => true]);
} else {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();

$userId = $ctx->getUserId();
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

function falha(string $msg, int $codigo = 400): void
{
    http_response_code($codigo);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function contasCrm(AccountContext $ctx): array
{
    return array_values(array_unique(array_merge(
        $ctx->getAccessibleAccountIds('clientes'),
        $ctx->getAccessibleAccountIds('prospeccao')
    )));
}

function alvoOu404(AccountContext $ctx, string $entidade, int $id): array
{
    $modulo = $entidade === Entidade::CARD ? 'prospeccao' : 'clientes';
    $alvo   = Entidade::resolver($entidade, $id, $ctx->getAccessibleAccountIds($modulo));
    if ($alvo === null) {
        falha('Registro não encontrado', 404);
    }
    return $alvo;
}

function conferirCsrf(array $input): void
{
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) $csrf)) {
        falha('Invalid CSRF token');
    }
}

/* ========================================================================= */
/* GET                                                                        */
/* ========================================================================= */

if ($metodo === 'GET') {
    $entidade = strtolower(trim((string) ($_GET['entidade'] ?? '')));
    $id       = (int) ($_GET['id'] ?? 0);
    if ($id <= 0 || !in_array($entidade, Entidade::TIPOS, true)) {
        falha('Informe entidade=cliente|card e id');
    }
    alvoOu404($ctx, $entidade, $id);

    $modulo = $entidade === Entidade::CARD ? 'prospeccao' : 'clientes';

    echo json_encode([
        'success'    => true,
        'interacoes' => Interacao::listar(
            $entidade,
            $id,
            $ctx->getAccessibleAccountIds($modulo),
            (int) ($_GET['limite'] ?? 200)
        ),
        'tipos'      => Interacao::ROTULOS,
        'direcoes'   => Interacao::DIRECOES,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ========================================================================= */
/* POST — registra                                                            */
/* ========================================================================= */

if ($metodo === 'POST') {
    conferirCsrf($input);

    $entidade = strtolower(trim((string) ($input['entidade'] ?? '')));
    $id       = (int) ($input['id'] ?? 0);
    if ($id <= 0 || !in_array($entidade, Entidade::TIPOS, true)) {
        falha('Informe entidade=cliente|card e id');
    }
    $alvo = alvoOu404($ctx, $entidade, $id);

    try {
        $novoId = Interacao::registrar($alvo, $input, $userId);
    } catch (\InvalidArgumentException $e) {
        falha($e->getMessage());
    }

    $modulo = $entidade === Entidade::CARD ? 'prospeccao' : 'clientes';

    echo json_encode([
        'success'    => true,
        'id'         => $novoId,
        'interacoes' => Interacao::listar($entidade, $id, $ctx->getAccessibleAccountIds($modulo)),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ========================================================================= */
/* PATCH — edita                                                              */
/* ========================================================================= */

if ($metodo === 'PATCH') {
    conferirCsrf($input);

    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        falha('id é obrigatório');
    }

    $contas = contasCrm($ctx);
    $atual  = Interacao::buscar($id, $contas);
    if ($atual === null) {
        falha('Registro não encontrado', 404);
    }
    // Confere posse pelo modulo da entidade dona, e nao pela uniao: quem tem
    // Clientes e nao tem Prospeccao nao edita interacao de card.
    alvoOu404($ctx, (string) $atual['entidade'], (int) $atual['entidade_id']);

    if (!Interacao::atualizar($id, $contas, $input, $userId)) {
        falha('Nada para alterar', 409);
    }

    $modulo = $atual['entidade'] === Entidade::CARD ? 'prospeccao' : 'clientes';

    echo json_encode([
        'success'    => true,
        'interacoes' => Interacao::listar(
            (string) $atual['entidade'],
            (int) $atual['entidade_id'],
            $ctx->getAccessibleAccountIds($modulo)
        ),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ========================================================================= */
/* DELETE                                                                     */
/* ========================================================================= */

if ($metodo === 'DELETE') {
    conferirCsrf($input);

    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        falha('id é obrigatório');
    }

    $contas = contasCrm($ctx);
    $atual  = Interacao::buscar($id, $contas);
    if ($atual === null) {
        falha('Registro não encontrado', 404);
    }
    alvoOu404($ctx, (string) $atual['entidade'], (int) $atual['entidade_id']);

    if (!Interacao::remover($id, $contas, $userId)) {
        falha('Registro já havia sido removido', 409);
    }

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

falha('Método não permitido', 405);
