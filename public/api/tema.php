<?php
/**
 * GET /api/tema.php?t=escuro|claro
 *
 * A paleta do Yuris como CSS: o escuro do leão e o claro da águia, gerados por
 * App\Core\PaletaTema a partir do CSS que já existe. O menu lateral carrega as
 * duas folhas só nas contas Yuris, com ?v=<versão das origens>.
 *
 * Público e sem sessão: é CSS, sem dado de ninguém. A folha gerada fica guardada
 * no diretório temporário; se não der para gravar, é gerada a cada pedido, e o
 * cache do navegador segura o resto.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\PaletaTema;

$tema = (string) ($_GET['t'] ?? '');
header('Content-Type: text/css; charset=utf-8');
if (!in_array($tema, ['escuro', 'claro'], true)) {
    http_response_code(404);
    echo "/* tema desconhecido */\n";
    exit;
}

$publico = dirname(__DIR__);
$versao = PaletaTema::versao($publico);
$etag = '"tema-' . $tema . '-' . $versao . '"';
header('Cache-Control: public, max-age=86400');
header('ETag: ' . $etag);
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

$guardado = sys_get_temp_dir() . '/yuris-tema-' . $tema . '-' . $versao . '-' . substr(md5($publico), 0, 8) . '.css';
$css = is_file($guardado) ? (string) @file_get_contents($guardado) : '';
if ($css === '') {
    $css = '/* paleta do Yuris, tema ' . $tema . ', gerada por App\\Core\\PaletaTema */' . "\n" . PaletaTema::gerar($publico, $tema);
    // Grava num temporário e renomeia: pedido simultâneo nunca lê meia folha.
    $tmp = $guardado . '.' . getmypid();
    if (@file_put_contents($tmp, $css) !== false) { @rename($tmp, $guardado); }
}
echo $css;
