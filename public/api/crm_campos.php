<?php

/**
 * /api/crm_campos.php — campos personalizados (bloco C da Fase 2).
 *
 * GET    ?definicoes=1[&para=cliente|card][&inativas=1]   catalogo de definicoes
 * GET    ?entidade=cliente|card&id=N                      definicoes + valor da ficha
 * POST   {acao:'definir', rotulo, tipo, aplica_em?, opcoes?, obrigatorio?}   (exige permissao)
 * POST   {acao:'ajustar', campo_id, ...}                                     (exige permissao)
 * POST   {acao:'salvar', entidade, id, valores:{chave: valor}}
 * DELETE {campo_id}                                       arquiva a definicao  (exige permissao)
 *
 * ---------------------------------------------------------------------------
 * DUAS PERMISSOES DIFERENTES NO MESMO ARQUIVO
 * ---------------------------------------------------------------------------
 * 'salvar' e uso da ficha: quem abre a tela preenche. 'definir', 'ajustar' e a
 * remocao mudam a configuracao da conta e exigem crm.catalogos_gerenciar.
 * Misturar as duas numa permissao so obrigaria a escolher entre proibir todo
 * mundo de preencher ou deixar qualquer um redesenhar o cadastro.
 *
 * ---------------------------------------------------------------------------
 * O QUE 'salvar' DEVOLVE, E POR QUE
 * ---------------------------------------------------------------------------
 * Devolve `gravados` e `erros` juntos, e 200 mesmo com erro parcial. Uma ficha
 * com oito campos onde um veio com data invalida grava os outros sete e diz qual
 * falhou. Recusar o lote inteiro por causa de um campo faria a pessoa perder o
 * que digitou nos outros, e nao ha nada de transacional em jogo aqui: campo
 * personalizado nao tem invariante entre campos.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Crm\CampoPersonalizado;
use App\Crm\Entidade;
use App\Crm\Permissao;

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

function falha(string $msg, int $codigo = 400, array $extra = []): void
{
    http_response_code($codigo);
    echo json_encode(array_merge(['success' => false, 'error' => $msg], $extra), JSON_UNESCAPED_UNICODE);
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

function exigirPermissaoCatalogo(): void
{
    if (!Permissao::podeGerenciarCatalogos()) {
        falha('Você não tem permissão para criar ou alterar campos personalizados.', 403, ['code' => 'sem_permissao']);
    }
}

/* ========================================================================= */
/* GET                                                                        */
/* ========================================================================= */

if ($metodo === 'GET') {
    if (!empty($_GET['definicoes'])) {
        $para = strtolower(trim((string) ($_GET['para'] ?? '')));
        echo json_encode([
            'success'        => true,
            'definicoes'     => CampoPersonalizado::definicoes(
                contasCrm($ctx),
                in_array($para, Entidade::TIPOS, true) ? $para : null,
                !empty($_GET['inativas'])
            ),
            'tipos'          => CampoPersonalizado::TIPOS,
            'aplica_em'      => CampoPersonalizado::APLICA_EM,
            'pode_gerenciar' => Permissao::podeGerenciarCatalogos(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $entidade = strtolower(trim((string) ($_GET['entidade'] ?? '')));
    $id       = (int) ($_GET['id'] ?? 0);
    if ($id <= 0 || !in_array($entidade, Entidade::TIPOS, true)) {
        falha('Informe entidade=cliente|card e id, ou definicoes=1');
    }

    $alvo   = alvoOu404($ctx, $entidade, $id);
    $modulo = $entidade === Entidade::CARD ? 'prospeccao' : 'clientes';

    echo json_encode([
        'success'        => true,
        'campos'         => CampoPersonalizado::valores($entidade, $id, $ctx->getAccessibleAccountIds($modulo)),
        'pode_gerenciar' => Permissao::podeGerenciarCatalogos(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ========================================================================= */
/* POST                                                                       */
/* ========================================================================= */

if ($metodo === 'POST') {
    conferirCsrf($input);
    $acao = (string) ($input['acao'] ?? '');

    if ($acao === 'definir') {
        exigirPermissaoCatalogo();
        try {
            $def = CampoPersonalizado::criarDefinicao(
                $ctx->getAccountId(),
                (string) ($input['rotulo'] ?? ''),
                (string) ($input['tipo'] ?? 'texto'),
                (string) ($input['aplica_em'] ?? 'ambos'),
                is_array($input['opcoes'] ?? null) ? $input['opcoes'] : [],
                !empty($input['obrigatorio']),
                $userId
            );
        } catch (\InvalidArgumentException $e) {
            falha($e->getMessage());
        }
        echo json_encode(['success' => true, 'campo' => $def], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($acao === 'ajustar') {
        exigirPermissaoCatalogo();
        $campoId = (int) ($input['campo_id'] ?? 0);
        if ($campoId <= 0) {
            falha('campo_id é obrigatório');
        }
        try {
            $ok = CampoPersonalizado::atualizarDefinicao($campoId, contasCrm($ctx), $input);
        } catch (\InvalidArgumentException $e) {
            falha($e->getMessage());
        }
        if (!$ok) {
            falha('Campo não encontrado ou nada para alterar', 404);
        }
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($acao === 'salvar') {
        $entidade = strtolower(trim((string) ($input['entidade'] ?? '')));
        $id       = (int) ($input['id'] ?? 0);
        if ($id <= 0 || !in_array($entidade, Entidade::TIPOS, true)) {
            falha('Informe entidade=cliente|card e id');
        }
        $valores = $input['valores'] ?? null;
        if (!is_array($valores)) {
            falha('Informe valores como objeto {chave: valor}');
        }

        $alvo   = alvoOu404($ctx, $entidade, $id);
        $modulo = $entidade === Entidade::CARD ? 'prospeccao' : 'clientes';
        $contas = $ctx->getAccessibleAccountIds($modulo);

        $r = CampoPersonalizado::salvarValores($alvo, $valores, $contas, $userId);

        echo json_encode([
            'success'  => true,
            'gravados' => $r['gravados'],
            'erros'    => $r['erros'],
            'campos'   => CampoPersonalizado::valores($entidade, $id, $contas),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    falha('Ação desconhecida');
}

/* ========================================================================= */
/* DELETE — arquiva a definicao. Os valores gravados ficam.                   */
/* ========================================================================= */

if ($metodo === 'DELETE') {
    conferirCsrf($input);
    exigirPermissaoCatalogo();

    $campoId = (int) ($input['campo_id'] ?? 0);
    if ($campoId <= 0) {
        falha('campo_id é obrigatório');
    }
    if (!CampoPersonalizado::arquivarDefinicao($campoId, contasCrm($ctx))) {
        falha('Campo não encontrado', 404);
    }
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

falha('Método não permitido', 405);
