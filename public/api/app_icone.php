<?php
/**
 * /api/app_icone.php?h=<sha1>&l=<180|192|512>: o ícone do app instalado no
 * celular, feito do ícone da marca da conta (22/10/2026).
 *
 * Mesma regra do marca_arquivo.php: SEM sessão (o celular busca o ícone fora
 * da página), e o que impede listar marcas é o endereço ser o hash do conteúdo.
 * A conversão (App\Core\IconeApp) leva meio segundo no PHP puro, então o
 * resultado fica guardado na pasta temporária e o navegador guarda por um ano.
 * Formato que o IconeApp não lê (JPEG, WebP, PNG de 16 bits) vai para o ícone
 * original.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Core\IconeApp;
use App\Master\Marca;

$h = (string) ($_GET['h'] ?? '');
$l = (int) ($_GET['l'] ?? 0);
if (!Marca::hashValido($h) || !in_array($l, IconeApp::LADOS, true)) {
    http_response_code(400);
    exit;
}

$etag = $h . '-' . $l;
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''), '"') === $etag) {
    http_response_code(304);
    exit;
}

$dir = sys_get_temp_dir() . '/yuris_icone_app';
$arquivo = $dir . '/' . $etag . '.png';
$png = is_file($arquivo) ? (string) @file_get_contents($arquivo) : '';

if ($png === '') {
    try {
        $st = Database::getConnection()->prepare(
            'SELECT mime, conteudo FROM account_marca_arquivos WHERE hash = ? LIMIT 1'
        );
        $st->execute([$h]);
        $arq = $st->fetch(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('[app_icone] ' . $e->getMessage());
        http_response_code(500);
        exit;
    }
    if (!$arq) {
        http_response_code(404);
        exit;
    }
    $png = $arq['mime'] === 'image/png' ? (string) IconeApp::gerar((string) $arq['conteudo'], $l) : '';
    if ($png === '') {
        header('Location: ' . Marca::urlArquivo($h), true, 302);
        exit;
    }
    if (is_dir($dir) || @mkdir($dir, 0775, true)) @file_put_contents($arquivo, $png, LOCK_EX);
}

header('Content-Type: image/png');
header('Content-Length: ' . strlen($png));
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: "' . $etag . '"');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
echo $png;
