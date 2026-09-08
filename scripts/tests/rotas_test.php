<?php
/**
 * rotas_test.php — invariantes da camada de rota de public/ (debito D3).
 *
 * FAIL (exit != 0) = a camada de rota deixou de valer uma garantia que o
 *                    sistema em producao depende.
 *
 * Cobre, em ordem de gravidade:
 *   1. o gatilho (.htaccess) ainda so manda pro PHP o que nao existe no disco;
 *   2. o require acontece em ESCOPO GLOBAL (a regressao que apagou o icone do
 *      WhatsApp da home inteira);
 *   3. nada de public/includes, public/uploads ou traversal sai pela rota;
 *   4. toda pagina de public/ continua alcancavel pelo endereco limpo;
 *   5. toda chave declarada em config/rotas.php aponta para arquivo real.
 *
 * Uso local: C:\xampp\php\php.exe scripts/tests/rotas_test.php
 * Uso prod:  docker exec yuris_app php /var/www/html/scripts/tests/rotas_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Router;

$ROOT    = str_replace('\\', '/', dirname(__DIR__, 2));
$PUBLICO = $ROOT . '/public';

$FAILS = 0; $PASSES = 0;
function pass(string $m): void { global $PASSES; $PASSES++; echo "  [PASS] $m\n"; }
function fail(string $m): void { global $FAILS;  $FAILS++;  echo "  [FAIL] $m\n"; }
function secao(string $t): void { echo "\n== $t ==\n"; }

/* ===================================================================== */
secao('1. O gatilho: .htaccess so desvia o que NAO existe no disco');
/* ===================================================================== */

$ht = $PUBLICO . '/.htaccess';
if (!is_file($ht)) {
    fail('public/.htaccess nao existe — a camada de rota esta desligada');
} else {
    $txt = file_get_contents($ht);
    // Estas duas condicoes sao a razao de o raio de acao ser pequeno: sem elas,
    // TODA requisicao passaria pelo front controller, inclusive as 215 paginas
    // e endpoints que hoje o Apache serve direto.
    foreach (['!-f' => 'arquivo existente', '!-d' => 'diretorio existente'] as $cond => $oque) {
        if (preg_match('/RewriteCond\s+%\{REQUEST_FILENAME\}\s+' . preg_quote($cond, '/') . '/', $txt)) {
            pass("preserva $oque (RewriteCond $cond)");
        } else {
            fail("PERDEU a guarda $cond — $oque passaria a ser roteado pelo PHP");
        }
    }
    if (preg_match('/RewriteRule\s+\S+\s+index\.php/', $txt)) {
        pass('fallback aponta para index.php');
    } else {
        fail('fallback nao aponta para index.php');
    }
    if (str_contains($txt, 'RewriteRule . /index.php')) {
        fail('alvo absoluto (/index.php) quebra a porta 80 do XAMPP; use alvo relativo');
    } else {
        pass('alvo do rewrite e relativo (serve nos dois DocumentRoots)');
    }
}

/* ===================================================================== */
secao('2. O require tem de acontecer em escopo global');
/* ===================================================================== */

/*
 * Regressao real, pega na varredura diferencial de 08/09/2026: a primeira
 * versao do Router fazia `require $rota['arquivo']` DENTRO de um metodo
 * estatico. As variaveis de topo do arquivo incluido viravam locais do metodo,
 * e v2/partials/_render.php, que faz `global $waSvg`, passou a renderizar
 * vazio: a home perdeu o icone do WhatsApp em TODOS os botoes de CTA.
 *
 * O contrato que impede isso de voltar: resolveRequisicao() DEVOLVE o caminho,
 * e quem inclui e public/index.php, no topo da pilha.
 */
$alvo = Router::resolveRequisicao('/');
if (is_string($alvo) && is_file($alvo)) {
    pass('resolveRequisicao() devolve o caminho em vez de incluir');
} else {
    fail('resolveRequisicao() nao devolve caminho de arquivo — o include voltou pra dentro do Router');
}

$fonteRouter = file_get_contents($ROOT . '/app/Core/Router.php');
if (preg_match('/^\s*(require|include)(_once)?\s+\$/m', $fonteRouter)) {
    fail('Router.php voltou a incluir a pagina por conta propria (escopo de metodo)');
} else {
    pass('Router.php nao inclui pagina de rota por conta propria');
}

$fonteIndex = file_get_contents($PUBLICO . '/index.php');
// Escopo global nao e questao de indentacao (o require mora dentro de um if):
// e nao haver funcao, metodo ou classe no arquivo. Sem eles, qualquer require
// daqui executa no topo da pilha, e `global $x` do include enxerga o que deve.
if (!preg_match('/\b(function|class|trait)\s/', $fonteIndex)) {
    pass('public/index.php nao declara funcao/classe: todo require dele e global');
} else {
    fail('public/index.php passou a declarar funcao/classe — o require pode ter saido do escopo global');
}
if (preg_match('/require\s+\$\w+\s*;/', $fonteIndex)) {
    pass('public/index.php inclui o arquivo devolvido pelo Router');
} else {
    fail('public/index.php nao inclui o arquivo devolvido pelo Router');
}

/* ===================================================================== */
secao('3. Nada indevido sai pela rota');
/* ===================================================================== */

$hostis = [
    '/../.env'                      => 'traversal para o .env',
    '/..%2F..%2Fconfig/database.php' => 'traversal codificado',
    '/includes/sidebar.php'         => 'partial interno',
    '/includes/legal_page.php'      => 'partial interno',
    '/uploads/qualquer.pdf'         => 'anexo de cliente',
    "/dashboard.php\0.txt"          => 'byte nulo',
];
foreach ($hostis as $uri => $oque) {
    if (Router::resolve($uri) === null) {
        pass("recusa $oque");
    } else {
        fail("SERVIU $oque via $uri");
    }
}

// Recursao: /index nao pode resolver para o proprio front controller.
$r = Router::resolve('/index');
if ($r !== null && str_replace('\\', '/', $r['arquivo']) === $PUBLICO . '/index.php') {
    fail('/index resolve para o proprio front controller (recursao infinita)');
} else {
    pass('/index nao reentra no front controller');
}

/* ===================================================================== */
secao('4. Toda pagina de public/ continua alcancavel pelo endereco limpo');
/* ===================================================================== */

$pulaPasta = ['includes', 'uploads', 'assets', 'sistema_vendas', 'ai', 'api', 'v2'];
$pulaArq   = ['index.php', 'index-v1-legacy.php'];

$paginas = 0; $inalcancaveis = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($PUBLICO, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $f) {
    if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') continue;
    $rel = str_replace('\\', '/', substr(str_replace('\\', '/', $f->getPathname()), strlen($PUBLICO)));
    $seg = explode('/', ltrim($rel, '/'));
    if (in_array($seg[0], $pulaPasta, true)) continue;
    if (in_array(basename($rel), $pulaArq, true)) continue;

    $paginas++;
    $limpa = substr($rel, 0, -4);                     // /clientes
    $rota  = Router::resolve($limpa);
    if ($rota === null || str_replace('\\', '/', $rota['arquivo']) !== $PUBLICO . $rel) {
        $inalcancaveis[] = "$limpa -> $rel";
    }
}
if ($inalcancaveis === []) {
    pass("as $paginas paginas de public/ resolvem pelo endereco limpo");
} else {
    foreach ($inalcancaveis as $x) fail("endereco limpo nao resolve: $x");
}

// Paginas-pasta de SEO: sao exatamente as que hoje dependem da lista de slugs
// escrita a mao no vhost do nginx. Resolvendo aqui, a lista deixa de ser a
// unica coisa entre a pagina nova e um 404 so em producao.
$pastas = 0; $ruins = [];
foreach (glob($PUBLICO . '/*/index.php') as $idx) {
    $slug = basename(dirname($idx));
    if (in_array($slug, $pulaPasta, true) || $slug === 'configuracoes' || $slug === 'lgpd') continue;
    $pastas++;
    foreach (["/$slug/", "/$slug"] as $u) {
        $rota = Router::resolve($u);
        if ($rota === null || str_replace('\\', '/', $rota['arquivo']) !== str_replace('\\', '/', $idx)) {
            $ruins[] = $u;
        }
    }
}
if ($ruins === []) {
    pass("as $pastas paginas-pasta de SEO resolvem com e sem barra final");
} else {
    foreach ($ruins as $u) fail("pagina-pasta nao resolve: $u");
}

/* ===================================================================== */
secao('5. A tabela declarada aponta para arquivo real');
/* ===================================================================== */

$tabela = Router::tabela();
if ($tabela === []) {
    fail('config/rotas.php vazio — a rota "/" (home) e obrigatoria');
}
foreach ($tabela as $url => $def) {
    $arq = $ROOT . '/' . ltrim(is_array($def) ? $def['arquivo'] : $def, '/');
    if (is_file($arq)) {
        pass("rota $url -> " . (is_array($def) ? $def['arquivo'] : $def));
    } else {
        fail("rota $url aponta para arquivo inexistente: $arq");
    }
}

// A razao de ser do D3: uma pagina pode morar FORA de public/ e ainda responder
// pelo mesmo endereco. Provado com um arquivo temporario, para nao depender de
// nenhuma pagina real ter sido movida ainda.
$temp = $ROOT . '/storage/_rota_teste_' . bin2hex(random_bytes(4)) . '.php';
@mkdir(dirname($temp), 0775, true);
file_put_contents($temp, "<?php echo 'ok';\n");
$relativo = substr(str_replace('\\', '/', $temp), strlen($ROOT) + 1);

Router::definirTabela(['/pagina-fora-do-public' => $relativo]);
$fora = Router::resolve('/pagina-fora-do-public');
if ($fora !== null && str_replace('\\', '/', $fora['arquivo']) === str_replace('\\', '/', $temp)) {
    pass('rota serve arquivo de FORA de public/ (e o que destrava reorganizar a pasta)');
} else {
    fail('rota NAO serve arquivo de fora de public/ — a camada nao cumpre o que promete');
}

// E nao pode sair da raiz do projeto.
Router::definirTabela(['/escapa' => '../../../../etc/passwd']);
if (Router::resolve('/escapa') === null) {
    pass('rota declarada nao serve arquivo de fora da raiz do projeto');
} else {
    fail('rota declarada SERVIU arquivo de fora da raiz do projeto');
}

@unlink($temp);
Router::definirTabela(null);   // volta a ler config/rotas.php

$home = Router::resolve('/');
if ($home !== null && str_replace('\\', '/', $home['arquivo']) === $PUBLICO . '/v2/index.php') {
    pass('a raiz serve a landing v2');
} else {
    fail('a raiz NAO serve a landing v2 — a home mudou de dono');
}

/* ===================================================================== */
secao('6. Pasta nao pode sombrear pagina de mesmo nome');
/* ===================================================================== */

/*
 * O caso que motivou esta secao: existiam public/lgpd.php e a pasta
 * public/lgpd/. O mod_dir enxerga a pasta primeiro e responde 301 para /lgpd/,
 * que nao tinha index.php, e em producao isso terminava em 403. Como
 * /lgpd.php e link no rodape de TODA pagina legal e da landing, o visitante
 * percorria /lgpd.php -> 301 /lgpd -> 301 /lgpd/ -> 403.
 *
 * Nao da para consertar no .htaccess: o mod_dir marca a requisicao como
 * diretorio ANTES do mod_rewrite rodar, e nenhuma substituicao interna desfaz
 * isso (testado com [L], [PT], [DPI], [END], alvo relativo e absoluto). So
 * redirect externo vence, e esse entra em laco com a regra do nginx que
 * converte .php para a forma limpa. A saida e nao ter o conflito.
 */
$conhecidas = [
    // Aceita por ora: em producao o nginx reescreve /configuracoes para
    // /configuracoes.php antes de chegar ao Apache, entao o sintoma nao
    // aparece la. E divida, nao conserto: some no dia que essas duas telas
    // sairem de public/configuracoes/, como as do LGPD sairam.
    'configuracoes',
];
$sombras = [];
foreach (glob($PUBLICO . '/*.php') as $arq) {
    $nome = basename($arq, '.php');
    if (is_dir($PUBLICO . '/' . $nome)) {
        $sombras[] = $nome;
    }
}
foreach ($sombras as $nome) {
    if (in_array($nome, $conhecidas, true)) {
        echo "  [NOTA] sombra conhecida e aceita: public/$nome.php x public/$nome/\n";
        continue;
    }
    fail("pasta public/$nome/ sombreia a pagina public/$nome.php (o /$nome vai dar 403)");
}
$novas = array_diff($sombras, $conhecidas);
if ($novas === []) {
    pass('nenhuma sombra nova entre pagina e pasta de mesmo nome');
}
if (!in_array('lgpd', $sombras, true)) {
    pass('o conflito public/lgpd.php x public/lgpd/ nao existe mais');
} else {
    fail('o conflito do LGPD voltou');
}

/* ===================================================================== */
secao('7. Enderecos do LGPD que estao em e-mail ja enviado');
/* ===================================================================== */

/*
 * O link de acompanhamento (/lgpd/acompanhar.php?token=...) e montado em
 * public/api/lgpd/request.php e ENVIADO POR E-MAIL ao titular de dados.
 * E-mail ja enviado nao se corrige: estes enderecos sao permanentes.
 */
$permanentes = [
    '/lgpd/solicitar'       => 'app/Lgpd/Paginas/solicitar.php',
    '/lgpd/solicitar.php'   => 'app/Lgpd/Paginas/solicitar.php',
    '/lgpd/acompanhar'      => 'app/Lgpd/Paginas/acompanhar.php',
    '/lgpd/acompanhar.php'  => 'app/Lgpd/Paginas/acompanhar.php',
];
foreach ($permanentes as $url => $esperado) {
    $r = Router::resolve($url);
    $ok = $r !== null
        && str_replace('\\', '/', $r['arquivo']) === $ROOT . '/' . $esperado;
    if ($ok) {
        pass("$url continua respondendo");
    } else {
        fail("$url QUEBROU (resolveu para " . var_export($r['arquivo'] ?? null, true) . ')');
    }
}

// A pagina publica do LGPD voltou a ser alcancavel nas tres formas.
foreach (['/lgpd', '/lgpd/', '/lgpd.php'] as $url) {
    $r = Router::resolve($url);
    if ($r !== null && str_replace('\\', '/', $r['arquivo']) === $PUBLICO . '/lgpd.php') {
        pass("$url serve a pagina publica do LGPD");
    } else {
        fail("$url nao serve public/lgpd.php");
    }
}

// Fora de public/ = um endereco so. Se voltarem para dentro, o Apache passa a
// servi-las tambem pelo caminho fisico, criando uma segunda URL indexavel.
foreach (['app/Lgpd/Paginas/solicitar.php', 'app/Lgpd/Paginas/acompanhar.php'] as $rel) {
    if (is_file($ROOT . '/' . $rel) && !str_starts_with($ROOT . '/' . $rel, $PUBLICO . '/')) {
        pass("$rel mora fora de public/: tem um endereco so");
    } else {
        fail("$rel voltou para dentro de public/");
    }
}

/* ===================================================================== */
secao('8. Canonical das paginas legais nao depende do front controller');
/* ===================================================================== */

$legal = file_get_contents($PUBLICO . '/includes/legal_page.php');
if (str_contains($legal, 'Router::caminhoAtual()')) {
    pass('legal_page.php pergunta o caminho ao Router');
} else {
    fail('legal_page.php voltou a derivar o canonical so de SCRIPT_NAME (daria "/index")');
}
if (preg_match('/basename\(\$_SERVER\[.SCRIPT_NAME.\]/', $legal)) {
    fail('legal_page.php ainda usa basename() — /lgpd/solicitar publicaria canonical "/solicitar"');
} else {
    pass('canonical usa o caminho inteiro, nao o basename');
}

/* ===================================================================== */
printf("\n%s  %d PASS, %d FAIL\n", $FAILS === 0 ? 'OK' : 'FALHOU', $PASSES, $FAILS);
exit($FAILS === 0 ? 0 : 1);
