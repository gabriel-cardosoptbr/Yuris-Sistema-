<?php
/**
 * GET /api/tema_escuro.php?arquivo=ff-ficha
 *
 * Devolve, como CSS, a cópia ESCURA de uma folha da edição CRM, gerada na hora
 * por App\Master\TemaEscuroCrm a partir do arquivo claro. Assim o tema escuro
 * acompanha qualquer mudança no desenho claro, sem um segundo arquivo para
 * manter à mão. Só arquivos da lista abaixo; nada de caminho vindo da URL.
 *
 * Público e sem sessão: é CSS, igual ao arquivo de origem em /assets/. Pode
 * ficar em cache: o menu lateral pede com ?v=<data do arquivo>.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

$ARQUIVOS = [
    'ff-ficha' => __DIR__ . '/../assets/ff-ficha.css',
    'chat-numeros' => __DIR__ . '/../assets/chat-numeros.css',
];

$chave = (string) ($_GET['arquivo'] ?? '');
$caminho = $ARQUIVOS[$chave] ?? null;
if ($caminho === null || !is_file($caminho)) {
    http_response_code(404);
    header('Content-Type: text/css; charset=utf-8');
    echo "/* arquivo desconhecido */\n";
    exit;
}

// A versão muda quando o arquivo claro OU o conversor mudam.
$mtime = max((int) filemtime($caminho), (int) filemtime(__DIR__ . '/../../app/Master/TemaEscuroCrm.php'));
$etag = '"' . $chave . '-' . $mtime . '"';
header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: public, max-age=86400');
header('ETag: ' . $etag);
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
echo "/* tema escuro de assets/" . basename($caminho) . ", gerado por App\\Master\\TemaEscuroCrm */\n";
// true: a folha das fichas tem cores fixas que valem nos dois temas; elas também ganham cópia escura.
echo \App\Master\TemaEscuroCrm::converter((string) file_get_contents($caminho), true);
