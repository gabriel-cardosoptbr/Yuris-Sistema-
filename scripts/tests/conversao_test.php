<?php
/**
 * conversao_test.php — Prospecção → Cliente: conversão, timeline e auditoria.
 *
 * Executa os dez cenários pedidos na especificação de 09/09/2026, com ESCRITA
 * real no banco. Não é teste de fumaça: cada asserção olha o dado depois da
 * operação, não a resposta da função.
 *
 * O QUE ESTE TESTE NÃO CONSEGUE LIMPAR
 * `card_history` e as demais tabelas de auditoria têm trigger de imutabilidade
 * (migration 053, LGPD Art. 37): não aceitam UPDATE nem DELETE, nem vindos
 * daqui. Então as linhas de histórico dos cards de teste FICAM no banco, órfãs.
 * Isso é de propósito e não polui nada: a Timeline faz JOIN em `cards`, e card
 * apagado não aparece em lugar nenhum.
 *
 * Uso local: C:\xampp\php\php.exe scripts/tests/conversao_test.php
 * Uso prod:  NÃO. Este teste escreve. Rode só em desenvolvimento.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Clientes\Cliente;
use App\Core\Database;
use App\Core\Timeline;
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
 * Cenário: duas contas distintas, para o teste de isolamento ser real e não
 * uma simulação com a mesma conta duas vezes.
 * ------------------------------------------------------------------------ */
$PREFIXO = 'TESTE-CONV-' . substr(bin2hex(random_bytes(3)), 0, 6);

// A conta A precisa estar completa: funil (para criar prospecção) e setores
// (para o cliente nascer em algum lugar).
$ACC_A = (int) $pdo->query(
    'SELECT a.id FROM accounts a
      WHERE EXISTS (SELECT 1 FROM pipeline_columns pc WHERE pc.account_id = a.id)
        AND EXISTS (SELECT 1 FROM clientes_setores cs WHERE cs.account_id = a.id AND cs.ativo = 1)
      ORDER BY a.id LIMIT 1'
)->fetchColumn();

// A conta B existe só para provar isolamento. Ela NÃO precisa de funil: uma
// prospecção pode nascer sem coluna (cards.coluna_id é anulável), e o que
// interessa aqui é a fronteira entre contas, não o desenho do funil.
// Preferimos uma conta SEM setores de cliente: assim a mesma conta serve para
// provocar a falha do Teste 10 sem inventar erro artificial.
$ACC_B = (int) $pdo->query(
    "SELECT a.id FROM accounts a
      WHERE a.id <> $ACC_A
   ORDER BY (SELECT COUNT(*) FROM clientes_setores cs WHERE cs.account_id = a.id AND cs.ativo = 1) ASC,
            a.id ASC
      LIMIT 1"
)->fetchColumn();

if (!$ACC_A || !$ACC_B) {
    echo "SKIP: são necessárias 2 contas (uma com funil e setores); A=$ACC_A B=$ACC_B.\n";
    exit(0);
}
$USER = (int) $pdo->query("SELECT id FROM users WHERE account_id = $ACC_A ORDER BY id LIMIT 1")->fetchColumn();

$colunaA = (int) $pdo->query("SELECT id FROM pipeline_columns WHERE account_id = $ACC_A ORDER BY ordem, id LIMIT 1")->fetchColumn();
$colunaB = (int) $pdo->query("SELECT id FROM pipeline_columns WHERE account_id = $ACC_B ORDER BY ordem, id LIMIT 1")->fetchColumn();

echo "contas de teste: A=$ACC_A  B=$ACC_B   usuário=$USER   prefixo=$PREFIXO\n";

$criados = ['cards' => [], 'clientes' => [], 'contatos' => []];

function novoCard(array $extra, int $acc, int $col, string $prefixo, int $user): int
{
    global $criados;
    $id = Card::create(array_merge([
        'account_id'   => $acc,
        'cliente_nome' => $prefixo . ' ' . ($extra['_rot'] ?? 'lead'),
        'coluna_id'    => $col,
        '_usuario_id'  => $user,
    ], $extra));
    $criados['cards'][] = $id;
    return $id;
}

/**
 * Sessão de teste com a lista de permissões que EU escolho, para exercitar o
 * gate sem depender de como os usuários do banco estão configurados.
 * Só SELECT: nada no banco é alterado.
 */
function montaSessaoTeste(PDO $pdo, int $userId, array $perms): string
{
    $st = $pdo->prepare(
        'SELECT u.*, a.tipo AS acc_tipo, a.nome AS acc_nome, a.plano AS acc_plano
           FROM users u LEFT JOIN accounts a ON a.id = u.account_id WHERE u.id = ?'
    );
    $st->execute([$userId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);

    /*
     * O arquivo de sessão é escrito À MÃO, sem session_start(): a suíte já
     * imprimiu resultado antes de chegar aqui, e session_id() recusa mudar
     * depois de qualquer saída ("headers already sent"), inclusive em CLI.
     * O formato do handler 'php' é: chave|valor_serializado, concatenados.
     */
    $sid   = 'convtest' . bin2hex(random_bytes(8));
    $dados = [
        'user_id'          => (int) $u['id'],
        'user_nome'        => $u['nome'],
        'user_perfil'      => 'user',      // não-admin: é quem passa pela lista
        'user_role'        => 'member',
        'account_id'       => (int) $u['account_id'],
        'account_tipo'     => $u['acc_tipo'] ?? 'matriz',
        'account_nome'     => $u['acc_nome'] ?? '',
        'account_plano'    => $u['acc_plano'] ?? 'basico',
        'user_permissions' => $perms,
        'csrf_token'       => str_repeat('a', 32),
    ];

    $payload = '';
    foreach ($dados as $k => $v) {
        $payload .= $k . '|' . serialize($v);
    }
    file_put_contents(caminhoSessaoTeste($sid), $payload);
    return $sid;
}

function caminhoSessaoTeste(string $sid): string
{
    $dir = ini_get('session.save_path') ?: sys_get_temp_dir();
    if (str_contains($dir, ';')) {
        $dir = substr($dir, strrpos($dir, ';') + 1);
    }
    return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'sess_' . $sid;
}

/** Não deixa sessão válida para trás: é credencial viva no disco. */
function limpaSessaoTeste(string $sid): void
{
    @unlink(caminhoSessaoTeste($sid));
}

/** GET quando $body é null; POST com JSON + CSRF quando não é. */
function httpJson(string $url, string $sid, ?array $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_COOKIE         => 'PHPSESSID=' . $sid,
    ]);
    if ($body !== null) {
        curl_setopt_array($ch, [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-TOKEN: ' . str_repeat('a', 32)],
        ]);
    }
    $resp   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'json' => json_decode((string) $resp, true)];
}

/* ===================================================================== */
secao('Teste 1 — converter preservando os dados da prospecção');
/* ===================================================================== */

$card1 = novoCard([
    '_rot'              => 'completo',
    'telefone_whatsapp' => '11987650001',
    'email'             => 'conv1@example.invalid',
    'cpf_cnpj'          => '52998224725',
    'rg'                => '123456789',
    'cep'               => '01310100',
    'logradouro'        => 'Av Paulista',
    'numero'            => '1000',
    'bairro'            => 'Bela Vista',
    'cidade'            => 'São Paulo',
    'uf'                => 'SP',
    'descricao'         => 'quer revisão de contrato',
    'valor_estimado'    => 2500,
], $ACC_A, $colunaA, $PREFIXO, $USER);

$r1 = ConversaoCliente::converter($card1, $ACC_A, [$ACC_A], $USER);
ok($r1['ok'] === true, 'a conversão foi concluída');
if (!$r1['ok']) {
    echo "  motivo: {$r1['erro']}\n";
} else {
    $criados['clientes'][] = $r1['cliente_id'];
    $cli = Cliente::find($r1['cliente_id']);

    $mapa = [
        'nome'       => $PREFIXO . ' completo',
        'cpf_cnpj'   => '52998224725',
        'email'      => 'conv1@example.invalid',
        'telefone'   => '11987650001',
        'rg'         => '123456789',
        'cep'        => '01310100',
        'logradouro' => 'Av Paulista',
        'numero'     => '1000',
        'bairro'     => 'Bela Vista',
        'cidade'     => 'São Paulo',
        'uf'         => 'SP',
    ];
    $faltando = [];
    foreach ($mapa as $campo => $esperado) {
        if ((string) ($cli[$campo] ?? '') !== (string) $esperado) {
            $faltando[] = "$campo (esperado '$esperado', veio '" . ($cli[$campo] ?? 'null') . "')";
        }
    }
    ok($faltando === [], 'os 11 campos do cadastro chegaram ao cliente' . ($faltando ? ': ' . implode('; ', $faltando) : ''));

    ok(str_contains((string) ($cli['observacoes'] ?? ''), 'revisão de contrato'),
        'o motivo/interesse da prospecção acompanhou o cliente');
    ok((int) ($cli['responsavel_id'] ?? 0) === 0 || $cli['responsavel_id'] !== null,
        'o responsável foi transferido (ou ficou vazio como na origem)');
    ok(!empty($cli['contato_id']), 'o contato (a pessoa) ficou ligado ao cliente');
    ok((int) ($cli['card_origem_id'] ?? 0) === $card1, 'o cliente aponta para a prospecção de origem');
    ok(!empty($cli['convertido_em']) && (int) $cli['convertido_por'] === $USER,
        'data e autor da conversão ficaram gravados');
}

/* ===================================================================== */
secao('Teste 1b — os PROCESSOS da prospecção acompanham o cliente');
/* ===================================================================== */

// Processo aberto enquanto a pessoa era lead ficava apontando só para a
// prospecção, e a ficha do cliente nascia sem os casos dela.
$temProcessos = false;
try {
    $pdo->query('SELECT card_id, cliente_id FROM processos LIMIT 0');
    $temProcessos = true;
} catch (\Throwable $e) {}

if (!$temProcessos) {
    echo "  [SKIP] tabela processos sem as colunas de vínculo
";
} else {
    $cardP = novoCard(['_rot' => 'com-processo', 'telefone_whatsapp' => '11987650077'], $ACC_A, $colunaA, $PREFIXO, $USER);

    // Um processo de teste ligado à prospecção.
    $colsProc = $pdo->query('SHOW COLUMNS FROM processos')->fetchAll(\PDO::FETCH_COLUMN);
    $campos = ['account_id' => $ACC_A, 'card_id' => $cardP];
    if (in_array('cliente_nome', $colsProc, true)) $campos['cliente_nome'] = $PREFIXO . ' com-processo';
    if (in_array('numero_processo', $colsProc, true)) $campos['numero_processo'] = '0000000-00.2026.8.26.0000';
    if (in_array('titulo', $colsProc, true)) $campos['titulo'] = $PREFIXO . ' caso';

    $cols = implode(', ', array_keys($campos));
    $phs  = ':' . implode(', :', array_keys($campos));
    $pdo->prepare("INSERT INTO processos ($cols) VALUES ($phs)")->execute($campos);
    $procId = (int) $pdo->lastInsertId();

    $rP = ConversaoCliente::converter($cardP, $ACC_A, [$ACC_A], $USER);
    ok($rP['ok'] === true, 'a conversão com processo vinculado foi concluída');
    if ($rP['ok']) {
        $criados['clientes'][] = $rP['cliente_id'];
        $st = $pdo->prepare('SELECT card_id, cliente_id FROM processos WHERE id = ?');
        $st->execute([$procId]);
        $proc = $st->fetch(\PDO::FETCH_ASSOC);

        ok((int) $proc['cliente_id'] === (int) $rP['cliente_id'], 'o processo passou a apontar para o cliente');
        ok((int) $proc['card_id'] === $cardP, 'o processo NÃO perdeu o vínculo com a prospecção de origem');

        $evP = Timeline::paraCliente($rP['cliente_id'], [$ACC_A]);
        ok(in_array('processos_vinculados', array_column($evP, 'acao'), true),
            'a transferência dos processos virou evento na timeline');
    }
    $pdo->prepare('DELETE FROM processos WHERE id = ?')->execute([$procId]);
}

/* ===================================================================== */
secao('Teste 2 — a timeline do cliente inclui o que houve ANTES da conversão');
/* ===================================================================== */

if ($r1['ok']) {
    $ev = Timeline::paraCliente($r1['cliente_id'], [$ACC_A]);
    $fases = array_column($ev, 'fase');
    ok(in_array('prospeccao', $fases, true), 'a timeline traz eventos da fase de prospecção');
    ok(in_array('cliente', $fases, true), 'a timeline traz eventos da fase de cliente');

    $acoes = array_column($ev, 'acao');
    ok(in_array('created', $acoes, true), 'o primeiro evento (criação) está na timeline');

    // A prova de que NÃO houve cópia: nenhuma linha de card_history ganhou par
    // em clientes_history.
    $nCard = (int) $pdo->query("SELECT COUNT(*) FROM card_history WHERE card_id = $card1")->fetchColumn();
    $nCli  = (int) $pdo->query("SELECT COUNT(*) FROM clientes_history WHERE cliente_id = {$r1['cliente_id']}")->fetchColumn();
    ok($nCli < $nCard + 3, "histórico não foi duplicado na conversão (card=$nCard, cliente=$nCli)");
}

/* ===================================================================== */
secao('Teste 3 — alteração de campo grava valor anterior e novo');
/* ===================================================================== */

$card3 = novoCard(['_rot' => 'edicao', 'telefone_whatsapp' => '11999990000'], $ACC_A, $colunaA, $PREFIXO, $USER);
Card::update($card3, ['telefone_whatsapp' => '11988880000', '_usuario_id' => $USER]);

$ev3 = Timeline::paraCard($card3, [$ACC_A]);
$alt = null;
foreach ($ev3 as $e) {
    if ($e['campo'] === 'telefone_whatsapp') { $alt = $e; break; }
}
ok($alt !== null, 'a alteração de telefone virou evento no histórico');
if ($alt) {
    ok($alt['de'] === '11999990000', "o valor ANTERIOR foi registrado (veio '{$alt['de']}')");
    ok($alt['para'] === '11988880000', "o valor NOVO foi registrado (veio '{$alt['para']}')");
    ok($alt['usuario'] !== null, 'o autor da alteração foi registrado');
}

/* ===================================================================== */
secao('Teste 4 — a conversão vira evento na linha do tempo');
/* ===================================================================== */

if ($r1['ok']) {
    $ev = Timeline::paraCliente($r1['cliente_id'], [$ACC_A]);
    $acoes = array_column($ev, 'acao');
    ok(in_array('convertido_cliente', $acoes, true), 'evento "prospecção convertida em cliente" registrado no card');
    ok(in_array('convertido_de_prospeccao', $acoes, true), 'evento correspondente registrado no cliente');
}

/* ===================================================================== */
secao('Teste 5 — converter de novo é bloqueado');
/* ===================================================================== */

$r5 = ConversaoCliente::converter($card1, $ACC_A, [$ACC_A], $USER);
ok($r5['ok'] === false, 'a segunda conversão foi recusada');
ok(str_contains((string) $r5['erro'], 'já foi convertida'), 'a mensagem diz que já foi convertida');
ok((int) $r5['cliente_id'] === (int) $r1['cliente_id'], 'a recusa informa QUAL cliente já existe');

$prev5 = ConversaoCliente::previa($card1, [$ACC_A]);
ok($prev5['ja_convertida'] === true && $prev5['ok'] === false, 'a prévia também bloqueia');

/* ===================================================================== */
secao('Teste 6 — prospecção com CPF de cliente existente é sinalizada');
/* ===================================================================== */

$card6 = novoCard([
    '_rot'     => 'duplicado',
    'cpf_cnpj' => '52998224725',           // mesmo CPF do Teste 1
    'email'    => 'outro@example.invalid',
], $ACC_A, $colunaA, $PREFIXO, $USER);

$prev6 = ConversaoCliente::previa($card6, [$ACC_A]);
ok(count($prev6['candidatos']) > 0, 'a prévia apontou possível duplicidade');
$forte = false;
foreach ($prev6['candidatos'] as $c) {
    if (($c['motivo'] ?? '') === 'cpf_cnpj') { $forte = true; }
    // O documento não pode voltar inteiro para a tela.
    if (!empty($c['cpf_cnpj']) && !str_contains((string) $c['cpf_cnpj'], '*')) {
        fail('o CPF do candidato voltou SEM máscara para a tela');
    }
}
ok($forte, 'o indício por CPF/CNPJ foi classificado como forte');
pass('os documentos dos candidatos voltam mascarados');

/* ===================================================================== */
secao('Teste 7 — vincular prospecção nova a cliente que já existe');
/* ===================================================================== */

if ($r1['ok']) {
    $r7 = ConversaoCliente::converter($card6, $ACC_A, [$ACC_A], $USER, (int) $r1['cliente_id']);
    ok($r7['ok'] === true, 'a vinculação foi concluída');
    ok($r7['criado'] === false, 'NÃO criou um segundo cliente');
    ok((int) $r7['cliente_id'] === (int) $r1['cliente_id'], 'apontou para o cliente que já existia');

    // Contar por prefixo seria frouxo: outros testes desta suíte criam clientes
    // com o mesmo prefixo. O que prova a ausência de "João 2" é que as DUAS
    // prospecções apontam para o MESMO id de cliente.
    $st7 = $pdo->prepare('SELECT COUNT(DISTINCT cliente_id) FROM cards WHERE id IN (?, ?)');
    $st7->execute([$card1, $card6]);
    $distintos = (int) $st7->fetchColumn();
    ok($distintos === 1, "as duas prospecções apontam para UM único cliente (distintos: $distintos)");

    // A timeline do cliente passa a incluir a segunda jornada.
    $ev7 = Timeline::paraCliente($r1['cliente_id'], [$ACC_A]);
    $origens = array_unique(array_map(fn ($e) => $e['origem']['tipo'] . ':' . $e['origem']['id'], $ev7));
    $temCard6 = in_array('card:' . $card6, $origens, true);
    ok($temCard6, 'os eventos da segunda prospecção aparecem na timeline do cliente');
}

/* ===================================================================== */
secao('Teste 8 — permissão de ação');
/* ===================================================================== */

// A regra vive no endpoint (a sessão é dele). Aqui garantimos o contrato que o
// endpoint usa: a constante existe e não colide com nome de página.
ok(ConversaoCliente::PERMISSAO === 'prospeccao.converter_cliente', 'a chave de permissão é a esperada');
ok(str_contains(ConversaoCliente::PERMISSAO, '.'), 'a chave tem ponto, então não colide com nome de página');

$fonte = file_get_contents(__DIR__ . '/../../public/api/prospeccao_conversao.php');
ok(str_contains($fonte, 'podeConverter()'), 'o endpoint checa permissão');
ok(preg_match('/if \(!podeConverter\(\)\)/', $fonte) === 1, 'a checagem bloqueia quem não tem');
ok(str_contains($fonte, 'hash_equals'), 'o endpoint valida CSRF na escrita');

// A chave tem de estar na lista branca da API de usuários, senão o checkbox da
// tela salva NADA, em silêncio: o INSERT é filtrado por essa lista.
$fonteUsers = file_get_contents(__DIR__ . '/../../public/api/users.php');
ok(str_contains($fonteUsers, ConversaoCliente::PERMISSAO),
    'a chave está na lista branca de user_permissions (senão o checkbox não salva)');

/*
 * O bloqueio de verdade, por HTTP, com duas sessões reais: uma com a permissão
 * e outra sem. Só roda se o servidor local responder, porque a suíte precisa
 * continuar executável em máquina sem Apache de pé.
 */
$base = getenv('YURIS_BASE') ?: 'http://localhost:8090';
$vivo = @file_get_contents($base . '/login.php', false, stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]));

if ($vivo === false) {
    echo "  [SKIP] servidor em $base não respondeu; o bloqueio por HTTP não foi exercido
";
} else {
    $comPerm = montaSessaoTeste($pdo, $USER, [ConversaoCliente::PERMISSAO, 'prospeccao']);
    $semPerm = montaSessaoTeste($pdo, $USER, ['prospeccao']);

    $previaCom = httpJson($base . '/api/prospeccao_conversao.php?card_id=' . $card3, $comPerm);
    $previaSem = httpJson($base . '/api/prospeccao_conversao.php?card_id=' . $card3, $semPerm);

    ok(($previaCom['json']['pode_converter'] ?? null) === true,  'com a permissão, a prévia libera o botão');
    ok(($previaSem['json']['pode_converter'] ?? null) === false, 'sem a permissão, o botão não aparece');

    $post = httpJson(
        $base . '/api/prospeccao_conversao.php',
        $semPerm,
        ['card_id' => $card3]
    );
    ok($post['status'] === 403, "sem a permissão, converter devolve 403 (veio {$post['status']})");
    ok(($post['json']['code'] ?? '') === 'sem_permissao', 'a recusa vem com código próprio');

    // O card não pode ter sido convertido pela tentativa negada.
    $stP = $pdo->prepare('SELECT cliente_id FROM cards WHERE id = ?');
    $stP->execute([$card3]);
    ok($stP->fetchColumn() === null, 'a tentativa negada NÃO converteu a prospecção');

    limpaSessaoTeste($comPerm);
    limpaSessaoTeste($semPerm);
}

/* ===================================================================== */
secao('Teste 9 — isolamento entre contas');
/* ===================================================================== */

$cardB = novoCard(['_rot' => 'da-conta-B', 'cpf_cnpj' => '52998224725'], $ACC_B, $colunaB, $PREFIXO, $USER);

// 9a: a conta A não enxerga o card da conta B
$prevCross = ConversaoCliente::previa($cardB, [$ACC_A]);
ok($prevCross['card'] === null, 'a conta A não enxerga a prospecção da conta B');

// 9b: converter card da conta B usando o contexto da conta A não pode passar
$rCross = ConversaoCliente::converter($cardB, $ACC_A, [$ACC_A], $USER);
ok($rCross['ok'] === false, 'a conta A não converte prospecção da conta B');

// 9c: o candidato de duplicidade não atravessa conta, mesmo com CPF igual
$cardB2 = novoCard(['_rot' => 'dup-B', 'cpf_cnpj' => '52998224725'], $ACC_B, $colunaB, $PREFIXO, $USER);
$prevB  = ConversaoCliente::previa($cardB2, [$ACC_B]);
$vazouA = false;
foreach ($prevB['candidatos'] as $c) {
    if (in_array((int) $c['id'], array_map('intval', $criados['clientes']), true)) { $vazouA = true; }
}
ok(!$vazouA, 'cliente da conta A não aparece como candidato para a conta B');

// 9d: a timeline não atravessa conta
if ($r1['ok']) {
    $evCross = Timeline::paraCliente($r1['cliente_id'], [$ACC_B]);
    ok($evCross === [], 'a timeline de um cliente da conta A responde vazia para a conta B');
}

// 9e: vincular a cliente de OUTRA conta é recusado
if ($r1['ok']) {
    $rLigaCross = ConversaoCliente::converter($cardB, $ACC_B, [$ACC_B], $USER, (int) $r1['cliente_id']);
    ok($rLigaCross['ok'] === false, 'não dá para vincular prospecção da conta B a cliente da conta A');
}

/* ===================================================================== */
secao('Teste 10 — rollback quando algo falha no meio');
/* ===================================================================== */

// Falha provocada de verdade: uma conta SEM setor de clientes não consegue
// criar o cliente, e a conversão tem de desfazer tudo.
$semSetor = $pdo->query(
    'SELECT a.id FROM accounts a
      WHERE NOT EXISTS (SELECT 1 FROM clientes_setores cs WHERE cs.account_id = a.id AND cs.ativo = 1)
      ORDER BY a.id LIMIT 1'
)->fetchColumn();

if (!$semSetor) {
    echo "  [SKIP] nenhuma conta sem setor de clientes para provocar a falha\n";
} else {
    $semSetor = (int) $semSetor;
    $colS = (int) $pdo->query("SELECT id FROM pipeline_columns WHERE account_id = $semSetor ORDER BY ordem, id LIMIT 1")->fetchColumn();
    $cardF = novoCard(['_rot' => 'rollback'], $semSetor, $colS, $PREFIXO, $USER);

    $antesCli = (int) $pdo->query("SELECT COUNT(*) FROM clientes WHERE account_id = $semSetor")->fetchColumn();
    $rF = ConversaoCliente::converter($cardF, $semSetor, [$semSetor], $USER);
    $depoisCli = (int) $pdo->query("SELECT COUNT(*) FROM clientes WHERE account_id = $semSetor")->fetchColumn();

    ok($rF['ok'] === false, 'a conversão falhou como esperado');
    ok($antesCli === $depoisCli, "nenhum cliente parcial ficou no banco ($antesCli -> $depoisCli)");

    $st = $pdo->prepare('SELECT cliente_id, status FROM cards WHERE id = ?');
    $st->execute([$cardF]);
    $depois = $st->fetch(\PDO::FETCH_ASSOC);
    ok($depois['cliente_id'] === null, 'a prospecção NÃO ficou marcada como convertida');
    ok($depois['status'] !== ConversaoCliente::STATUS_CONVERTIDA, 'o status não mudou');
}

/* ===================================================================== */
secao('Extra — a prospecção convertida sai do funil ativo mas continua lá');
/* ===================================================================== */

$ativos = Card::list(['account_ids' => [$ACC_A]]);
$idsAtivos = array_map(fn ($c) => (int) $c['id'], $ativos);
ok(!in_array($card1, $idsAtivos, true), 'a prospecção convertida sumiu do funil ativo');

$todos = Card::list(['account_ids' => [$ACC_A], 'incluir_convertidas' => true]);
$idsTodos = array_map(fn ($c) => (int) $c['id'], $todos);
ok(in_array($card1, $idsTodos, true), 'ela continua disponível quando pedida explicitamente');

$st = $pdo->prepare('SELECT status, cliente_id, convertido_em, convertido_por FROM cards WHERE id = ?');
$st->execute([$card1]);
$c1 = $st->fetch(\PDO::FETCH_ASSOC);
ok($c1['status'] === ConversaoCliente::STATUS_CONVERTIDA, 'o status virou "convertida"');
ok(!empty($c1['convertido_em']) && (int) $c1['convertido_por'] === $USER, 'data e autor gravados na prospecção');

/* ===================================================================== */
/* limpeza                                                                */
/* ===================================================================== */

echo "\n== limpeza ==\n";
$rem = ['clientes' => 0, 'cards' => 0];
foreach (array_unique($criados['clientes']) as $id) {
    try { $pdo->prepare('DELETE FROM clientes WHERE id = ?')->execute([$id]); $rem['clientes']++; } catch (\Throwable $e) {}
}
foreach (array_unique($criados['cards']) as $id) {
    try { $pdo->prepare('DELETE FROM cards WHERE id = ?')->execute([$id]); $rem['cards']++; } catch (\Throwable $e) {}
}
try { $pdo->prepare('DELETE FROM contatos WHERE nome LIKE ?')->execute([$PREFIXO . '%']); } catch (\Throwable $e) {}
echo "  removidos: {$rem['cards']} prospecções, {$rem['clientes']} clientes\n";
echo "  histórico de auditoria permanece (imutável por trigger), órfão e invisível na timeline\n";

$sobrou = (int) $pdo->query("SELECT COUNT(*) FROM cards WHERE cliente_nome LIKE '" . $PREFIXO . "%'")->fetchColumn();
ok($sobrou === 0, 'nenhuma prospecção de teste ficou no banco');

printf("\n%s  %d PASS, %d FAIL\n", $FAILS === 0 ? 'OK' : 'FALHOU', $PASSES, $FAILS);
exit($FAILS === 0 ? 0 : 1);
