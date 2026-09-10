<?php
/**
 * relatorios_test.php — o módulo de relatórios.
 *
 * O que está sob teste não é "monta um HTML". É o conjunto de coisas que fazem
 * um relatório ser confiável o bastante para uma advogada levar a uma reunião ou
 * juntar a um processo:
 *
 *  - o dossiê NÃO enxerga registro de outro escritório, e responde igual para
 *    "não existe", "foi apagado" e "não é seu"
 *  - o histórico do processo sai COMPLETO, sem o LIMIT 50 que a tela usava
 *  - o documento anexado enquanto a pessoa era prospecção continua no dossiê do
 *    cliente, porque a conversão não copia arquivo
 *  - a listagem filtra de verdade e não aceita ordenação vinda do usuário
 *  - o CSV abre no Excel em português, com acento certo, e não executa fórmula
 *
 * ESCREVE NO BANCO. Não rode em produção.
 *
 * Uso local: C:\xampp\php\php.exe scripts/tests/relatorios_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Core\Timeline;
use App\Relatorios\Dossie;
use App\Relatorios\Listagem;
use App\Relatorios\Planilha;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

$FAILS = 0; $PASSES = 0;
function pass(string $m): void { global $PASSES; $PASSES++; echo "  [PASS] $m\n"; }
function fail(string $m): void { global $FAILS;  $FAILS++;  echo "  [FAIL] $m\n"; }
function secao(string $t): void { echo "\n== $t ==\n"; }
function ok(bool $c, string $m): void { $c ? pass($m) : fail($m); }
function eq($esp, $obt, string $m): void {
    if ($esp === $obt) { pass($m); return; }
    fail($m . ' (esperado ' . var_export($esp, true) . ', obtido ' . var_export($obt, true) . ')');
}

/** Acha o bloco pela chave. Falha explícita é melhor que null silencioso. */
function bloco(array $doc, string $chave): ?array {
    foreach ($doc['blocos'] as $b) { if ($b['chave'] === $chave) return $b; }
    return null;
}

$PREFIXO = 'TESTE-REL';

$ACC = (int) $pdo->query('SELECT id FROM accounts ORDER BY id LIMIT 1')->fetchColumn();
if (!$ACC) { echo "SKIP: nenhuma conta cadastrada.\n"; exit(0); }
$ACC_B = (int) $pdo->query("SELECT id FROM accounts WHERE id <> $ACC ORDER BY id LIMIT 1")->fetchColumn();
$COL   = (int) $pdo->query("SELECT id FROM pipeline_columns WHERE account_id = $ACC ORDER BY ordem, id LIMIT 1")->fetchColumn();
$USER  = (int) $pdo->query("SELECT id FROM users WHERE account_id = $ACC AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
echo "Cenário: conta $ACC (outra: " . ($ACC_B ?: 'nenhuma') . "), funil $COL, usuário $USER\n";

/*
 * Limpeza defensiva ANTES: uma rodada morta deixaria registro contando na
 * listagem e o teste de total daria falso negativo sem ninguém entender.
 *
 * AS TABELAS DE HISTÓRICO NÃO SÃO LIMPAS, E NÃO PODEM SER.
 *
 * `card_history`, `clientes_history` e `processo_history` têm trigger de
 * imutabilidade (migration 053, LGPD Art. 37): UPDATE e DELETE são recusados
 * pelo banco. Tentar apagar aqui derruba o teste inteiro com
 * "card_history e imutavel", que foi exatamente o que aconteceu na primeira
 * rodada.
 *
 * Isso NÃO é sujeira perigosa: as linhas ficam órfãs, apontando para um card ou
 * processo que não existe mais, e toda leitura passa por JOIN na tabela dona
 * (que é o que garante o isolamento por conta). Sem o pai, elas não aparecem em
 * lugar nenhum. O teste confere isso no fim, em vez de fingir que limpou.
 *
 * `clientes_history` este teste nem gera: a fase 'cliente' da timeline é montada
 * por `crm_interacoes`, que é apagável.
 */
$limpar = function () use ($pdo, $PREFIXO) {
    $pdo->exec("DELETE FROM crm_interacoes WHERE assunto   LIKE '$PREFIXO%'");
    $pdo->exec("DELETE FROM crm_anexos     WHERE file_name LIKE '$PREFIXO%'");
    $pdo->exec("DELETE z FROM processo_prazos  z JOIN processos p ON p.id = z.processo_id  WHERE p.cliente_nome LIKE '$PREFIXO%'");
    $pdo->exec("DELETE t FROM processo_tarefas t JOIN processos p ON p.id = t.processo_id  WHERE p.cliente_nome LIKE '$PREFIXO%'");
    $pdo->exec("DELETE FROM processos WHERE cliente_nome LIKE '$PREFIXO%'");
    $pdo->exec("DELETE FROM cards     WHERE cliente_nome LIKE '$PREFIXO%'");
    $pdo->exec("DELETE FROM clientes  WHERE nome         LIKE '$PREFIXO%'");
};
$limpar();

/* ===================================================================== */
/* cenário                                                                */
/* ===================================================================== */

$pdo->prepare("INSERT INTO clientes (account_id, nome, status, cpf_cnpj, telefone, created_at)
               VALUES (?, ?, 'ativo', '111.222.333-44', '11999990000', NOW())")
    ->execute([$ACC, "$PREFIXO Cliente Convertido"]);
$CLI = (int) $pdo->lastInsertId();

// A prospecção que virou este cliente. É ela que faz a timeline não recomeçar.
$pdo->prepare("INSERT INTO cards (account_id, cliente_nome, coluna_id, status, cliente_id, convertido_em, created_at, updated_at)
               VALUES (?, ?, ?, 'aberto', ?, NOW(), NOW(), NOW())")
    ->execute([$ACC, "$PREFIXO Prospeccao De Origem", $COL, $CLI]);
$CARD = (int) $pdo->lastInsertId();

// Uma prospecção solta, que nunca virou cliente.
$pdo->prepare("INSERT INTO cards (account_id, cliente_nome, coluna_id, status, created_at, updated_at)
               VALUES (?, ?, ?, 'aberto', NOW(), NOW())")
    ->execute([$ACC, "$PREFIXO Prospeccao Solta", $COL]);
$CARD_SOLTO = (int) $pdo->lastInsertId();

$pdo->prepare("INSERT INTO processos (account_id, numero, cliente_nome, tipo_acao, status, cliente_id, data_inicio, created_at)
               VALUES (?, ?, ?, 'Trabalhista', 'ativo', ?, CURDATE(), NOW())")
    ->execute([$ACC, "$PREFIXO-0001", "$PREFIXO Cliente Convertido", $CLI]);
$PROC = (int) $pdo->lastInsertId();

/* ===================================================================== */
secao('O dossiê monta as três entidades');

foreach (['cliente' => $CLI, 'card' => $CARD, 'processo' => $PROC] as $ent => $id) {
    $d = Dossie::montar($ent, $id, [$ACC]);
    ok(is_array($d), "monta o dossiê de $ent");
    if (!is_array($d)) { continue; }
    eq($ent, $d['entidade'], "  entidade devolvida é $ent");
    eq($id, $d['id'], '  id devolvido é o pedido');
    ok(($d['titulo'] ?? '') !== '', '  tem título');
    ok(($d['blocos'] ?? []) !== [], '  tem blocos');
    ok(bloco($d, 'timeline') !== null, '  tem linha do tempo');
}

/* ===================================================================== */
secao('Isolamento: os três casos respondem IGUAL');

eq(null, Dossie::montar('cliente', $CLI, [999999]), 'conta que não é dona não vê o cliente');
eq(null, Dossie::montar('processo', $PROC, [999999]), 'nem o processo');
eq(null, Dossie::montar('card', $CARD, [999999]), 'nem a prospecção');
eq(null, Dossie::montar('cliente', 99999999, [$ACC]), 'id inexistente devolve null (o MESMO null)');
eq(null, Dossie::montar('cliente', 0, [$ACC]), 'id zero devolve null');
eq(null, Dossie::montar('cliente', $CLI, []), 'escopo de contas VAZIO devolve null, nunca "tudo"');
eq(null, Dossie::montar('xxx', $CLI, [$ACC]), 'entidade inválida devolve null');

if ($ACC_B) {
    eq(null, Dossie::montar('cliente', $CLI, [$ACC_B]), 'a outra conta real também não vê');
}

// Apagado responde igual a inexistente.
$pdo->prepare('UPDATE cards SET deleted_at = NOW() WHERE id = ?')->execute([$CARD_SOLTO]);
eq(null, Dossie::montar('card', $CARD_SOLTO, [$ACC]), 'prospecção apagada devolve null');
$pdo->prepare('UPDATE cards SET deleted_at = NULL WHERE id = ?')->execute([$CARD_SOLTO]);
ok(Dossie::montar('card', $CARD_SOLTO, [$ACC]) !== null, 'e volta a aparecer quando restaurada');

/* ===================================================================== */
secao('O histórico do processo sai COMPLETO');

/*
 * A tela lia processo_history com LIMIT 50 fixo. Um relatório que promete
 * "histórico completo" não pode cortar em 50 e não avisar. 60 linhas é o
 * mínimo que prova que o limite saiu.
 */
$ins = $pdo->prepare("INSERT INTO processo_history (processo_id, user_email, acao, descricao, created_at)
                      VALUES (?, 'teste@teste', 'Movimentação', ?, DATE_SUB(NOW(), INTERVAL ? MINUTE))");
for ($i = 1; $i <= 60; $i++) {
    $ins->execute([$PROC, "$PREFIXO movimento $i", $i]);
}
$eventos = Timeline::paraProcesso($PROC, [$ACC]);
ok(count($eventos) >= 60, 'os 60 movimentos vêm todos (o LIMIT 50 saiu): ' . count($eventos));

// Prazo e tarefa também entram no rastro, e antes não entravam em lugar nenhum.
$pdo->prepare("INSERT INTO processo_prazos (processo_id, descricao, data_limite, status, created_at)
               VALUES (?, ?, DATE_ADD(CURDATE(), INTERVAL 10 DAY), 'pendente', NOW())")
    ->execute([$PROC, "$PREFIXO prazo de contestação"]);
$pdo->prepare("INSERT INTO processo_tarefas (processo_id, titulo, concluido, created_at)
               VALUES (?, ?, 0, NOW())")
    ->execute([$PROC, "$PREFIXO juntar procuração"]);

$eventos = Timeline::paraProcesso($PROC, [$ACC]);
$acoes   = array_column($eventos, 'acao');
ok(in_array('prazo_processo', $acoes, true),    'o prazo cadastrado virou evento da linha do tempo');
ok(in_array('tarefa_registrada', $acoes, true), 'a tarefa do processo também');

// A data-limite vai no TEXTO, não no `quando`: um vencimento futuro no `quando`
// jogaria o evento para o topo antes de ele existir.
$hoje = date('Y-m-d');
foreach ($eventos as $e) {
    if ($e['acao'] === 'prazo_processo') {
        ok(substr((string) $e['quando'], 0, 10) <= $hoje, 'o prazo entra pela data de CADASTRO, não pelo vencimento futuro');
        ok(str_contains((string) $e['para'], 'limite'), 'e o vencimento aparece no texto do evento');
        break;
    }
}

eq([], Timeline::paraProcesso($PROC, [999999]), 'processo de outra conta não devolve histórico');
eq([], Timeline::paraProcesso($PROC, []),       'escopo vazio não devolve histórico');
eq([], Timeline::paraProcesso(0, [$ACC]),       'id zero não devolve histórico');

$d = Dossie::montar('processo', $PROC, [$ACC]);
ok(bloco($d, 'prazos')['total'] >= 1,   'o dossiê do processo lista o prazo');
ok(bloco($d, 'tarefas')['total'] >= 1,  'e a tarefa');
ok(bloco($d, 'timeline')['total'] >= 62,'e a linha do tempo inteira');

/* ===================================================================== */
secao('O que a pessoa deixou enquanto era prospecção continua no dossiê do cliente');

/*
 * É a regra da Fase 2: FATO não é copiado na conversão, é HERDADO pelo escopo
 * de leitura. Se este teste falhar, o contrato anexado antes da conversão some
 * do relatório do cliente, que é justamente o documento que ela vai juntar.
 */
$pdo->prepare("INSERT INTO crm_anexos (account_id, entidade, entidade_id, file_path, file_name, mime_type, file_size, uploaded_by, created_at)
               VALUES (?, 'card', ?, 'teste/nao-existe.pdf', ?, 'application/pdf', 1024, ?, NOW())")
    ->execute([$ACC, $CARD, "$PREFIXO contrato.pdf", $USER ?: null]);

$d = Dossie::montar('cliente', $CLI, [$ACC]);
$docs = bloco($d, 'documentos');
ok($docs['total'] >= 1, 'o documento anexado na prospecção aparece no dossiê do CLIENTE');
$nomes = array_column($docs['linhas'], 0);
ok(in_array("$PREFIXO contrato.pdf", $nomes, true), 'e com o nome certo do arquivo');

$prosp = bloco($d, 'prospeccoes');
ok($prosp['total'] >= 1, 'a prospecção de origem é listada no dossiê do cliente');

$procs = bloco($d, 'processos');
ok($procs['total'] >= 1, 'o processo do cliente aparece no dossiê dele');

/* ===================================================================== */
secao('A etiqueta de FASE só aparece quando há mais de uma');

// Só card_history: uma fase só ('prospeccao').
$pdo->prepare("INSERT INTO card_history (card_id, usuario_id, acao, campo_alterado, valor_anterior, valor_novo, created_at)
               VALUES (?, ?, 'updated', 'telefone_whatsapp', '', '11988887777', NOW())")
    ->execute([$CARD, $USER ?: null]);

$d = Dossie::montar('card', $CARD, [$ACC]);
$fases = array_unique(array_column(bloco($d, 'timeline')['itens'], 'fase'));
eq([''], array_values($fases), 'na prospecção, com uma fase só, a etiqueta some (seria ruído em toda linha)');

// Agora um evento do lado CLIENTE: interação registrada na ficha do cliente.
$pdo->prepare("INSERT INTO crm_interacoes (account_id, entidade, entidade_id, tipo, direcao, assunto, conteudo, ocorrido_em, created_by, created_at)
               VALUES (?, 'cliente', ?, 'ligacao', 'saida', ?, 'conteudo de teste', NOW(), ?, NOW())")
    ->execute([$ACC, $CLI, "$PREFIXO ligacao pos conversao", $USER ?: null]);

$d = Dossie::montar('cliente', $CLI, [$ACC]);
$itens = bloco($d, 'timeline')['itens'];
$fases = array_values(array_unique(array_filter(array_column($itens, 'fase'))));
sort($fases);
eq(['cliente', 'prospeccao'], $fases, 'no cliente, com as DUAS fases, a etiqueta aparece e diz de onde veio cada evento');

$inter = bloco($d, 'interacoes');
ok($inter['total'] >= 1, 'a interação também entra no bloco de contatos e anotações');

/* ===================================================================== */
secao('A listagem filtra de verdade');

$todos = Listagem::montar('clientes', [], [$ACC]);
ok($todos['total'] >= 1, 'lista clientes da conta: ' . $todos['total']);

$porBusca = Listagem::montar('clientes', ['busca' => $PREFIXO], [$ACC]);
eq(1, $porBusca['total'], 'a busca por nome acha exatamente o cliente de teste');

$porStatus = Listagem::montar('clientes', ['busca' => $PREFIXO, 'status' => 'inativo'], [$ACC]);
eq(0, $porStatus['total'], 'filtrar por situação que ele não tem devolve zero');

$porStatusCerto = Listagem::montar('clientes', ['busca' => $PREFIXO, 'status' => 'ativo'], [$ACC]);
eq(1, $porStatusCerto['total'], 'e pela situação certa devolve ele');

$porProcesso = Listagem::montar('processos', ['busca' => $PREFIXO], [$ACC]);
eq(1, $porProcesso['total'], 'a busca em processos acha pelo número');

$porProsp = Listagem::montar('prospeccoes', ['busca' => $PREFIXO], [$ACC]);
eq(2, $porProsp['total'], 'e em prospecções acha as duas');

// Período: data impossível é IGNORADA em vez de virar filtro. Um relatório que
// zera por causa de um campo mal digitado parece sistema quebrado.
$comDataRuim = Listagem::montar('clientes', ['busca' => $PREFIXO, 'de' => '2026-13-45'], [$ACC]);
eq(1, $comDataRuim['total'], 'data impossível no filtro é ignorada, não zera o relatório');

$comDataFutura = Listagem::montar('clientes', ['busca' => $PREFIXO, 'de' => date('Y-m-d', strtotime('+2 days'))], [$ACC]);
eq(0, $comDataFutura['total'], 'mas data VÁLIDA filtra de verdade');

/* ===================================================================== */
secao('A ordenação nunca vem do usuário');

$ordens = ['nome', 'recente', 'antigo', 'setor', 'inexistente', "id; DROP TABLE clientes--", ''];
$totais = [];
foreach ($ordens as $o) {
    $r = Listagem::montar('clientes', ['busca' => $PREFIXO, 'ordem' => $o], [$ACC]);
    $totais[] = $r['total'];
}
eq(array_fill(0, count($ordens), 1), $totais, 'ordem desconhecida ou maliciosa cai no padrão e a consulta roda igual');
ok((int) $pdo->query('SELECT COUNT(*) FROM clientes')->fetchColumn() > 0, 'a tabela clientes continua existindo');

/* ===================================================================== */
secao('Listagem: isolamento e fonte inválida');

eq(0, Listagem::montar('clientes', ['busca' => $PREFIXO], [999999])['total'], 'outra conta não vê o cliente de teste');
eq(0, Listagem::montar('clientes', [], [])['total'], 'escopo vazio devolve lista vazia, nunca "tudo"');
eq(0, Listagem::montar('xxx', [], [$ACC])['total'], 'fonte inválida devolve vazio');
eq([], Listagem::montar('xxx', [], [$ACC])['colunas'], 'e sem colunas');

foreach (['clientes', 'prospeccoes', 'processos'] as $f) {
    $r = Listagem::montar($f, [], [$ACC]);
    ok(count($r['colunas']) > 0, "a fonte $f declara colunas");
    ok(count($r['linhas']) <= Listagem::LIMITE_MAXIMO, "  e nunca passa do teto de " . Listagem::LIMITE_MAXIMO);
    foreach ($r['colunas_livres'] as $i) {
        ok(isset($r['colunas'][$i]), "  a coluna livre #$i existe de verdade em $f");
    }
    if ($r['linhas']) {
        eq(count($r['colunas']), count($r['linhas'][0]), "  cada linha de $f tem uma célula por coluna");
    }
}

/* ===================================================================== */
secao('O CSV abre no Excel em português');

$csv = Planilha::conteudo(['Nome', 'Valor'], [['Prospecção', '1,50'], ['=SOMA(A1)', 'ok']], ['Cabeçalho']);

eq("\xEF\xBB\xBF", substr($csv, 0, 3), 'começa com BOM UTF-8 (sem ele o Excel lê "Prospecção" como "ProspecÃ§Ã£o")');
ok(str_contains($csv, '"Nome";"Valor"'), 'separa colunas por ponto e vírgula (a vírgula é o separador decimal em pt-BR)');
ok(str_contains($csv, "\r\n"), 'quebra de linha CRLF, que é o que o Excel do Windows espera');
ok(str_contains($csv, 'Prospecção'), 'o acento vai cru, em UTF-8');

// CSV injection: uma célula começando com = vira FÓRMULA no Excel.
ok(str_contains($csv, '"\'=SOMA(A1)"'), 'célula que começa com = é neutralizada com apóstrofo');
ok(!str_contains($csv, '"=SOMA(A1)"'), 'e a versão perigosa NÃO aparece no arquivo');

foreach (['+1', '-1', '@x', "\tx"] as $perigoso) {
    $c = Planilha::conteudo(['A'], [[$perigoso]]);
    ok(str_contains($c, '"\'' . $perigoso), 'também neutraliza célula começando com ' . trim($perigoso, "\t"));
}

$c = Planilha::conteudo(['A'], [['diz "oi"'], ["quebra\nde linha"], ['ponto;virgula']]);
ok(str_contains($c, '"diz ""oi"""'),      'aspas duplas são dobradas');
ok(str_contains($c, '"quebra de linha"'), 'quebra de linha dentro da célula vira espaço');
ok(str_contains($c, '"ponto;virgula"'),   'o separador dentro do texto fica protegido pelas aspas');

eq('relatorio.csv',       Planilha::nomeSeguro(''),                  'nome vazio vira relatorio.csv');
eq('etc_passwd.csv',      Planilha::nomeSeguro('../../etc/passwd'),  'caminho não escapa do nome do arquivo');
eq('rel_a_x.csv',         Planilha::nomeSeguro('rel"a; x'),          'aspas e ponto e vírgula não forjam o Content-Disposition');
eq('ok.csv',              Planilha::nomeSeguro('ok.csv'),            'nome que já é válido não muda');

/* ===================================================================== */
secao('O dossiê inteiro cabe no CSV');

$d   = Dossie::montar('processo', $PROC, [$ACC]);
$csv = Planilha::doDossie($d);
eq("\xEF\xBB\xBF", substr($csv, 0, 3), 'o CSV do dossiê também tem BOM');
ok(str_contains($csv, 'IDENTIFICAÇÃO'), 'traz a identificação');
foreach ($d['blocos'] as $b) {
    ok(str_contains($csv, mb_strtoupper((string) $b['titulo'])), 'traz o bloco ' . $b['titulo']);
}
ok(str_contains($csv, "$PREFIXO movimento 1"), 'e o conteúdo da linha do tempo de verdade');

/* ===================================================================== */
echo "\n== limpeza ==\n";
$limpar();
$sobra = 0;
foreach ([
    "SELECT COUNT(*) FROM clientes  WHERE nome         LIKE '$PREFIXO%'",
    "SELECT COUNT(*) FROM cards     WHERE cliente_nome LIKE '$PREFIXO%'",
    "SELECT COUNT(*) FROM processos WHERE cliente_nome LIKE '$PREFIXO%'",
    "SELECT COUNT(*) FROM crm_anexos     WHERE file_name LIKE '$PREFIXO%'",
    "SELECT COUNT(*) FROM crm_interacoes WHERE assunto   LIKE '$PREFIXO%'",
] as $sql) {
    $sobra += (int) $pdo->query($sql)->fetchColumn();
}
eq(0, $sobra, 'nenhum registro de teste ficou no banco');

/*
 * O histórico órfão: ele CONTINUA no banco, porque a lei manda, e este teste
 * prova que isso não faz mal. Sem o card e sem o processo, nada consegue
 * alcançá-lo, porque toda leitura passa por JOIN na tabela dona.
 */
$orfaos = (int) $pdo->query("SELECT COUNT(*) FROM processo_history WHERE descricao LIKE '$PREFIXO%'")->fetchColumn();
ok($orfaos > 0, "o histórico do processo continua no banco ($orfaos linhas): ele é imutável por lei");
eq([], Timeline::paraProcesso($PROC, [$ACC]), 'mas com o processo apagado ninguém alcança essas linhas');
eq(null, Dossie::montar('processo', $PROC, [$ACC]), 'e o dossiê do processo apagado responde null');
eq([], Timeline::paraCard($CARD, [$ACC]), 'idem para o histórico da prospecção apagada');

printf("\n%s  %d PASS, %d FAIL\n", $FAILS === 0 ? 'OK' : 'FALHOU', $PASSES, $FAILS);
exit($FAILS === 0 ? 0 : 1);
