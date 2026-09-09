<?php
/**
 * crm_fase2_test.php — Fase 2 do CRM: anexos, etiquetas, campos, interações e canal.
 *
 * ESCREVE NO BANCO. Cada asserção olha o DADO depois da operação, não a resposta
 * da função: uma função pode devolver true e não ter gravado.
 *
 * A pergunta central que esta suíte responde é a que a Fase 2 inteira gira em
 * volta: o que ACOMPANHA a conversão sem ser copiado (fato) e o que é copiado
 * uma vez (opinião editável). Os dois comportamentos são testados nos dois
 * sentidos, incluindo a prova de que o fato NÃO foi duplicado.
 *
 * O QUE ESTE TESTE NÃO CONSEGUE LIMPAR
 * `card_history` e `clientes_history` têm trigger de imutabilidade (migration
 * 053 e 127, LGPD Art. 37): não aceitam UPDATE nem DELETE, nem vindos daqui. As
 * linhas de histórico dos registros de teste FICAM no banco, órfãs. É de
 * propósito e não polui: a Timeline faz JOIN em `cards`/`clientes`, e registro
 * apagado não aparece em lugar nenhum.
 *
 * Uso local: C:\xampp\php\php.exe scripts/tests/crm_fase2_test.php
 * Uso prod:  NÃO. Rode só em desenvolvimento.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Clientes\VinculosCliente;
use App\Core\Database;
use App\Core\Timeline;
use App\Crm\Anexo;
use App\Crm\CampoPersonalizado;
use App\Crm\Entidade;
use App\Crm\Interacao;
use App\Crm\Tag;
use App\Prospeccao\Card;
use App\Prospeccao\ConversaoCliente;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

$FAILS = 0; $PASSES = 0;
function pass(string $m): void { global $PASSES; $PASSES++; echo "  [PASS] $m\n"; }
function fail(string $m): void { global $FAILS;  $FAILS++;  echo "  [FAIL] $m\n"; }
function secao(string $t): void { echo "\n== $t ==\n"; }
function ok(bool $c, string $m): void { $c ? pass($m) : fail($m); }

/* --------------------------------------------------------------------------
 * Cenário: duas contas de verdade, para o teste de isolamento não ser uma
 * simulação com a mesma conta duas vezes.
 * ------------------------------------------------------------------------ */
$PREFIXO = 'TESTE-F2-' . substr(bin2hex(random_bytes(3)), 0, 6);

$ACC_A = (int) $pdo->query(
    'SELECT a.id FROM accounts a
      WHERE EXISTS (SELECT 1 FROM pipeline_columns pc WHERE pc.account_id = a.id)
        AND EXISTS (SELECT 1 FROM clientes_setores cs WHERE cs.account_id = a.id AND cs.ativo = 1)
        AND EXISTS (SELECT 1 FROM clientes_origens co WHERE co.account_id = a.id AND co.ativo = 1)
      ORDER BY a.id LIMIT 1'
)->fetchColumn();

// A conta B precisa ter catálogo próprio de canal e de etiqueta para os testes
// de cruzamento serem possíveis.
$ACC_B = (int) $pdo->query(
    "SELECT a.id FROM accounts a
      WHERE a.id <> $ACC_A
        AND EXISTS (SELECT 1 FROM clientes_origens co WHERE co.account_id = a.id AND co.ativo = 1)
   ORDER BY a.id LIMIT 1"
)->fetchColumn();

if (!$ACC_A || !$ACC_B) {
    echo "SKIP: são necessárias 2 contas com catálogo de canal; A=$ACC_A B=$ACC_B.\n";
    exit(0);
}

$USER = (int) $pdo->query("SELECT id FROM users WHERE account_id = $ACC_A ORDER BY id LIMIT 1")->fetchColumn();
if (!$USER) { echo "SKIP: conta A sem usuário.\n"; exit(0); }

$COL_A   = (int) $pdo->query("SELECT id FROM pipeline_columns WHERE account_id = $ACC_A ORDER BY ordem, id LIMIT 1")->fetchColumn();
$CANAL_A = (int) $pdo->query("SELECT id FROM clientes_origens WHERE account_id = $ACC_A AND ativo = 1 ORDER BY ordem, id LIMIT 1")->fetchColumn();
$CANAL_B = (int) $pdo->query("SELECT id FROM clientes_origens WHERE account_id = $ACC_B AND ativo = 1 ORDER BY ordem, id LIMIT 1")->fetchColumn();
$SLUG_A  = (string) $pdo->query("SELECT slug FROM clientes_origens WHERE id = $CANAL_A")->fetchColumn();

echo "Cenário: conta A=$ACC_A (funil $COL_A, canal $CANAL_A '$SLUG_A'), conta B=$ACC_B (canal $CANAL_B)\n";
echo "Prefixo: $PREFIXO\n";

$criados = ['cards' => [], 'clientes' => [], 'tags' => [], 'campos' => [], 'anexos' => [], 'interacoes' => [], 'tasks' => []];

/*
 * LIMPEZA DEFENSIVA de rodadas anteriores que morreram no meio.
 *
 * A lição vem da Fase 1: um teste que abortou antes da limpeza deixou uma linha
 * com UNIQUE ocupado, e a rodada seguinte falhou no INSERT e PULOU as asserções
 * em silêncio, terminando verde sem ter testado nada. Aqui o risco é o mesmo com
 * `crm_tags` (UNIQUE em account_id + slug): uma etiqueta 'TESTE-F2-...' sobrando
 * faria Tag::criar devolver a antiga em vez de criar, e as contagens ficariam
 * erradas por um motivo que ninguém adivinharia.
 *
 * Varre por PREFIXO GENÉRICO, não pelo desta rodada: o prefixo desta é novo por
 * construção, então limpar só ele não limparia nada.
 */
$lixo = 0;
try {
    foreach ($pdo->query("SELECT id FROM crm_tags WHERE nome LIKE 'TESTE-F2-%'")->fetchAll(\PDO::FETCH_COLUMN) as $id) {
        $pdo->prepare('DELETE FROM crm_tag_vinculos WHERE tag_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM crm_tags WHERE id = ?')->execute([$id]);
        $lixo++;
    }
    foreach ($pdo->query("SELECT id FROM crm_campos WHERE rotulo LIKE 'TESTE-F2-%'")->fetchAll(\PDO::FETCH_COLUMN) as $id) {
        $pdo->prepare('DELETE FROM crm_campo_valores WHERE campo_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM crm_campos WHERE id = ?')->execute([$id]);
        $lixo++;
    }
    foreach ($pdo->query("SELECT id FROM cards WHERE cliente_nome LIKE 'TESTE-F2-%'")->fetchAll(\PDO::FETCH_COLUMN) as $id) {
        $pdo->prepare("DELETE FROM crm_anexos     WHERE entidade = 'card' AND entidade_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM crm_interacoes WHERE entidade = 'card' AND entidade_id = ?")->execute([$id]);
        $pdo->prepare('DELETE FROM cards WHERE id = ?')->execute([$id]);
        $lixo++;
    }
    foreach ($pdo->query("SELECT id FROM clientes WHERE nome LIKE 'TESTE-F2-%'")->fetchAll(\PDO::FETCH_COLUMN) as $id) {
        $pdo->prepare("DELETE FROM crm_anexos     WHERE entidade = 'cliente' AND entidade_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM crm_interacoes WHERE entidade = 'cliente' AND entidade_id = ?")->execute([$id]);
        $pdo->prepare('DELETE FROM clientes WHERE id = ?')->execute([$id]);
        $lixo++;
    }
    foreach ($pdo->query("SELECT id FROM tasks WHERE titulo LIKE 'TESTE-F2-%'")->fetchAll(\PDO::FETCH_COLUMN) as $id) {
        $pdo->prepare('DELETE FROM task_links WHERE task_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM tasks WHERE id = ?')->execute([$id]);
        $lixo++;
    }
} catch (\Throwable $e) {
    echo "  aviso: limpeza defensiva falhou parcialmente: " . $e->getMessage() . "\n";
}
if ($lixo > 0) { echo "Limpeza defensiva: $lixo registro(s) de rodadas anteriores removidos.\n"; }

/** Cria uma prospecção de teste. */
function novoCard(string $nome, int $acc, ?int $col, ?int $canal = null): int
{
    global $criados, $USER;
    $id = Card::create([
        'account_id'   => $acc,
        'cliente_nome' => $nome,
        'coluna_id'    => $col,
        'origem_id'    => $canal,
        '_usuario_id'  => $USER,
    ]);
    $criados['cards'][] = $id;
    return (int) $id;
}

/* ===================================================================== */
secao('Bloco E — canal de aquisição atravessa a conversão');
/* ===================================================================== */

$cardCanal = novoCard($PREFIXO . ' Canal', $ACC_A, $COL_A, $CANAL_A);

$st = $pdo->prepare('SELECT origem_id FROM cards WHERE id = ?');
$st->execute([$cardCanal]);
ok((int) $st->fetchColumn() === $CANAL_A, 'o canal gravou na prospecção');

$r = ConversaoCliente::converter($cardCanal, $ACC_A, [$ACC_A], $USER);
ok($r['ok'] === true, 'conversão concluída: ' . ($r['erro'] ?? 'sem erro'));
if ($r['ok']) {
    $criados['clientes'][] = $r['cliente_id'];
    $st = $pdo->prepare('SELECT origem FROM clientes WHERE id = ?');
    $st->execute([$r['cliente_id']]);
    $origemCliente = (string) $st->fetchColumn();
    ok(
        $origemCliente === $SLUG_A,
        "o cliente nasceu com o canal da prospecção ('$origemCliente' === '$SLUG_A')"
    );
}

// Sem canal na prospecção, cai em 'prospeccao': não inventa dado.
$cardSemCanal = novoCard($PREFIXO . ' SemCanal', $ACC_A, $COL_A, null);
$r2 = ConversaoCliente::converter($cardSemCanal, $ACC_A, [$ACC_A], $USER);
if ($r2['ok']) {
    $criados['clientes'][] = $r2['cliente_id'];
    $st = $pdo->prepare('SELECT origem FROM clientes WHERE id = ?');
    $st->execute([$r2['cliente_id']]);
    ok((string) $st->fetchColumn() === 'prospeccao', "prospecção sem canal vira origem 'prospeccao'");
}

// Canal da conta B num card da conta A é recusado na escrita.
$cardCruz = novoCard($PREFIXO . ' Cruz', $ACC_A, $COL_A, $CANAL_B);
$st = $pdo->prepare('SELECT origem_id FROM cards WHERE id = ?');
$st->execute([$cardCruz]);
ok($st->fetchColumn() === null, 'canal de OUTRA conta é recusado no cadastro da prospecção');

/* ===================================================================== */
secao('Bloco B — etiqueta é opinião: COPIA na conversão e depois vive solta');
/* ===================================================================== */

$tagA = Tag::criar($ACC_A, $PREFIXO . ' Urgente', '#ef4444', $USER);
$tagB = Tag::criar($ACC_B, $PREFIXO . ' AlheiaB', null, $USER);
$criados['tags'][] = (int) $tagA['id'];
$criados['tags'][] = (int) $tagB['id'];

ok((int) $tagA['account_id'] === $ACC_A, 'etiqueta criada na conta certa');

// Criar de novo com o mesmo nome devolve a MESMA, não cria segunda.
$tagRepetida = Tag::criar($ACC_A, $PREFIXO . ' Urgente', null, $USER);
ok((int) $tagRepetida['id'] === (int) $tagA['id'], 'criar etiqueta repetida devolve a existente');

$cardTag = novoCard($PREFIXO . ' Tags', $ACC_A, $COL_A, $CANAL_A);
$alvoCard = Entidade::resolver('card', $cardTag, [$ACC_A]);
ok($alvoCard !== null && $alvoCard['account_id'] === $ACC_A, 'Entidade::resolver acha a prospecção e diz a conta dela');

ok(Tag::aplicar($alvoCard, (int) $tagA['id'], [$ACC_A], $USER) === true, 'etiqueta aplicada na prospecção');
ok(Tag::aplicar($alvoCard, (int) $tagA['id'], [$ACC_A], $USER) === false, 'aplicar de novo é inerte (não duplica)');

$n = (int) $pdo->query(
    "SELECT COUNT(*) FROM crm_tag_vinculos WHERE tag_id = {$tagA['id']} AND entidade = 'card' AND entidade_id = $cardTag"
)->fetchColumn();
ok($n === 1, 'existe exatamente UM vínculo da etiqueta com a prospecção');

// Cruzamento de conta: a etiqueta da B não entra em card da A, mesmo com as
// duas contas "acessíveis" (é o caso da sessão matriz que alcança a filial).
ok(
    Tag::aplicar($alvoCard, (int) $tagB['id'], [$ACC_A, $ACC_B], $USER) === false,
    'etiqueta da conta B é RECUSADA num card da conta A, mesmo com as duas acessíveis'
);
$nCruz = (int) $pdo->query(
    "SELECT COUNT(*) FROM crm_tag_vinculos WHERE tag_id = {$tagB['id']}"
)->fetchColumn();
ok($nCruz === 0, 'nenhum vínculo cruzado foi gravado');

$rt = ConversaoCliente::converter($cardTag, $ACC_A, [$ACC_A], $USER);
ok($rt['ok'] === true, 'conversão da prospecção etiquetada: ' . ($rt['erro'] ?? 'sem erro'));
$cliTag = (int) ($rt['cliente_id'] ?? 0);
if ($cliTag) {
    $criados['clientes'][] = $cliTag;

    $tagsCliente = Tag::daEntidade('cliente', $cliTag, [$ACC_A]);
    $nomes = array_map(fn ($t) => $t['nome'], $tagsCliente);
    ok(in_array($tagA['nome'], $nomes, true), 'a etiqueta ACOMPANHOU: está na ficha do cliente');

    // A prova de que copiou e não herdou por leitura: existe linha PRÓPRIA do cliente.
    $vinc = (int) $pdo->query(
        "SELECT COUNT(*) FROM crm_tag_vinculos
          WHERE tag_id = {$tagA['id']} AND entidade = 'cliente' AND entidade_id = $cliTag"
    )->fetchColumn();
    ok($vinc === 1, 'a etiqueta foi COPIADA (o cliente tem vínculo próprio, não herança)');

    // E o card continua com a dele: a cópia não moveu nada.
    $vincCard = (int) $pdo->query(
        "SELECT COUNT(*) FROM crm_tag_vinculos
          WHERE tag_id = {$tagA['id']} AND entidade = 'card' AND entidade_id = $cardTag"
    )->fetchColumn();
    ok($vincCard === 1, 'a prospecção continua com a etiqueta dela');

    // A independência: tirar do cliente NÃO tira do card. É o motivo de copiar.
    $alvoCli = Entidade::resolver('cliente', $cliTag, [$ACC_A]);
    ok(Tag::desaplicar($alvoCli, (int) $tagA['id'], [$ACC_A], $USER) === true, 'etiqueta removida do cliente');
    $aindaNoCard = (int) $pdo->query(
        "SELECT COUNT(*) FROM crm_tag_vinculos
          WHERE tag_id = {$tagA['id']} AND entidade = 'card' AND entidade_id = $cardTag"
    )->fetchColumn();
    ok($aindaNoCard === 1, 'remover do cliente NÃO mexeu na prospecção (as duas são independentes)');
}

// Arquivar mantém os vínculos: apagar reescreveria o passado.
$tagArq = Tag::criar($ACC_A, $PREFIXO . ' ParaArquivar', null, $USER);
$criados['tags'][] = (int) $tagArq['id'];
$cardArq = novoCard($PREFIXO . ' Arq', $ACC_A, $COL_A);
$alvoArq = Entidade::resolver('card', $cardArq, [$ACC_A]);
Tag::aplicar($alvoArq, (int) $tagArq['id'], [$ACC_A], $USER);
ok(Tag::arquivar((int) $tagArq['id'], [$ACC_A]) === true, 'etiqueta arquivada');
$vincSobrou = (int) $pdo->query(
    "SELECT COUNT(*) FROM crm_tag_vinculos WHERE tag_id = {$tagArq['id']}"
)->fetchColumn();
ok($vincSobrou === 1, 'arquivar a etiqueta NÃO apagou os vínculos existentes');
$noCatalogo = array_map(fn ($t) => (int) $t['id'], Tag::catalogo([$ACC_A]));
ok(!in_array((int) $tagArq['id'], $noCatalogo, true), 'a etiqueta arquivada saiu do catálogo ativo');

/* ===================================================================== */
secao('Bloco C — campo personalizado: normaliza, audita e copia só no vazio');
/* ===================================================================== */

$campoTexto = CampoPersonalizado::criarDefinicao($ACC_A, $PREFIXO . ' NIT', 'texto', 'ambos', [], false, $USER);
$campoData  = CampoPersonalizado::criarDefinicao($ACC_A, $PREFIXO . ' Admissao', 'data', 'ambos', [], false, $USER);
$campoSel   = CampoPersonalizado::criarDefinicao($ACC_A, $PREFIXO . ' Area', 'selecao', 'ambos', ['Trabalhista', 'Cível'], false, $USER);
$campoSoCard = CampoPersonalizado::criarDefinicao($ACC_A, $PREFIXO . ' SoFunil', 'texto', 'card', [], false, $USER);
foreach ([$campoTexto, $campoData, $campoSel, $campoSoCard] as $c) { $criados['campos'][] = (int) $c['id']; }

// Seleção sem opção é recusada na definição: campo de lista sem lista não serve.
$erroSel = false;
try { CampoPersonalizado::criarDefinicao($ACC_A, $PREFIXO . ' Vazio', 'selecao', 'ambos', [], false, $USER); }
catch (\InvalidArgumentException $e) { $erroSel = true; }
ok($erroSel, 'campo de seleção SEM opções é recusado');

// Normalização por tipo, testada na função e não pela UI.
ok(CampoPersonalizado::normalizarValor('10/03/2024', 'data', []) === '2024-03-10', 'data em d/m/Y vira Y-m-d');
ok(CampoPersonalizado::normalizarValor('2024-03-10', 'data', []) === '2024-03-10', 'data em Y-m-d é aceita');
ok(CampoPersonalizado::normalizarValor('1.234,56', 'moeda', []) === '1234.56', 'moeda em formato BR vira ponto decimal');
ok(CampoPersonalizado::normalizarValor('', 'texto', []) === null, 'vazio vira null (apaga o valor)');
ok(CampoPersonalizado::normalizarValor('Sim', 'sim_nao', []) === '1', 'sim/não aceita texto em pt-BR');

$erroData = false;
try { CampoPersonalizado::normalizarValor('30/02/xx', 'data', []); }
catch (\InvalidArgumentException $e) { $erroData = true; }
ok($erroData, 'data inválida é recusada');

$erroOpcao = false;
try { CampoPersonalizado::normalizarValor('Tributário', 'selecao', ['Trabalhista', 'Cível']); }
catch (\InvalidArgumentException $e) { $erroOpcao = true; }
ok($erroOpcao, 'opção fora da lista do campo é recusada');

$cardCampo = novoCard($PREFIXO . ' Campos', $ACC_A, $COL_A);
$alvoCampo = Entidade::resolver('card', $cardCampo, [$ACC_A]);

$res = CampoPersonalizado::salvarValores($alvoCampo, [
    $campoTexto['chave']  => '12345678901',
    $campoData['chave']   => '10/03/2024',
    $campoSel['chave']    => 'Trabalhista',
    $campoSoCard['chave'] => 'valor do funil',
], [$ACC_A], $USER);
ok($res['gravados'] === 4 && $res['erros'] === [], 'quatro campos gravados, sem erro');

// Regravar o mesmo valor não gera evento: histórico não é log de cliques.
$res2 = CampoPersonalizado::salvarValores($alvoCampo, [$campoTexto['chave'] => '12345678901'], [$ACC_A], $USER);
ok($res2['gravados'] === 0, 'regravar o MESMO valor não gera evento nem UPDATE');

// Erro parcial: grava o que dá e diz o que não passou.
$res3 = CampoPersonalizado::salvarValores($alvoCampo, [
    $campoTexto['chave'] => '99999999999',
    $campoSel['chave']   => 'InexistenteNaLista',
], [$ACC_A], $USER);
ok($res3['gravados'] === 1, 'lote com um campo inválido grava os válidos');
ok(isset($res3['erros'][$campoSel['chave']]), 'e diz qual campo não passou');

// A auditoria campo a campo, com valor antigo e novo.
$hist = $pdo->query(
    "SELECT campo_alterado, valor_anterior, valor_novo FROM card_history
      WHERE card_id = $cardCampo AND acao = 'campo_personalizado'
   ORDER BY id"
)->fetchAll(\PDO::FETCH_ASSOC);
ok(count($hist) === 5, 'cinco eventos de campo personalizado no histórico (4 + 1 alteração)');

$alteracao = null;
foreach ($hist as $h) {
    if ($h['campo_alterado'] === $campoTexto['chave'] && $h['valor_anterior'] !== null) { $alteracao = $h; }
}
ok(
    $alteracao !== null
      && $alteracao['valor_anterior'] === '12345678901'
      && $alteracao['valor_novo'] === '99999999999',
    'a alteração registrou valor ANTIGO e NOVO (de 12345678901 para 99999999999)'
);
// A chave, não o rótulo: rótulo pode ser reescrito e o evento antigo continuaria válido.
ok(
    $alteracao !== null && $alteracao['campo_alterado'] === $campoTexto['chave'],
    'o histórico grava a CHAVE do campo, não o rótulo'
);

$rc = ConversaoCliente::converter($cardCampo, $ACC_A, [$ACC_A], $USER);
ok($rc['ok'] === true, 'conversão da prospecção com campos: ' . ($rc['erro'] ?? 'sem erro'));
$cliCampo = (int) ($rc['cliente_id'] ?? 0);
if ($cliCampo) {
    $criados['clientes'][] = $cliCampo;

    $valores = [];
    foreach (CampoPersonalizado::valores('cliente', $cliCampo, [$ACC_A]) as $v) {
        $valores[$v['chave']] = $v['valor'];
    }
    ok(($valores[$campoTexto['chave']] ?? null) === '99999999999', 'campo de texto ACOMPANHOU a conversão');
    ok(($valores[$campoData['chave']] ?? null) === '2024-03-10', 'campo de data acompanhou, já normalizado');
    ok(($valores[$campoSel['chave']] ?? null) === 'Trabalhista', 'campo de seleção acompanhou');

    // aplica_em='card' é do funil comercial: não faz sentido na ficha do cliente.
    ok(!array_key_exists($campoSoCard['chave'], $valores), "campo marcado só para 'card' NÃO foi para o cliente");
    $copiouErrado = (int) $pdo->query(
        "SELECT COUNT(*) FROM crm_campo_valores
          WHERE campo_id = {$campoSoCard['id']} AND entidade = 'cliente' AND entidade_id = $cliCampo"
    )->fetchColumn();
    ok($copiouErrado === 0, 'e não gravou linha nenhuma dele no cliente');
}

// A trava do "só no vazio": vinculando a um cliente que JÁ tem valor, o dele vale.
$cardCampo2 = novoCard($PREFIXO . ' Campos2', $ACC_A, $COL_A);
$alvoCampo2 = Entidade::resolver('card', $cardCampo2, [$ACC_A]);
CampoPersonalizado::salvarValores($alvoCampo2, [$campoTexto['chave'] => 'VEIO-DO-LEAD'], [$ACC_A], $USER);

if ($cliCampo) {
    $alvoCliCampo = Entidade::resolver('cliente', $cliCampo, [$ACC_A]);
    CampoPersonalizado::salvarValores($alvoCliCampo, [$campoTexto['chave'] => 'JA-ERA-DO-CLIENTE'], [$ACC_A], $USER);

    $rv = ConversaoCliente::converter($cardCampo2, $ACC_A, [$ACC_A], $USER, $cliCampo);
    ok($rv['ok'] === true, 'vinculação a cliente existente: ' . ($rv['erro'] ?? 'sem erro'));

    $valores2 = [];
    foreach (CampoPersonalizado::valores('cliente', $cliCampo, [$ACC_A]) as $v) {
        $valores2[$v['chave']] = $v['valor'];
    }
    ok(
        ($valores2[$campoTexto['chave']] ?? null) === 'JA-ERA-DO-CLIENTE',
        'o valor que o cliente JÁ tinha não foi sobrescrito pelo do lead'
    );
}

/* ===================================================================== */
secao('Bloco A — documento é fato: acompanha SEM ser copiado');
/* ===================================================================== */

$cardAnexo = novoCard($PREFIXO . ' Anexos', $ACC_A, $COL_A);
$alvoAnexo = Entidade::resolver('card', $cardAnexo, [$ACC_A]);

// Registra a LINHA. O arquivo em si é do endpoint; aqui o que está em teste é
// dono, escopo e auditoria, e isso não precisa de bytes no disco.
$anexo1 = Anexo::registrar($alvoAnexo, '/uploads/crm/card/x/teste1.pdf', 'contrato.pdf', 'application/pdf', 1234, 'Contrato assinado', $USER);
$criados['anexos'][] = $anexo1;
ok($anexo1 > 0, 'documento registrado na prospecção');

$lista = Anexo::listar('card', $cardAnexo, [$ACC_A]);
ok(count($lista) === 1 && $lista[0]['file_name'] === 'contrato.pdf', 'aparece na listagem da prospecção');
ok(!array_key_exists('file_path', $lista[0]), 'file_path NÃO sai na listagem (só download_url)');
ok(
    ($lista[0]['download_url'] ?? '') === '/api/crm_anexos.php?action=download&id=' . $anexo1,
    'a listagem entrega a URL do endpoint autenticado'
);

$ra = ConversaoCliente::converter($cardAnexo, $ACC_A, [$ACC_A], $USER);
ok($ra['ok'] === true, 'conversão da prospecção com documento: ' . ($ra['erro'] ?? 'sem erro'));
$cliAnexo = (int) ($ra['cliente_id'] ?? 0);
if ($cliAnexo) {
    $criados['clientes'][] = $cliAnexo;

    $listaCli = Anexo::listar('cliente', $cliAnexo, [$ACC_A]);
    ok(count($listaCli) === 1, 'o documento aparece na ficha do cliente');
    ok(($listaCli[0]['fase'] ?? '') === 'prospeccao', 'e vem marcado como "da prospecção"');

    // A prova de que NÃO copiou: a linha continua sendo de card, e existe UMA só.
    ok(($listaCli[0]['entidade'] ?? '') === 'card', 'a linha continua pertencendo à prospecção (leitura, não cópia)');
    $totalLinhas = (int) $pdo->query(
        "SELECT COUNT(*) FROM crm_anexos WHERE file_name = 'contrato.pdf' AND deleted_at IS NULL
           AND entidade_id IN ($cardAnexo, $cliAnexo)"
    )->fetchColumn();
    ok($totalLinhas === 1, 'existe UMA linha de anexo, não duas: a conversão não duplicou');

    ok(Anexo::contar('cliente', $cliAnexo, [$ACC_A]) === 1, 'o contador da ficha do cliente vê o documento');

    // Isolamento: a conta B não vê nada disso.
    ok(Anexo::listar('cliente', $cliAnexo, [$ACC_B]) === [], 'a conta B não vê o documento do cliente da conta A');
    ok(Anexo::listar('card', $cardAnexo, [$ACC_B]) === [], 'a conta B não vê o documento da prospecção da conta A');
    ok(Anexo::buscar($anexo1, [$ACC_B]) === null, 'buscar o anexo pela conta B devolve null');
}

// Remoção: a linha fica marcada, sai das listas, e o evento entra no histórico.
ok(Anexo::remover($anexo1, $alvoAnexo, $USER, 'contrato.pdf') === true, 'documento removido');
ok(Anexo::listar('card', $cardAnexo, [$ACC_A]) === [], 'sai da listagem depois de removido');
$aindaExiste = (int) $pdo->query("SELECT COUNT(*) FROM crm_anexos WHERE id = $anexo1")->fetchColumn();
ok($aindaExiste === 1, 'a LINHA continua no banco (o nome do documento é prova)');
ok(Anexo::remover($anexo1, $alvoAnexo, $USER, 'contrato.pdf') === false, 'remover duas vezes é recusado (não apaga arquivo alheio)');

$evRem = (int) $pdo->query(
    "SELECT COUNT(*) FROM card_history WHERE card_id = $cardAnexo AND acao = 'anexo_removido'"
)->fetchColumn();
ok($evRem === 1, 'a remoção gerou evento no histórico');

/* ===================================================================== */
secao('Bloco D — interação é fato: acompanha, e aparece UMA vez na timeline');
/* ===================================================================== */

$cardInt = novoCard($PREFIXO . ' Interacoes', $ACC_A, $COL_A);
$alvoInt = Entidade::resolver('card', $cardInt, [$ACC_A]);

$int1 = Interacao::registrar($alvoInt, [
    'tipo'        => 'ligacao',
    'direcao'     => 'saida',
    'assunto'     => 'Primeiro contato',
    'conteudo'    => 'Explicado o serviço.',
    'ocorrido_em' => '2024-01-10 15:30:00',
    'duracao_min' => 18,
], $USER);
$criados['interacoes'][] = $int1;
ok($int1 > 0, 'ligação registrada na prospecção');

$int2 = Interacao::registrar($alvoInt, ['tipo' => 'nota', 'conteudo' => 'Cliente pediu para ligar de manhã.'], $USER);
$criados['interacoes'][] = $int2;

// Nota interna não tem direção: ninguém ligou para ninguém.
$st = $pdo->prepare('SELECT direcao FROM crm_interacoes WHERE id = ?');
$st->execute([$int2]);
ok($st->fetchColumn() === null, "nota interna não guarda direção");

// Registro sem assunto e sem conteúdo é recusado: registro vazio não é registro.
$erroVazio = false;
try { Interacao::registrar($alvoInt, ['tipo' => 'ligacao'], $USER); }
catch (\InvalidArgumentException $e) { $erroVazio = true; }
ok($erroVazio, 'interação sem assunto e sem conteúdo é recusada');

$listaInt = Interacao::listar('card', $cardInt, [$ACC_A]);
ok(count($listaInt) === 2, 'as duas interações aparecem na prospecção');

// `ocorrido_em` de 2024 com `created_at` de hoje: o registro é retroativo, e a
// listagem precisa dizer isso, senão pareceria digitado na hora.
$aLigacao = null;
foreach ($listaInt as $i) { if ((int) $i['id'] === $int1) { $aLigacao = $i; } }
ok($aLigacao !== null && !empty($aLigacao['retroativo']), 'a interação retroativa vem marcada como "anotada depois"');
ok($aLigacao !== null && $aLigacao['tipo_rotulo'] === 'Ligação', 'o rótulo do tipo vem pronto para a tela');

// A TIMELINE: a interação entra na data do FATO, não na de digitação.
$tlCard = Timeline::paraCard($cardInt, [$ACC_A]);
$evInt  = array_values(array_filter($tlCard, fn ($e) => $e['acao'] === 'interacao_registrada'));
ok(count($evInt) === 1, 'a ligação aparece UMA vez na timeline da prospecção');
ok(
    count($evInt) === 1 && strpos((string) $evInt[0]['quando'], '2024-01-10') === 0,
    'e na data em que ACONTECEU (2024-01-10), não na de hoje'
);
ok(count($evInt) === 1 && $evInt[0]['categoria'] === 'interacoes', 'classificada na categoria interacoes');

$evNota = array_values(array_filter($tlCard, fn ($e) => $e['acao'] === 'nota_interna'));
ok(count($evNota) === 1, 'a nota interna aparece UMA vez na timeline');

// A prova de não duplicação: registrar interação NÃO grava evento de auditoria.
$auditInt = (int) $pdo->query(
    "SELECT COUNT(*) FROM card_history
      WHERE card_id = $cardInt AND acao IN ('interacao_registrada','nota_interna')"
)->fetchColumn();
ok($auditInt === 0, 'registrar interação não grava em card_history: a linha da tabela É a prova');

$ri = ConversaoCliente::converter($cardInt, $ACC_A, [$ACC_A], $USER);
ok($ri['ok'] === true, 'conversão da prospecção com interações: ' . ($ri['erro'] ?? 'sem erro'));
$cliInt = (int) ($ri['cliente_id'] ?? 0);
if ($cliInt) {
    $criados['clientes'][] = $cliInt;

    $listaCliInt = Interacao::listar('cliente', $cliInt, [$ACC_A]);
    ok(count($listaCliInt) === 2, 'as duas interações acompanharam para a ficha do cliente');
    ok(($listaCliInt[0]['entidade'] ?? '') === 'card', 'continuam pertencendo à prospecção (leitura, não cópia)');

    $totalInt = (int) $pdo->query(
        "SELECT COUNT(*) FROM crm_interacoes WHERE entidade_id IN ($cardInt, $cliInt) AND deleted_at IS NULL"
    )->fetchColumn();
    ok($totalInt === 2, 'existem DUAS linhas de interação, não quatro: a conversão não duplicou');

    // E na timeline do cliente, cada uma continua aparecendo UMA vez.
    $tlCli   = Timeline::paraCliente($cliInt, [$ACC_A]);
    $nLig    = count(array_filter($tlCli, fn ($e) => $e['acao'] === 'interacao_registrada'));
    $nNota   = count(array_filter($tlCli, fn ($e) => $e['acao'] === 'nota_interna'));
    ok($nLig === 1, 'a ligação aparece UMA vez na timeline do cliente');
    ok($nNota === 1, 'a nota aparece UMA vez na timeline do cliente');

    // Isolamento.
    ok(Interacao::listar('cliente', $cliInt, [$ACC_B]) === [], 'a conta B não vê as interações');
    ok(Interacao::buscar($int1, [$ACC_B]) === null, 'buscar a interação pela conta B devolve null');

    // Timeline do cliente pela conta B não pode devolver evento nenhum.
    ok(Timeline::paraCliente($cliInt, [$ACC_B]) === [], 'a timeline do cliente pela conta B vem vazia');
}

// Editar audita o que mudou; remover é soft e deixa rastro.
ok(Interacao::atualizar($int1, [$ACC_A], ['assunto' => 'Primeiro contato (corrigido)'], $USER) === true, 'interação editada');
$evEd = (int) $pdo->query(
    "SELECT COUNT(*) FROM card_history WHERE card_id = $cardInt AND acao = 'interacao_editada'"
)->fetchColumn();
ok($evEd >= 1, 'a edição gerou evento no histórico');
ok(Interacao::atualizar($int1, [$ACC_A], ['assunto' => 'Primeiro contato (corrigido)'], $USER) === false, 'editar sem mudar nada é inerte');
ok(Interacao::atualizar($int1, [$ACC_B], ['assunto' => 'Invasao'], $USER) === false, 'a conta B não consegue editar');

ok(Interacao::remover($int2, [$ACC_A], $USER) === true, 'nota removida');
ok(Interacao::remover($int2, [$ACC_A], $USER) === false, 'remover duas vezes é recusado');
$sobrouInt = (int) $pdo->query("SELECT COUNT(*) FROM crm_interacoes WHERE id = $int2")->fetchColumn();
ok($sobrouInt === 1, 'a linha da nota continua no banco (soft delete)');

/* ===================================================================== */
secao('Isolamento do portão: Entidade::resolver e o escopo de leitura');
/* ===================================================================== */

$cardB = novoCard($PREFIXO . ' DaContaB', $ACC_B, null);

ok(Entidade::resolver('card', $cardB, [$ACC_A]) === null, 'card da conta B não resolve pela conta A');
ok(Entidade::resolver('card', $cardB, [$ACC_B]) !== null, 'e resolve pela própria conta B');
ok(Entidade::resolver('card', 999999999, [$ACC_A]) === null, 'id inexistente devolve null (mesmo null de "não é seu")');
ok(Entidade::resolver('processo', $cardB, [$ACC_B]) === null, 'tipo de entidade fora do ENUM é recusado antes do SQL');

// resolver() devolve a conta REAL da entidade, não a da sessão. Uma sessão que
// alcança as duas contas anexando em card da B tem de gravar dono B.
$alvoB = Entidade::resolver('card', $cardB, [$ACC_A, $ACC_B]);
ok($alvoB !== null && (int) $alvoB['account_id'] === $ACC_B, 'resolver devolve a conta DONA, não a primeira acessível');

$anexoB = Anexo::registrar($alvoB, '/uploads/crm/card/y/b.pdf', 'daContaB.pdf', 'application/pdf', 10, null, $USER);
$criados['anexos'][] = $anexoB;
$donoB = (int) $pdo->query("SELECT account_id FROM crm_anexos WHERE id = $anexoB")->fetchColumn();
ok($donoB === $ACC_B, 'o anexo nasceu com dono = conta da entidade (B), não a da sessão');

// Escopo vazio não pode virar "sem filtro".
[$sqlVazio, $paramsVazio] = Entidade::whereEscopo([], []);
ok($sqlVazio === '1 = 0' && $paramsVazio === [], 'escopo vazio gera WHERE que casa nada, nunca WHERE aberto');

/* ===================================================================== */
secao('Transação: conversão que falha não deixa etiqueta copiada');
/* ===================================================================== */

// A conta B (sem setor de cliente, ou com id que não resolve) faz a conversão
// falhar DEPOIS do ponto onde as etiquetas seriam copiadas? Não: elas são
// copiadas perto do fim. Para provar o rollback de verdade, forçamos a falha na
// corrida — o UPDATE final com `cliente_id IS NULL` — convertendo duas vezes.
$cardRb = novoCard($PREFIXO . ' Rollback', $ACC_A, $COL_A);
$alvoRb = Entidade::resolver('card', $cardRb, [$ACC_A]);
$tagRb  = Tag::criar($ACC_A, $PREFIXO . ' Rb', null, $USER);
$criados['tags'][] = (int) $tagRb['id'];
Tag::aplicar($alvoRb, (int) $tagRb['id'], [$ACC_A], $USER);

$primeira = ConversaoCliente::converter($cardRb, $ACC_A, [$ACC_A], $USER);
ok($primeira['ok'] === true, 'primeira conversão passa');
if ($primeira['ok']) { $criados['clientes'][] = $primeira['cliente_id']; }

$segunda = ConversaoCliente::converter($cardRb, $ACC_A, [$ACC_A], $USER);
ok($segunda['ok'] === false, 'segunda conversão da MESMA prospecção é recusada');

// E o mais importante: a recusa não deixou lixo. Só o cliente da primeira tem a
// etiqueta, e nenhum cliente novo nasceu.
$clientesComTag = (int) $pdo->query(
    "SELECT COUNT(*) FROM crm_tag_vinculos WHERE tag_id = {$tagRb['id']} AND entidade = 'cliente'"
)->fetchColumn();
ok($clientesComTag === 1, 'a etiqueta foi copiada UMA vez só (a segunda tentativa não duplicou)');

$clientesDoPrefixo = (int) $pdo->query(
    "SELECT COUNT(*) FROM clientes WHERE nome = '" . $PREFIXO . " Rollback'"
)->fetchColumn();
ok($clientesDoPrefixo === 1, 'a tentativa recusada não criou um segundo cliente');

/* ===================================================================== */
secao('Compromisso ligado direto ao cliente (task_links.link_type = cliente)');
/* ===================================================================== */

$boardA = (int) $pdo->query("SELECT id FROM task_boards WHERE account_id = $ACC_A ORDER BY id LIMIT 1")->fetchColumn();

if (!$boardA) {
    echo "  [SKIP] conta A sem quadro de tarefas: o teste de compromisso não pode rodar\n";
} else {
    $colTask = (int) $pdo->query("SELECT id FROM task_columns WHERE board_id = $boardA ORDER BY ordem, id LIMIT 1")->fetchColumn();
    // criado_por_id tem FK para users e não aceita nulo: `tasks` é uma das
    // poucas tabelas do schema com chave estrangeira de verdade.
    $pdo->prepare(
        'INSERT INTO tasks (board_id, column_id, titulo, status, criado_por_id, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
    )->execute([$boardA, $colTask ?: null, $PREFIXO . ' Compromisso', 'ativa', $USER]);
    $taskId = (int) $pdo->lastInsertId();
    $criados['tasks'][] = $taskId;

    $cliTask = (int) $pdo->query(
        "SELECT id FROM clientes WHERE account_id = $ACC_A AND deleted_at IS NULL ORDER BY id DESC LIMIT 1"
    )->fetchColumn();

    // O ENUM tem de aceitar 'cliente'. Sem a migration 127 este INSERT falha.
    $aceitou = true;
    try {
        $pdo->prepare("INSERT INTO task_links (task_id, link_type, link_id) VALUES (?, 'cliente', ?)")
            ->execute([$taskId, $cliTask]);
    } catch (\Throwable $e) {
        $aceitou = false;
    }
    ok($aceitou, "task_links aceita link_type = 'cliente' (migration 127)");

    if ($aceitou) {
        $tarefas = VinculosCliente::tarefas($cliTask, [$ACC_A]);
        $ids     = array_map(fn ($t) => (int) $t['id'], $tarefas);
        ok(in_array($taskId, $ids, true), 'a tarefa ligada direto ao cliente aparece na ficha dele');

        // Isolamento REAL: a mesma consulta pela conta B não pode ver a tarefa.
        // O JOIN em task_boards é o que garante isso, porque `tasks` não tem
        // account_id próprio.
        $porB = VinculosCliente::tarefas($cliTask, [$ACC_B]);
        ok($porB === [], 'a mesma consulta pela conta B não devolve a tarefa (JOIN em task_boards)');

        // E não duplica quando a tarefa está ligada ao cliente E a um card dele.
        $cardDoCli = (int) $pdo->query(
            "SELECT id FROM cards WHERE cliente_id = $cliTask AND account_id = $ACC_A LIMIT 1"
        )->fetchColumn();
        if ($cardDoCli) {
            $pdo->prepare("INSERT INTO task_links (task_id, link_type, link_id) VALUES (?, 'card', ?)")
                ->execute([$taskId, $cardDoCli]);
            $tarefas2 = VinculosCliente::tarefas($cliTask, [$ACC_A]);
            $quantas  = count(array_filter($tarefas2, fn ($t) => (int) $t['id'] === $taskId));
            ok($quantas === 1, 'tarefa ligada ao cliente E ao card aparece UMA vez só');
        }
    }
}

/* ===================================================================== */
secao('Imutabilidade de clientes_history (migration 127)');
/* ===================================================================== */

$linhaHist = (int) $pdo->query('SELECT id FROM clientes_history ORDER BY id DESC LIMIT 1')->fetchColumn();
if ($linhaHist) {
    $bloqueouUpdate = false;
    try { $pdo->prepare('UPDATE clientes_history SET acao = ? WHERE id = ?')->execute(['adulterado', $linhaHist]); }
    catch (\Throwable $e) { $bloqueouUpdate = true; }
    ok($bloqueouUpdate, 'UPDATE em clientes_history é bloqueado por trigger');

    $bloqueouDelete = false;
    try { $pdo->prepare('DELETE FROM clientes_history WHERE id = ?')->execute([$linhaHist]); }
    catch (\Throwable $e) { $bloqueouDelete = true; }
    ok($bloqueouDelete, 'DELETE em clientes_history é bloqueado por trigger');
} else {
    echo "  [SKIP] clientes_history vazio\n";
}

/* ===================================================================== */
/* limpeza                                                                */
/* ===================================================================== */

echo "\n== limpeza ==\n";
$rem = ['cards' => 0, 'clientes' => 0, 'tags' => 0, 'campos' => 0, 'anexos' => 0, 'interacoes' => 0, 'tasks' => 0];

// Ordem importa: vínculos e valores antes dos catálogos, senão sobra órfão.
foreach (array_unique($criados['anexos']) as $id) {
    try { $pdo->prepare('DELETE FROM crm_anexos WHERE id = ?')->execute([$id]); $rem['anexos']++; } catch (\Throwable $e) {}
}
foreach (array_unique($criados['interacoes']) as $id) {
    try { $pdo->prepare('DELETE FROM crm_interacoes WHERE id = ?')->execute([$id]); $rem['interacoes']++; } catch (\Throwable $e) {}
}
foreach (array_unique($criados['tags']) as $id) {
    try {
        $pdo->prepare('DELETE FROM crm_tag_vinculos WHERE tag_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM crm_tags WHERE id = ?')->execute([$id]);
        $rem['tags']++;
    } catch (\Throwable $e) {}
}
foreach (array_unique($criados['campos']) as $id) {
    try {
        $pdo->prepare('DELETE FROM crm_campo_valores WHERE campo_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM crm_campos WHERE id = ?')->execute([$id]);
        $rem['campos']++;
    } catch (\Throwable $e) {}
}
foreach (array_unique($criados['tasks']) as $id) {
    try {
        $pdo->prepare('DELETE FROM task_links WHERE task_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM tasks WHERE id = ?')->execute([$id]);
        $rem['tasks']++;
    } catch (\Throwable $e) {}
}
foreach (array_unique($criados['clientes']) as $id) {
    try { $pdo->prepare('DELETE FROM clientes WHERE id = ?')->execute([$id]); $rem['clientes']++; } catch (\Throwable $e) {}
}
foreach (array_unique($criados['cards']) as $id) {
    try { $pdo->prepare('DELETE FROM cards WHERE id = ?')->execute([$id]); $rem['cards']++; } catch (\Throwable $e) {}
}
try { $pdo->prepare('DELETE FROM contatos WHERE nome LIKE ?')->execute([$PREFIXO . '%']); } catch (\Throwable $e) {}

echo "  removidos: {$rem['cards']} prospecções, {$rem['clientes']} clientes, {$rem['tags']} etiquetas, "
   . "{$rem['campos']} campos, {$rem['anexos']} anexos, {$rem['interacoes']} interações, {$rem['tasks']} tarefas\n";
echo "  histórico de auditoria permanece (imutável por trigger), órfão e invisível na timeline\n";

// A limpeza também é asserção: teste que deixa lixo envenena a próxima rodada.
$sobrouCard = (int) $pdo->query("SELECT COUNT(*) FROM cards WHERE cliente_nome LIKE '$PREFIXO%'")->fetchColumn();
$sobrouTag  = (int) $pdo->query("SELECT COUNT(*) FROM crm_tags WHERE nome LIKE '$PREFIXO%'")->fetchColumn();
$sobrouCamp = (int) $pdo->query("SELECT COUNT(*) FROM crm_campos WHERE rotulo LIKE '$PREFIXO%'")->fetchColumn();
ok($sobrouCard === 0, 'nenhuma prospecção de teste ficou no banco');
ok($sobrouTag === 0, 'nenhuma etiqueta de teste ficou no catálogo');
ok($sobrouCamp === 0, 'nenhum campo personalizado de teste ficou no catálogo');

printf("\n%s  %d PASS, %d FAIL\n", $FAILS === 0 ? 'OK' : 'FALHOU', $PASSES, $FAILS);
exit($FAILS === 0 ? 0 : 1);
