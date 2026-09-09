<?php

/**
 * /api/crm_tags.php — etiquetas e classificacoes (bloco B da Fase 2).
 *
 * GET    ?catalogo=1[&inativas=1]              catalogo das contas acessiveis
 * GET    ?entidade=cliente|card&id=N           etiquetas aplicadas + catalogo
 * POST   {acao:'criar', nome, cor?}            cria no catalogo (exige permissao)
 * POST   {acao:'aplicar', entidade, id, tag_id | nome}
 * POST   {acao:'renomear', tag_id, nome?, cor?}     (exige permissao)
 * DELETE {entidade, id, tag_id}                tira a etiqueta da ficha
 * DELETE {tag_id, arquivar:true}               arquiva no catalogo (exige permissao)
 *
 * ---------------------------------------------------------------------------
 * "DIGITE E CRIE" NUM POST SO
 * ---------------------------------------------------------------------------
 * A acao 'aplicar' aceita `nome` em vez de `tag_id`. Quem digita uma etiqueta
 * que ainda nao existe cria e aplica na mesma ida, sem duas chamadas e sem uma
 * tela separada de catalogo no meio do caminho. Criar por esse atalho tambem
 * exige a permissao de catalogo: senao ela seria contornavel por quem sabe que
 * 'aplicar' aceita nome.
 *
 * ---------------------------------------------------------------------------
 * A CONTA DA ETIQUETA E A DA ENTIDADE TEM DE SER A MESMA
 * ---------------------------------------------------------------------------
 * Uma sessao matriz alcanca as filiais. Sem conferir, ela aplicaria etiqueta da
 * matriz num card da filial: as duas checagens de acesso passam isoladamente e
 * o vinculo sairia cruzado. A conferencia esta em App\Crm\Tag::aplicar, que e o
 * unico caminho de escrita.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Crm\Entidade;
use App\Crm\Permissao;
use App\Crm\Tag;

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

/** Todas as contas que a sessao alcanca nos dois modulos. */
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
        falha('Você não tem permissão para gerenciar as etiquetas do escritório.', 403, ['code' => 'sem_permissao']);
    }
}

/* ========================================================================= */
/* GET                                                                        */
/* ========================================================================= */

if ($metodo === 'GET') {
    $contas = contasCrm($ctx);

    if (!empty($_GET['catalogo'])) {
        echo json_encode([
            'success'         => true,
            'catalogo'        => Tag::catalogo($contas, !empty($_GET['inativas'])),
            'cores'           => Tag::CORES,
            'pode_gerenciar'  => Permissao::podeGerenciarCatalogos(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $entidade = strtolower(trim((string) ($_GET['entidade'] ?? '')));
    $id       = (int) ($_GET['id'] ?? 0);
    if ($id <= 0 || !in_array($entidade, Entidade::TIPOS, true)) {
        falha('Informe entidade=cliente|card e id, ou catalogo=1');
    }

    $alvo   = alvoOu404($ctx, $entidade, $id);
    $modulo = $entidade === Entidade::CARD ? 'prospeccao' : 'clientes';

    /*
     * O catalogo devolvido e SO o da conta dona da entidade, nao o de todas as
     * acessiveis. A tela usa esta lista para montar o seletor, e oferecer
     * etiqueta de outra conta seria oferecer o que aplicar() vai recusar.
     */
    echo json_encode([
        'success'        => true,
        'tags'           => Tag::daEntidade($entidade, $id, $ctx->getAccessibleAccountIds($modulo)),
        'catalogo'       => Tag::catalogo([$alvo['account_id']]),
        'cores'          => Tag::CORES,
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

    if ($acao === 'criar') {
        exigirPermissaoCatalogo();
        $nome = trim((string) ($input['nome'] ?? ''));
        if ($nome === '') {
            falha('Informe o nome da etiqueta');
        }
        // Sem entidade no payload, cria na conta da sessao.
        try {
            $tag = Tag::criar($ctx->getAccountId(), $nome, $input['cor'] ?? null, $userId);
        } catch (\InvalidArgumentException $e) {
            falha($e->getMessage());
        }
        echo json_encode(['success' => true, 'tag' => $tag], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($acao === 'renomear') {
        exigirPermissaoCatalogo();
        $tagId = (int) ($input['tag_id'] ?? 0);
        if ($tagId <= 0) {
            falha('tag_id é obrigatório');
        }
        $ok = Tag::atualizar(
            $tagId,
            contasCrm($ctx),
            isset($input['nome']) ? (string) $input['nome'] : null,
            isset($input['cor']) ? (string) $input['cor'] : null
        );
        if (!$ok) {
            falha('Etiqueta não encontrada ou nada para alterar', 404);
        }
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($acao === 'aplicar') {
        $entidade = strtolower(trim((string) ($input['entidade'] ?? '')));
        $id       = (int) ($input['id'] ?? 0);
        if ($id <= 0 || !in_array($entidade, Entidade::TIPOS, true)) {
            falha('Informe entidade=cliente|card e id');
        }
        $alvo = alvoOu404($ctx, $entidade, $id);

        $tagId = (int) ($input['tag_id'] ?? 0);
        $nome  = trim((string) ($input['nome'] ?? ''));

        if ($tagId <= 0 && $nome !== '') {
            // Criar pelo atalho tambem e gerenciar catalogo.
            exigirPermissaoCatalogo();
            try {
                // Cria na conta DA ENTIDADE, nao na da sessao: uma sessao matriz
                // etiquetando um card da filial cria a etiqueta na filial, senao
                // aplicar() recusaria logo depois por conta diferente.
                $tag = Tag::criar($alvo['account_id'], $nome, $input['cor'] ?? null, $userId);
            } catch (\InvalidArgumentException $e) {
                falha($e->getMessage());
            }
            $tagId = (int) $tag['id'];
        }
        if ($tagId <= 0) {
            falha('Informe tag_id ou nome');
        }

        $modulo = $entidade === Entidade::CARD ? 'prospeccao' : 'clientes';
        $contas = $ctx->getAccessibleAccountIds($modulo);

        $aplicou = Tag::aplicar($alvo, $tagId, $contas, $userId);

        // false pode ser "ja estava" ou "conta nao bate". A lista devolvida
        // resolve a ambiguidade para a tela: se a etiqueta esta la, ja estava.
        echo json_encode([
            'success' => true,
            'aplicou' => $aplicou,
            'tags'    => Tag::daEntidade($entidade, $id, $contas),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    falha('Ação desconhecida');
}

/* ========================================================================= */
/* DELETE                                                                     */
/* ========================================================================= */

if ($metodo === 'DELETE') {
    conferirCsrf($input);

    $tagId = (int) ($input['tag_id'] ?? 0);
    if ($tagId <= 0) {
        falha('tag_id é obrigatório');
    }

    // Arquivar no catalogo e outra coisa que tirar da ficha.
    if (!empty($input['arquivar'])) {
        exigirPermissaoCatalogo();
        if (!Tag::arquivar($tagId, contasCrm($ctx))) {
            falha('Etiqueta não encontrada', 404);
        }
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $entidade = strtolower(trim((string) ($input['entidade'] ?? '')));
    $id       = (int) ($input['id'] ?? 0);
    if ($id <= 0 || !in_array($entidade, Entidade::TIPOS, true)) {
        falha('Informe entidade=cliente|card e id');
    }
    $alvo   = alvoOu404($ctx, $entidade, $id);
    $modulo = $entidade === Entidade::CARD ? 'prospeccao' : 'clientes';
    $contas = $ctx->getAccessibleAccountIds($modulo);

    if (!Tag::desaplicar($alvo, $tagId, $contas, $userId)) {
        falha('Etiqueta não estava aplicada', 404);
    }

    echo json_encode([
        'success' => true,
        'tags'    => Tag::daEntidade($entidade, $id, $contas),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

falha('Método não permitido', 405);
