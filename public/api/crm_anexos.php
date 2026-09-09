<?php

/**
 * /api/crm_anexos.php — documentos de cliente e de prospeccao (bloco A da Fase 2).
 *
 * GET    ?entidade=cliente|card&id=N        lista (para cliente inclui os das prospeccoes)
 * GET    ?action=download&id=N              baixa um anexo
 * POST   multipart: entidade, id, file[, descricao]
 * DELETE {id}
 *
 * ---------------------------------------------------------------------------
 * AS DEFESAS SAO AS MESMAS DO /api/task_attachments.php, DE PROPOSITO
 * ---------------------------------------------------------------------------
 * Aquele endpoint passou por auditoria LGPD e fechou dois furos reais: anexo
 * baixavel por URL publica previsivel (P0) e SVG servido inline com <script>
 * dentro (P1). Reescrever a protecao aqui de outro jeito seria abrir os mesmos
 * dois de novo. Entao vale exatamente o mesmo conjunto:
 *
 *   1. MIME conferido por finfo_file, no CONTEUDO, nunca pelo que o navegador
 *      declarou. Whitelist em App\Crm\Anexo::MIMES_PERMITIDOS, sem SVG.
 *   2. Nome do arquivo no disco com prefixo de random_bytes(16). uniqid() e
 *      microtime, e microtime e adivinhavel.
 *   3. Download SO por aqui, com Content-Disposition: attachment e nosniff.
 *      O .htaccess "Require all denied" de public/uploads/ ja cobre a subpasta
 *      nova (ele vale para o diretorio inteiro), e ele nega tambem execucao de
 *      .php/.phtml dentro dela, contra webshell renomeado.
 *   4. realpath conferido contra a raiz de uploads antes de ler: sem isso um
 *      file_path com ".." serviria qualquer arquivo do servidor.
 *
 * ---------------------------------------------------------------------------
 * A ORDEM DAS OPERACOES NA REMOCAO
 * ---------------------------------------------------------------------------
 * Marca a linha primeiro, apaga o arquivo depois, e so se a marcacao pegou.
 * Ao contrario, um UPDATE que falhasse deixaria a listagem mostrando documento
 * que nao existe mais no disco.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Crm\Anexo;
use App\Crm\Entidade;

$metodo = $_SERVER['REQUEST_METHOD'];

// GET so le a sessao. Escrita precisa dela aberta para conferir o CSRF.
if ($metodo === 'GET') {
    session_start(['read_and_close' => true]);
} else {
    session_start();
}

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();

$userId = $ctx->getUserId();
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

function falha(string $msg, int $codigo = 400): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Resolve a entidade e confere posse. Devolve o alvo, ou 404.
 *
 * O 404 e igual para "nao existe", "esta apagada" e "nao e da sua conta": ver
 * App\Crm\Entidade. Distinguir os tres ja seria dizer que o id existe em outra
 * conta.
 */
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
    if (($_GET['action'] ?? '') === 'download') {
        $anexoId = (int) ($_GET['id'] ?? 0);
        if ($anexoId <= 0) {
            falha('id é obrigatório');
        }

        /*
         * As duas contas de uma vez: o anexo pode estar num card (modulo
         * prospeccao) ou num cliente (modulo clientes), e neste ponto ainda nao
         * se sabe qual. A posse fina vem logo abaixo, pelo alvo.
         */
        $contas = array_values(array_unique(array_merge(
            $ctx->getAccessibleAccountIds('clientes'),
            $ctx->getAccessibleAccountIds('prospeccao')
        )));

        $anexo = Anexo::buscar($anexoId, $contas);
        if ($anexo === null) {
            falha('Anexo não encontrado', 404);
        }

        // Confere a entidade dona pelo modulo certo. Um usuario com acesso a
        // Clientes e sem acesso a Prospeccao nao baixa anexo de card.
        alvoOu404($ctx, (string) $anexo['entidade'], (int) $anexo['entidade_id']);

        $caminho    = realpath(__DIR__ . '/..' . $anexo['file_path']);
        $raizUpload = realpath(__DIR__ . '/../uploads');
        if (!$caminho || !$raizUpload || strncmp($caminho, $raizUpload, strlen($raizUpload)) !== 0) {
            falha('Caminho inválido', 404);
        }
        if (!is_file($caminho)) {
            falha('Arquivo não encontrado no servidor', 404);
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header_remove('Cache-Control');
        header('Content-Type: ' . ($anexo['mime_type'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . addslashes((string) ($anexo['file_name'] ?: 'anexo')) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Content-Length: ' . filesize($caminho));
        readfile($caminho);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');

    $entidade = strtolower(trim((string) ($_GET['entidade'] ?? '')));
    $id       = (int) ($_GET['id'] ?? 0);
    if ($id <= 0 || !in_array($entidade, Entidade::TIPOS, true)) {
        falha('Informe entidade=cliente|card e id');
    }

    $modulo = $entidade === Entidade::CARD ? 'prospeccao' : 'clientes';
    alvoOu404($ctx, $entidade, $id);

    echo json_encode([
        'success' => true,
        'anexos'  => Anexo::listar($entidade, $id, $ctx->getAccessibleAccountIds($modulo)),
        'limites' => [
            'tamanho_maximo' => Anexo::TAMANHO_MAXIMO,
            'mimes'          => Anexo::MIMES_PERMITIDOS,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ========================================================================= */
/* POST — sobe arquivo                                                        */
/* ========================================================================= */

if ($metodo === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    conferirCsrf($input);

    $entidade = strtolower(trim((string) ($_POST['entidade'] ?? '')));
    $id       = (int) ($_POST['id'] ?? 0);
    if ($id <= 0 || !in_array($entidade, Entidade::TIPOS, true)) {
        falha('Informe entidade=cliente|card e id');
    }
    $alvo = alvoOu404($ctx, $entidade, $id);

    if (empty($_FILES['file'])) {
        falha('Arquivo é obrigatório');
    }
    $arquivo = $_FILES['file'];
    if ($arquivo['error'] !== UPLOAD_ERR_OK) {
        // UPLOAD_ERR_INI_SIZE e UPLOAD_ERR_FORM_SIZE tem mensagem propria: o
        // usuario precisa saber que o problema e tamanho, nao "erro no upload".
        $msg = in_array($arquivo['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'Arquivo maior que o limite do servidor'
            : 'Erro no upload';
        falha($msg);
    }
    if ((int) $arquivo['size'] > Anexo::TAMANHO_MAXIMO) {
        falha('Arquivo maior que ' . (int) (Anexo::TAMANHO_MAXIMO / 1048576) . ' MB');
    }

    // MIME pelo CONTEUDO. O que o navegador manda em $_FILES['type'] e sugestao
    // do cliente e pode ser qualquer coisa.
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $arquivo['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, Anexo::MIMES_PERMITIDOS, true)) {
        falha('Tipo de arquivo não permitido');
    }

    $dir = __DIR__ . '/../uploads/crm/' . $entidade . '/' . $id . '/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        falha('Falha ao preparar a pasta do anexo', 500);
    }

    $nomeSeguro = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) $arquivo['name']);
    $destino    = $dir . bin2hex(random_bytes(16)) . '_' . $nomeSeguro;
    if (!move_uploaded_file($arquivo['tmp_name'], $destino)) {
        falha('Falha ao salvar arquivo', 500);
    }

    $descricao = trim((string) ($_POST['descricao'] ?? ''));

    $anexoId = Anexo::registrar(
        $alvo,
        str_replace(__DIR__ . '/..', '', $destino),
        (string) $arquivo['name'],
        $mime,
        (int) $arquivo['size'],
        $descricao === '' ? null : mb_substr($descricao, 0, 255),
        $userId
    );

    echo json_encode([
        'success'      => true,
        'id'           => $anexoId,
        'download_url' => '/api/crm_anexos.php?action=download&id=' . $anexoId,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ========================================================================= */
/* DELETE                                                                     */
/* ========================================================================= */

if ($metodo === 'DELETE') {
    header('Content-Type: application/json; charset=utf-8');
    conferirCsrf($input);

    $anexoId = (int) ($input['id'] ?? 0);
    if ($anexoId <= 0) {
        falha('id é obrigatório');
    }

    $contas = array_values(array_unique(array_merge(
        $ctx->getAccessibleAccountIds('clientes'),
        $ctx->getAccessibleAccountIds('prospeccao')
    )));

    $anexo = Anexo::buscar($anexoId, $contas);
    if ($anexo === null) {
        falha('Anexo não encontrado', 404);
    }
    $alvo = alvoOu404($ctx, (string) $anexo['entidade'], (int) $anexo['entidade_id']);

    if (!Anexo::remover($anexoId, $alvo, $userId, (string) $anexo['file_name'])) {
        falha('Anexo já havia sido removido', 409);
    }

    // Só agora o arquivo sai do disco. Se a linha nao tivesse sido marcada, ele
    // continuaria la, o que e recuperavel; o contrario nao e.
    $caminho    = realpath(__DIR__ . '/..' . $anexo['file_path']);
    $raizUpload = realpath(__DIR__ . '/../uploads');
    if ($caminho && $raizUpload && strncmp($caminho, $raizUpload, strlen($raizUpload)) === 0 && is_file($caminho)) {
        @unlink($caminho);
    }

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

falha('Método não permitido', 405);
