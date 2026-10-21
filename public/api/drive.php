<?php
/**
 * Google Drive dos clientes (App\Drive), lado da equipe.
 *
 *   GET  ?estado=1                          conexão da conta (e o aviso da última tentativa de conectar)
 *   GET  ?cliente=N [&pasta=ID] [&forcar=1]  a pasta do cliente: itens, caminho, hora da leitura e erro
 *   GET  ?cliente=N&buscar=texto            pastas da conta Google, para escolher a do cliente (com a pasta-mãe)
 *   GET  ?cliente=N&navegar=[ID|root|shared] navegar no Drive para escolher a pasta: os lugares (vazio),
 *                                           Meu Drive, compartilhados comigo, ou as subpastas de uma pasta
 *   GET  ?cliente=N&acessos=1               quem da equipe tem e quem não tem acesso à pasta no Drive
 *   GET  ?cartao=N                          arquivos do Drive vinculados ao cartão
 *   GET  ?cliente=N&miniatura=ID [&w=480]   a miniatura do arquivo (imagem; 404 sem miniatura)
 *   GET  ?cliente=N&baixar=ID               baixa o arquivo (em fluxo; documento do Google vai para o formato do Office)
 *   GET  ?cliente=N&zip=1 [&pasta=ID] [&ids=a,b]  os arquivos da pasta (ou os escolhidos) num .zip em fluxo
 *   GET  ?cliente=N&compartilhamento=1 [&pasta=ID] o link da pasta e se está aberta para quem tiver o link
 *   GET  ?cliente=N&secoes=1                as seções do Drive da ficha (título e pasta)
 *   POST {acao:'envio_abrir', cliente_id, pasta_id?, nome, mime, tamanho}  sessão para o navegador enviar direto ao Google
 *   POST {acao:'envio_registrar', cliente_id, file_id}     depois do envio: confere a pasta e põe na lista
 *   POST {acao:'nova_pasta', cliente_id, pasta_id?, nome}
 *   POST {acao:'link_publico', cliente_id, pasta_id?, abrir}  administrador: abre ou fecha para quem tiver o link
 *   POST {acao:'secao_criar', cliente_id, titulo, pasta_id?} (sem pasta: cria uma com o título) /
 *        {acao:'secao_renomear', cliente_id, id, titulo} / {acao:'secao_remover', cliente_id, id} / {acao:'secao_ordem', cliente_id, ids}
 *   POST {acao:'vincular_pasta', cliente_id, referencia}   link ou id da pasta
 *   POST {acao:'desvincular_pasta', cliente_id}
 *   POST {acao:'dar_acesso', cliente_id, emails:[...]}      administrador
 *   POST {acao:'desconectar'}                               administrador
 *   POST {acao:'cartao_vincular', task_id, file_id} / {acao:'cartao_remover', task_id, id}
 *   POST multipart acao=enviar: cliente_id, pasta_id?, arquivo, task_id?, material_id?
 *        o arquivo vai para a pasta do cliente; com material, registra o recebimento
 *
 * Só na conta com ambientes. Cliente: da conta e, para vendedor, só o dele.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Ambientes\Ambiente;
use App\Core\AccountContext;
use App\Core\Database;
use App\Digital\Entrega;
use App\Digital\Material;
use App\Drive\ArquivoDoCartao;
use App\Drive\Conexao;
use App\Drive\DriveErro;
use App\Drive\Baixar;
use App\Drive\Pasta;
use App\Drive\Secao;

session_start();
$flash = $_SESSION['drive_flash'] ?? null;
if ($flash !== null && !empty($_GET['estado'])) unset($_SESSION['drive_flash']);
session_write_close();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function drResponder(array $corpo, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user_id'])) drResponder(['ok' => false, 'error' => 'Sessão expirada.'], 401);
$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();
$acc = (int) $ctx->getAccountId();
$uid = (int) $ctx->getUserId();
$admin = $ctx->isOwnerOrAdmin();
if (!Ambiente::habilitado(\App\Master\Account::findById($acc) ?? [])) drResponder(['ok' => false, 'error' => 'Ambientes não estão ligados nesta conta.'], 404);
if (!Ambiente::podePagina('clientes') && !Ambiente::podePagina('tarefas')) drResponder(['ok' => false, 'error' => 'Sem permissão.'], 403);
$vendedor = AccountContext::vendedorDaSessao();

/** Erro num download (a pessoa abriu um link, não um pedido da tela): uma página simples. */
function drErroDownload(string $msg, int $status = 400): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Não foi possível baixar</title><body style="font-family:system-ui,sans-serif;padding:32px;color:#1F2937">'
        . '<h2 style="margin:0 0 8px">Não foi possível baixar</h2><p>' . htmlspecialchars($msg) . '</p><p><a href="javascript:history.back()">Voltar</a></p></body>';
    exit;
}

/** Sem buffer nem compressão: cada pedaço que chega do Google segue na hora. */
function drPrepararFluxo(): void
{
    set_time_limit(0);
    ignore_user_abort(false);
    if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) ob_end_clean();
    header_remove('Content-Type');
}

function drCliente(int $acc, int $id, ?int $vendedor): array
{
    $sql = 'SELECT id, nome, responsavel_id FROM clientes WHERE id = ? AND account_id = ? AND deleted_at IS NULL';
    $args = [$id, $acc];
    if ($vendedor !== null) { $sql .= ' AND responsavel_id = ?'; $args[] = $vendedor; }
    $st = Database::getConnection()->prepare($sql);
    $st->execute($args);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) drResponder(['ok' => false, 'error' => 'Cliente não encontrado.'], 404);
    return $c;
}

/** Quem vê o cliente no sistema (administração, quem abre Clientes ou Tarefas, e o responsável). */
function drEquipe(int $acc, array $cliente): array
{
    $st = Database::getConnection()->prepare("SELECT u.id, u.nome, u.login AS email, u.perfil, u.role,
                                                     (SELECT GROUP_CONCAT(p.page) FROM user_permissions p WHERE p.user_id = u.id) AS perms
                                                FROM users u WHERE u.account_id = ? AND u.deleted_at IS NULL AND u.status = 'active' ORDER BY u.nome");
    $st->execute([$acc]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $perms = explode(',', (string) $u['perms']);
        $adm = strtolower((string) $u['perfil']) === 'admin' || in_array($u['role'], ['owner', 'admin'], true) || in_array('*', $perms, true);
        // Vendedor (não administrador) só vê o cliente de que é responsável.
        $ve = $adm || ((int) $cliente['responsavel_id'] === (int) $u['id'])
            || ((in_array('clientes', $perms, true) || in_array('tarefas', $perms, true)) && (int) $cliente['responsavel_id'] === 0);
        // O login é o e-mail da pessoa; sem e-mail válido não há como dar acesso no Drive.
        if ($ve) $out[] = ['id' => (int) $u['id'], 'nome' => $u['nome'], 'email' => filter_var($u['email'], FILTER_VALIDATE_EMAIL) ? (string) $u['email'] : ''];
    }
    return $out;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!empty($_GET['estado'])) drResponder(['ok' => true, 'data' => Conexao::estado($acc) + ['pode_conectar' => $admin, 'aviso' => $flash]]);
        if (!empty($_GET['cartao'])) {
            $c = Entrega::cartaoVisivel((int) $_GET['cartao'], $uid, $ctx->getAccessibleAccountIds('tarefas') ?: [$acc], $admin);
            drResponder(['ok' => true, 'data' => ArquivoDoCartao::doCartao((int) $c['task']['id'])]);
        }
        $cli = drCliente($acc, (int) ($_GET['cliente'] ?? 0), $vendedor);
        if (!empty($_GET['miniatura'])) {
            // Imagem, não JSON: só arquivo da árvore do cliente que a pessoa vê.
            $m = Pasta::miniatura($acc, (int) $cli['id'], (string) $_GET['miniatura'], (int) ($_GET['w'] ?? 480));
            if (!$m) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'sem miniatura'; exit; }
            header_remove('Cache-Control');
            header('Content-Type: ' . $m['mime']);
            header('Cache-Control: private, max-age=86400');
            header('X-Content-Type-Options: nosniff');
            echo $m['bytes'];
            exit;
        }
        $estado = Conexao::estado($acc);
        if (isset($_GET['buscar'])) {
            if (!$estado['conectado']) drResponder(['ok' => false, 'error' => 'O Google Drive não está conectado.'], 409);
            drResponder(['ok' => true, 'data' => Pasta::buscarPastas($acc, (string) $_GET['buscar'])]);
        }
        if (!empty($_GET['secoes'])) {
            drResponder(['ok' => true, 'data' => Secao::listar($acc, (int) $cli['id'])]);
        }
        if (!empty($_GET['compartilhamento'])) {
            if (!$estado['conectado']) drResponder(['ok' => false, 'error' => 'O Google Drive não está conectado.'], 409);
            drResponder(['ok' => true, 'data' => Pasta::compartilhamento($acc, (int) $cli['id'], !empty($_GET['pasta']) ? (string) $_GET['pasta'] : null), 'pode_mudar' => $admin]);
        }
        if (!empty($_GET['baixar']) || !empty($_GET['zip'])) {
            if (!$estado['conectado']) drErroDownload('O Google Drive não está conectado.', 409);
            try {
                if (!empty($_GET['baixar'])) {
                    $fileId = (string) $_GET['baixar'];
                    if (!Pasta::itemDoCliente($acc, (int) $cli['id'], $fileId)) drErroDownload('Arquivo fora da pasta do cliente.', 404);
                    drPrepararFluxo();
                    Baixar::arquivo($acc, (int) $cli['id'], $fileId, function (array $cab) {
                        foreach ($cab as $k => $v) header("$k: $v");
                        header('Cache-Control: private, no-store');
                        header('X-Content-Type-Options: nosniff');
                    }, function (string $b) { echo $b; flush(); });
                    exit;
                }
                $ids = !empty($_GET['ids']) ? array_values(array_filter(explode(',', (string) $_GET['ids']))) : null;
                $pastaId = !empty($_GET['pasta']) ? (string) $_GET['pasta'] : null;
                $plano = Baixar::planoZip($acc, (int) $cli['id'], $pastaId, $ids);
                $nomePasta = 'arquivos';
                $st = Database::getConnection()->prepare('SELECT nome FROM drive_itens WHERE account_id = ? AND cliente_id = ? AND file_id = ?');
                if ($pastaId) { $st->execute([$acc, (int) $cli['id'], $pastaId]); $nomePasta = (string) ($st->fetchColumn() ?: $nomePasta); }
                else $nomePasta = (string) (Pasta::daCliente($acc, (int) $cli['id'])['folder_nome'] ?? $nomePasta);
                drPrepararFluxo();
                header('Content-Type: application/zip');
                header('Content-Disposition: ' . Baixar::cabecalhoNome($nomePasta . ($ids ? ' (selecionados)' : '') . '.zip'));
                header('Cache-Control: private, no-store');
                Baixar::zip($acc, $plano, function (string $b) { echo $b; flush(); });
                exit;
            } catch (DriveErro | \InvalidArgumentException $e) {
                if (headers_sent()) { error_log('[api/drive download] ' . $e->getMessage()); exit; }
                drErroDownload($e->getMessage(), $e instanceof DriveErro ? 502 : 400);
            }
        }
        if (isset($_GET['navegar'])) {
            if (!$estado['conectado']) drResponder(['ok' => false, 'error' => 'O Google Drive não está conectado.'], 409);
            if ($vendedor !== null) drResponder(['ok' => false, 'error' => 'Só a administração liga a pasta do cliente.'], 403);
            drResponder(['ok' => true, 'data' => Pasta::navegar($acc, (string) $_GET['navegar'] ?: null)]);
        }
        if (!empty($_GET['acessos'])) {
            drResponder(['ok' => true, 'data' => Pasta::acessos($acc, (int) $cli['id'], drEquipe($acc, $cli)), 'pode_dar' => $admin]);
        }
        $pasta = Pasta::daCliente($acc, (int) $cli['id']);
        if (!$pasta || !$estado['conectado']) {
            drResponder(['ok' => true, 'data' => ['conexao' => $estado + ['pode_conectar' => $admin], 'pasta' => $pasta ? ['nome' => $pasta['folder_nome'], 'link' => $pasta['folder_link']] : null]]);
        }
        $lista = Pasta::listar($acc, (int) $cli['id'], isset($_GET['pasta']) ? (string) $_GET['pasta'] : null, !empty($_GET['forcar']));
        drResponder(['ok' => true, 'data' => ['conexao' => $estado + ['pode_conectar' => $admin]] + $lista]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') drResponder(['ok' => false, 'error' => 'Método não permitido.'], 405);
    $multipart = str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data');
    $in = $multipart ? $_POST : (json_decode(file_get_contents('php://input'), true) ?? []);
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['csrf_token'] ?? '');
    if (!$csrf || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) $csrf)) drResponder(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.'], 419);
    $acao = (string) ($in['acao'] ?? '');

    if ($acao === 'desconectar') {
        if (!$admin) drResponder(['ok' => false, 'error' => 'Só um administrador desconecta a conta Google.'], 403);
        Conexao::desconectar($acc);
        drResponder(['ok' => true]);
    }
    if ($acao === 'cartao_vincular' || $acao === 'cartao_remover') {
        $c = Entrega::cartaoEditavel((int) ($in['task_id'] ?? 0), $uid, $ctx->getAccessibleAccountIds('tarefas') ?: [$acc], $admin);
        if ($c['board']['cliente_id']) drCliente($acc, (int) $c['board']['cliente_id'], $vendedor);
        $lista = $acao === 'cartao_vincular' ? ArquivoDoCartao::vincular($c, $uid, (string) ($in['file_id'] ?? '')) : ArquivoDoCartao::remover($c, $uid, (int) ($in['id'] ?? 0));
        drResponder(['ok' => true, 'data' => $lista]);
    }

    $cli = drCliente($acc, (int) ($in['cliente_id'] ?? 0), $vendedor);
    if ($acao === 'vincular_pasta') {
        if ($vendedor !== null) drResponder(['ok' => false, 'error' => 'Só a administração liga a pasta do cliente.'], 403);
        $p = Pasta::vincular($acc, $uid, (int) $cli['id'], (string) ($in['referencia'] ?? ''));
        drResponder(['ok' => true, 'data' => ['nome' => $p['folder_nome'], 'link' => $p['folder_link']]]);
    }
    if ($acao === 'desvincular_pasta') {
        if ($vendedor !== null) drResponder(['ok' => false, 'error' => 'Só a administração muda a pasta do cliente.'], 403);
        Pasta::desvincular($acc, (int) $cli['id']);
        drResponder(['ok' => true]);
    }
    if ($acao === 'dar_acesso') {
        if (!$admin) drResponder(['ok' => false, 'error' => 'Só um administrador dá acesso no Drive.'], 403);
        // Só e-mail de quem vê o cliente no sistema: o Drive não vira porta para quem não deveria ver.
        $permitidos = array_map(fn($u) => strtolower($u['email']), drEquipe($acc, $cli));
        $emails = array_values(array_intersect(array_map('strtolower', (array) ($in['emails'] ?? [])), $permitidos));
        drResponder(['ok' => true, 'data' => Pasta::darAcesso($acc, (int) $cli['id'], $emails)]);
    }
    if ($acao === 'envio_abrir') {
        // A origem libera o CORS da sessão no Google: só o endereço deste sistema.
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $proto = (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || ($_SERVER['HTTPS'] ?? '') === 'on') ? 'https' : 'http';
        $origem = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origem === '' || strtolower((string) parse_url($origem, PHP_URL_HOST) . (parse_url($origem, PHP_URL_PORT) ? ':' . parse_url($origem, PHP_URL_PORT) : '')) !== strtolower($host)) $origem = $proto . '://' . $host;
        $url = Pasta::abrirEnvio($acc, (int) $cli['id'], !empty($in['pasta_id']) ? (string) $in['pasta_id'] : null, (string) ($in['nome'] ?? ''),
            (string) ($in['mime'] ?? ''), (int) ($in['tamanho'] ?? 0), $origem);
        drResponder(['ok' => true, 'url' => $url]);
    }
    if ($acao === 'envio_registrar') {
        drResponder(['ok' => true, 'data' => Pasta::registrarEnviado($acc, (int) $cli['id'], (string) ($in['file_id'] ?? ''))]);
    }
    if ($acao === 'nova_pasta') {
        drResponder(['ok' => true, 'data' => Pasta::novaPasta($acc, (int) $cli['id'], !empty($in['pasta_id']) ? (string) $in['pasta_id'] : null, (string) ($in['nome'] ?? ''))]);
    }
    if ($acao === 'link_publico') {
        if (!$admin) drResponder(['ok' => false, 'error' => 'Só um administrador muda o compartilhamento da pasta.'], 403);
        drResponder(['ok' => true, 'data' => Pasta::liberarLink($acc, (int) $cli['id'], !empty($in['pasta_id']) ? (string) $in['pasta_id'] : null, !empty($in['abrir']))]);
    }
    if (str_starts_with($acao, 'secao_')) {
        if ($vendedor !== null) drResponder(['ok' => false, 'error' => 'Só a administração organiza as seções do Drive.'], 403);
        $cid = (int) $cli['id'];
        $lista = match ($acao) {
            'secao_criar' => Secao::criar($acc, $uid, $cid, (string) ($in['titulo'] ?? ''), !empty($in['pasta_id']) ? (string) $in['pasta_id'] : null),
            'secao_renomear' => Secao::renomear($acc, $cid, (int) ($in['id'] ?? 0), (string) ($in['titulo'] ?? '')),
            'secao_remover' => Secao::remover($acc, $cid, (int) ($in['id'] ?? 0)),
            'secao_ordem' => Secao::ordenar($acc, $cid, (array) ($in['ids'] ?? [])),
            default => drResponder(['ok' => false, 'error' => 'Ação desconhecida.'], 400),
        };
        drResponder(['ok' => true, 'data' => $lista]);
    }
    if ($acao === 'enviar') {
        $f = $_FILES['arquivo'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            drResponder(['ok' => false, 'error' => ($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'O arquivo passa do limite de envio.' : 'Escolha o arquivo.'], 400);
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: 'application/octet-stream';
        // Com cartão e material: confere o cartão antes de mandar para o Drive.
        $cartao = null;
        if (!empty($in['task_id'])) {
            $cartao = Entrega::cartaoEditavel((int) $in['task_id'], $uid, $ctx->getAccessibleAccountIds('tarefas') ?: [$acc], $admin);
            if ((int) ($cartao['board']['cliente_id'] ?? 0) !== (int) $cli['id']) drResponder(['ok' => false, 'error' => 'O cartão é de outro cliente.'], 400);
        }
        $item = Pasta::enviar($acc, (int) $cli['id'], !empty($in['pasta_id']) ? (string) $in['pasta_id'] : null, (string) $f['name'], (string) file_get_contents($f['tmp_name']), $mime);
        if ($cartao && !empty($in['material_id'])) {
            Material::receber($cartao, $uid, (int) $in['material_id'], ['id' => $item['id'], 'nome' => $item['nome'], 'link' => $item['link']]);
        }
        drResponder(['ok' => true, 'data' => $item]);
    }
    drResponder(['ok' => false, 'error' => 'Ação desconhecida.'], 400);
} catch (DriveErro $e) {
    drResponder(['ok' => false, 'error' => $e->getMessage(), 'motivo' => $e->motivo], 502);
} catch (\DomainException $e) {
    drResponder(['ok' => false, 'error' => $e->getMessage()], 404);
} catch (\InvalidArgumentException $e) {
    drResponder(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (\Throwable $e) {
    error_log('[api/drive] ' . $e->getMessage());
    drResponder(['ok' => false, 'error' => 'Não foi possível concluir agora.'], 500);
}
