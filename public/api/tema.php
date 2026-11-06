<?php
/**
 * GET /api/tema.php?t=escuro|claro&p=<tela>
 *
 * A paleta do Yuris como CSS: o escuro do leão e o claro da águia, gerados por
 * App\Core\PaletaTema a partir do CSS que a tela já carrega. Cada tela tem a sua
 * folha; só telas da lista PaletaTema::PAGINAS, nada de caminho vindo da URL.
 * Em prévia: o menu lateral só pede a folha no navegador de quem ligou.
 *
 * Público e sem sessão: é CSS, sem dado de ninguém. A folha gerada fica guardada
 * no diretório temporário; se não der para gravar, é gerada a cada pedido, e o
 * cache do navegador segura o resto.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\PaletaTema;

$tema = (string) ($_GET['t'] ?? '');
$pagina = (string) ($_GET['p'] ?? '');
header('Content-Type: text/css; charset=utf-8');
if (!in_array($tema, ['escuro', 'claro'], true) || !PaletaTema::conhece($pagina)) {
    http_response_code(404);
    echo "/* tema ou tela desconhecidos */\n";
    exit;
}

$publico = dirname(__DIR__);
$versao = PaletaTema::versao($publico, $pagina);
$etag = '"tema-' . $tema . '-' . $pagina . '-' . $versao . '"';
header('Cache-Control: public, max-age=86400');
header('ETag: ' . $etag);
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

$guardado = sys_get_temp_dir() . '/yuris-tema-' . $tema . '-' . $pagina . '-' . $versao . '-' . substr(md5($publico), 0, 8) . '.css';
$css = is_file($guardado) ? (string) @file_get_contents($guardado) : '';
if ($css === '') {
    $css = '/* paleta do Yuris, tema ' . $tema . ', tela ' . $pagina . ', gerada por App\\Core\\PaletaTema */' . "\n"
         . PaletaTema::gerar($publico, $pagina, $tema);
    // Grava num temporário e renomeia: pedido simultâneo nunca lê meia folha.
    $tmp = $guardado . '.' . getmypid();
    if (@file_put_contents($tmp, $css) !== false) { @rename($tmp, $guardado); }
}
echo $css;
