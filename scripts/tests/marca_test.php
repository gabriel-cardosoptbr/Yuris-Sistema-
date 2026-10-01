<?php
/**
 * marca_test.php: a marca das contas da edição CRM comercial.
 *
 * A edição CRM (a da Fleetiflow) passou a ser criada pelo Painel Master para
 * qualquer empresa, com nome, cor, logo e domínio próprios (App\Master\Marca).
 * Este teste tranca:
 *
 *   1. a conta Fleetiflow original, sem marca gravada, continua EXATAMENTE como
 *      era (mesma paleta tom a tom, mesmos arquivos, a Vitória como agente);
 *   2. as validações (cor, domínio, endereço do agente, imagem) recusam o que
 *      não serve, inclusive SVG e domínio do próprio Yuris;
 *   3. a gravação preserva o resto de `configuracoes` (o `produto`!);
 *   4. o domínio leva à conta certa e só a conta CRM;
 *   5. a agente Vitória NÃO atende conta de outra marca: sem endereço próprio,
 *      o agente fica desligado (antes os leads iriam para o robô do Fleetiflow).
 *
 * O que toca o banco roda em transação desfeita no fim.
 * Uso: php scripts/tests/marca_test.php
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Master\Marca as M;
use App\WhatsAppAgente\SdrFleetiflow;

$OK = 0;
$FALHAS = [];

function ok(string $msg, bool $cond): void
{
    global $OK, $FALHAS;
    if ($cond) { $OK++; echo "  [ok]   $msg\n"; }
    else { $FALHAS[] = $msg; echo "  [FALHA] $msg\n"; }
}

function lanca(callable $f): ?string
{
    try { $f(); return null; } catch (\InvalidArgumentException $e) { return $e->getMessage(); }
}

$conta = static fn(?array $marca, string $produto = 'fleetiflow') => [
    'id' => 1, 'nome' => 'Conta',
    'configuracoes' => json_encode(array_filter(['produto' => $produto, 'marca' => $marca], fn($v) => $v !== null)),
];

echo "\n== 1. A Fleetiflow original não muda ==\n";
$p = M::daConta($conta(null));
ok('sem marca gravada => marca Fleetiflow', $p['nome'] === 'Fleetiflow' && $p['personalizada'] === false);
ok('paleta original, tom a tom', $p['paleta'] === ['marca' => '#015DFC', 'forte' => '#013DF2', 'suave' => '#D6E4FF',
    'media' => '#A9C6FF', 'clara' => '#6D9DFD', 'escura' => '#0B2A6B', 'rgb' => '1,93,252', 'texto' => '#FFFFFF']);
ok('mesmos arquivos de antes', $p['icone_url'] === '/sistema_vendas/Imagens/fleetiflow-icone.png'
    && $p['logo_url'] === '/sistema_vendas/Imagens/fleetiflow-horizontal.png');
ok('agente é a Vitória', $p['agente'] === ['nome' => 'Vitória', 'artigo' => 'a']);
ok('marca sem nome conta como sem marca', M::daConta($conta(['nome' => '  ', 'cor' => '#FF0000']))['personalizada'] === false);
ok('configuracoes quebrado => padrão, sem erro', M::daConta(['configuracoes' => '{nao json'])['nome'] === 'Fleetiflow');
ok('a cor do Fleetiflow escolhida numa marca nova dá a mesma paleta', M::paleta('#015dfc') === $p['paleta']);

echo "\n== 2. Marca própria ==\n";
$hashLogo = str_repeat('a', 40);
$m = M::daConta($conta(['nome' => 'Inovaize', 'cor' => '#7c3aed', 'logo' => $hashLogo, 'icone' => 'nao-e-hash', 'agente_nome' => 'Ana']));
ok('nome e cor da marca', $m['nome'] === 'Inovaize' && $m['cor'] === '#7C3AED' && $m['personalizada'] === true);
ok('logo com hash válido vira endereço público', $m['logo_url'] === '/api/marca_arquivo.php?h=' . $hashLogo);
ok('hash inválido é ignorado (sem ícone)', $m['icone_url'] === null);
ok('sem ícone: favicon é a inicial em SVG', str_starts_with($m['favicon_url'], 'data:image/svg+xml;base64,')
    && str_contains(base64_decode(substr($m['favicon_url'], 26)), '>I</text>'));
ok('subtítulo padrão quando vazio', $m['subtitulo'] === 'Central Comercial');
ok('agente com nome próprio, sem artigo', $m['agente'] === ['nome' => 'Ana', 'artigo' => '']);
ok('agente sem nome vira "a IA"', M::daConta($conta(['nome' => 'X']))['agente'] === ['nome' => 'IA', 'artigo' => 'a']);
$pal = M::paleta('#7C3AED');
ok('paleta derivada tem os oito tons', count($pal) === 8 && $pal['marca'] === '#7C3AED' && $pal['rgb'] === '124,58,237');
ok('tom suave é claro e o escuro é escuro', hexdec(substr($pal['suave'], 1, 2)) > 200 && hexdec(substr($pal['escura'], 1, 2)) < 80);
ok('cor escura leva texto branco', $pal['texto'] === '#FFFFFF');
ok('cor clara (amarelo) leva texto escuro', M::paleta('#FDE047')['texto'] === '#1F2937');
ok('nome com HTML é limpo', M::daConta($conta(['nome' => '<b>Auto</b>doc']))['nome'] === 'Autodoc');

echo "\n== 3. Validações ==\n";
ok('cor #abc vira #AABBCC', M::validarCor('#abc') === '#AABBCC');
ok('cor sem # é aceita', M::validarCor('0e9f6e') === '#0E9F6E');
ok('cor inválida => null', M::validarCor('azul') === null && M::validarCor('#12345') === null);
ok('domínio colado com https e caminho fica só o host', M::validarDominio('https://CRM.Inovaize.com.br/login?x=1') === 'crm.inovaize.com.br');
ok('domínio com porta perde a porta', M::validarDominio('crm.x.com.br:8443') === 'crm.x.com.br');
ok('domínio do Yuris é recusado', M::validarDominio('crm.yuris.com.br') === null && M::validarDominio('yuris.com.br') === null);
ok('localhost e sem ponto são recusados', M::validarDominio('localhost') === null && M::validarDominio('intranet') === null);
ok('domínio com caractere inválido é recusado', M::validarDominio('crm_x.com.br') === null && M::validarDominio('a..b.com') === null);
ok('agente só com https', M::validarWebhook('https://n8n.x.com/webhook/1') !== null
    && M::validarWebhook('http://n8n.x.com/webhook/1') === null && M::validarWebhook('javascript:alert(1)') === null);
ok('normalizar exige nome', lanca(fn() => M::normalizar(['nome' => ''])) !== null);
ok('normalizar recusa cor inválida', lanca(fn() => M::normalizar(['nome' => 'X', 'cor' => 'roxo'])) !== null);
ok('normalizar recusa domínio inválido', lanca(fn() => M::normalizar(['nome' => 'X', 'dominio' => 'crm.yuris.com.br'])) !== null);
ok('normalizar recusa endereço de agente sem https', lanca(fn() => M::normalizar(['nome' => 'X', 'agente_webhook' => 'http://a.b/c'])) !== null);
$n = M::normalizar(['nome' => ' Autodoc ', 'cor' => '', 'dominio' => '']);
ok('normalizar: cor vazia vira a padrão, domínio vazio vira null', $n['cor'] === '#015DFC' && $n['dominio'] === null && $n['nome'] === 'Autodoc');

$png  = (function () { $im = imagecreatetruecolor(8, 8); ob_start(); imagepng($im); return ob_get_clean(); })();
$jpg  = (function () { $im = imagecreatetruecolor(8, 8); ob_start(); imagejpeg($im); return ob_get_clean(); })();
ok('PNG aceito', M::validarImagem($png)['mime'] === 'image/png');
ok('JPEG aceito, com tamanho', ($i = M::validarImagem($jpg))['mime'] === 'image/jpeg' && $i['largura'] === 8);
ok('SVG recusado (roda script)', lanca(fn() => M::validarImagem('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')) !== null);
ok('PDF disfarçado recusado', lanca(fn() => M::validarImagem("%PDF-1.4\n...")) !== null);
ok('acima de 1,5 MB recusado', lanca(fn() => M::validarImagem("\x89PNG\r\n\x1A\n" . str_repeat('x', M::MAX_BYTES))) !== null);
ok('data URL é decodificada', M::decodificarUpload('data:image/png;base64,' . base64_encode($png)) === $png);
ok('base64 quebrado é recusado', lanca(fn() => M::decodificarUpload('***')) !== null);

/* ── 4 e 5: com banco, em transação desfeita ─────────────────────────────── */
echo "\n== 4. Gravação, imagens e domínio (banco, desfeito no fim) ==\n";
try {
    $pdo = \App\Core\Database::getConnection();
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $temTabela = (bool) $pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'account_marca_arquivos'")->fetchColumn();
} catch (\Throwable $e) {
    $pdo = null; $temTabela = false;
    echo '  [pulado] sem banco: ' . $e->getMessage() . "\n";
}

if (!$pdo || !$temTabela) {
    echo "  [pulado] precisa do banco e da migration 133\n";
} else {
    $pdo->beginTransaction();
    try {
        $nova = static function (string $nome, ?array $config) use ($pdo): int {
            $pdo->prepare("INSERT INTO accounts (nome, tipo, codigo_vinculo, plano, status, configuracoes, created_at, updated_at)
                           VALUES (?, 'matriz', ?, 'equipe', 'active', ?, NOW(), NOW())")
                ->execute([$nome, bin2hex(random_bytes(8)), $config ? json_encode($config) : null]);
            return (int) $pdo->lastInsertId();
        };
        $ler = static function (int $id) use ($pdo): array {
            $st = $pdo->prepare('SELECT * FROM accounts WHERE id = ?');
            $st->execute([$id]);
            return $st->fetch(\PDO::FETCH_ASSOC);
        };

        $crm   = $nova('Teste Marca CRM', ['produto' => 'fleetiflow', 'outra_chave' => 7]);
        $yuris = $nova('Teste Marca Yuris', null);

        $hLogo = M::salvarArquivo($pdo, $crm, 'logo', $png);
        ok('salvarArquivo devolve o sha1 do conteúdo', $hLogo === sha1($png));
        $hLogo2 = M::salvarArquivo($pdo, $crm, 'logo', $jpg);
        $qtd = (int) $pdo->query("SELECT COUNT(*) FROM account_marca_arquivos WHERE account_id = $crm")->fetchColumn();
        ok('trocar o logo substitui a linha (uma por tipo)', $qtd === 1 && $hLogo2 === sha1($jpg));
        ok('tipo de imagem inválido é recusado', lanca(fn() => M::salvarArquivo($pdo, $crm, 'banner', $png)) !== null);

        $marca = M::normalizar(['nome' => 'Inovaize Teste', 'cor' => '#7C3AED', 'dominio' => 'crm.marca-teste.example',
                                'agente_nome' => 'Ana', 'agente_webhook' => 'https://n8n.example/webhook/ana']);
        M::gravar($pdo, $crm, $marca, ['logo' => $hLogo2]);
        $cfg = json_decode($ler($crm)['configuracoes'], true);
        ok('gravar preserva o produto e as outras chaves', $cfg['produto'] === 'fleetiflow' && $cfg['outra_chave'] === 7);
        ok('gravar guarda marca e hash do logo', $cfg['marca']['nome'] === 'Inovaize Teste' && $cfg['marca']['logo'] === $hLogo2);

        M::gravar($pdo, $crm, array_merge($marca, ['cor' => '#0E9F6E']));
        $cfg = json_decode($ler($crm)['configuracoes'], true);
        ok('regravar sem mexer na imagem mantém o logo', $cfg['marca']['logo'] === $hLogo2 && $cfg['marca']['cor'] === '#0E9F6E');
        M::gravar($pdo, $crm, $marca, ['logo' => null]);
        $cfg = json_decode($ler($crm)['configuracoes'], true);
        ok('imagem null tira o logo da marca', !isset($cfg['marca']['logo']));
        M::gravar($pdo, $crm, $marca, ['logo' => $hLogo2]);

        $achada = M::contaPorDominio($pdo, 'crm.marca-teste.example');
        ok('o domínio leva à conta dona', $achada !== null && (int) $achada['id'] === $crm);
        ok('domínio digitado com https e maiúscula também acha', (int) (M::contaPorDominio($pdo, 'HTTPS://crm.marca-teste.example/')['id'] ?? 0) === $crm);
        ok('excetoConta ignora a própria conta (edição)', M::contaPorDominio($pdo, 'crm.marca-teste.example', $crm) === null);
        ok('domínio desconhecido não acha nada', M::contaPorDominio($pdo, 'crm.ninguem.example') === null);

        // Conta jurídica com o mesmo domínio escrito na mão não vira porta de entrada.
        $pdo->prepare('UPDATE accounts SET configuracoes = ? WHERE id = ?')
            ->execute([json_encode(['marca' => ['nome' => 'Intrusa', 'dominio' => 'crm.intrusa.example']]), $yuris]);
        ok('conta da edição jurídica não é dona de domínio de marca', M::contaPorDominio($pdo, 'crm.intrusa.example') === null);

        echo "\n== 5. Agente de pré-venda por conta ==\n";
        ok('conta com marca própria usa o endereço DELA', SdrFleetiflow::urlDaConta($crm) === 'https://n8n.example/webhook/ana');
        ok('... e o nome do agente dela', SdrFleetiflow::nomeAgenteDaConta($crm) === 'Ana');

        $semHook = $nova('Teste Marca Sem Agente', ['produto' => 'fleetiflow', 'marca' => ['nome' => 'Autodoc Teste']]);
        ok('marca própria SEM endereço: agente desligado (não cai na Vitória)', SdrFleetiflow::urlDaConta($semHook) === '');
        ok('... e o nome genérico', SdrFleetiflow::nomeAgenteDaConta($semHook) === 'Agente de pré-venda');

        $original = $nova('Teste Marca Fleetiflow', ['produto' => 'fleetiflow']);
        ok('conta CRM sem marca (a Fleetiflow) continua no endereço do servidor', SdrFleetiflow::urlDaConta($original) === SdrFleetiflow::url());
        ok('... com a Vitória', SdrFleetiflow::nomeAgenteDaConta($original) === SdrFleetiflow::NOME_AGENTE);
    } catch (\Throwable $e) {
        ok('integração sem exceção: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}

echo "\n----\n";
if (!$FALHAS) {
    echo "Resultado: {$OK} ok · 0 falha(s)\n";
    exit(0);
}
echo 'Resultado: ' . $OK . ' ok · ' . count($FALHAS) . " falha(s)\n\n";
foreach ($FALHAS as $f) echo "  - $f\n";
exit(1);
