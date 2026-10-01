<?php
/**
 * /api/marca_arquivo.php?h=<sha1>: o logo ou o ícone da marca de uma conta.
 *
 * SEM sessão de propósito: o logo aparece na tela de login, antes de alguém
 * entrar. O que impede listar logos de todas as contas é o endereço ser o hash
 * do conteúdo (não o id da conta): só quem já viu a página sabe o endereço.
 *
 * Só serve PNG, JPEG e WebP (validados na gravação por App\Master\Marca), com
 * `nosniff` e CSP fechada, para o navegador nunca tratar o arquivo como página.
 * O conteúdo de um hash nunca muda, então o cache é de um ano.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Master\Marca;

$h = (string) ($_GET['h'] ?? '');
if (!Marca::hashValido($h)) {
    http_response_code(400);
    exit;
}

try {
    $st = Database::getConnection()->prepare(
        'SELECT mime, conteudo FROM account_marca_arquivos WHERE hash = ? LIMIT 1'
    );
    $st->execute([$h]);
    $arq = $st->fetch(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    error_log('[marca_arquivo] ' . $e->getMessage());
    http_response_code(500);
    exit;
}

if (!$arq || !in_array($arq['mime'], Marca::MIMES, true)) {
    http_response_code(404);
    exit;
}

if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''), '"') === $h) {
    http_response_code(304);
    exit;
}

header('Content-Type: ' . $arq['mime']);
header('Content-Length: ' . strlen($arq['conteudo']));
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: "' . $h . '"');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
echo $arq['conteudo'];
