<?php
/**
 * aniversariantes_test.php — data de nascimento e o relatório mensal de parabéns.
 *
 * Cobre o pedido de campo de 10/09/2026: "data de nascimento no cadastro do
 * cliente com possibilidade de relatório mensal para dar parabéns. Uma forma de
 * contactar o cliente novamente."
 *
 * O que está sob teste não é só "grava a data". É o conjunto que faz o relatório
 * ser confiável: normalização do que a pessoa digita, recusa de data absurda,
 * sobrevivência à conversão de prospecção em cliente, e o isolamento entre
 * escritórios na hora de listar.
 *
 * ESCREVE NO BANCO. Não rode em produção.
 *
 * Uso local: C:\xampp\php\php.exe scripts/tests/aniversariantes_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Clientes\Aniversariantes;
use App\Clientes\Cliente;
use App\Core\Database;
use App\Prospeccao\Card;
use App\Prospeccao\ConversaoCliente;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

$FAILS = 0; $PASSES = 0;
function pass(string $m): void { global $PASSES; $PASSES++; echo "  [PASS] $m\n"; }
function fail(string $m): void { global $FAILS;  $FAILS++;  echo "  [FAIL] $m\n"; }
function secao(string $t): void { echo "\n== $t ==\n"; }
function ok(bool $c, string $m): void { $c ? pass($m) : fail($m); }

$PREFIXO = 'TESTE-ANIV';

$ACC = (int) $pdo->query(
    'SELECT a.id FROM accounts a
      WHERE EXISTS (SELECT 1 FROM clientes_setores cs WHERE cs.account_id = a.id AND cs.ativo = 1)
        AND EXISTS (SELECT 1 FROM pipeline_columns pc WHERE pc.account_id = a.id)
   ORDER BY a.id LIMIT 1'
)->fetchColumn();
if (!$ACC) { echo "SKIP: nenhuma conta com setor de cliente e funil.\n"; exit(0); }

$ACC_B = (int) $pdo->query("SELECT id FROM accounts WHERE id <> $ACC ORDER BY id LIMIT 1")->fetchColumn();
$SETOR = (int) $pdo->query("SELECT id FROM clientes_setores WHERE account_id = $ACC AND ativo = 1 LIMIT 1")->fetchColumn();
$COL   = (int) $pdo->query("SELECT id FROM pipeline_columns WHERE account_id = $ACC ORDER BY ordem, id LIMIT 1")->fetchColumn();
echo "Cenário: conta $ACC (setor $SETOR, funil $COL), outra conta $ACC_B\n";

// Limpeza defensiva: rodada morta deixaria clientes contando no relatório.
$pdo->prepare("DELETE FROM clientes WHERE nome LIKE '$PREFIXO%'")->execute();
$pdo->prepare("DELETE FROM cards    WHERE cliente_nome LIKE '$PREFIXO%'")->execute();

$criados = ['clientes' => [], 'cards' => []];

/* ===================================================================== */
secao('A data é normalizada, e data impossível é recusada');
/* ===================================================================== */

$cid = Cliente::create([
    'account_id' => $ACC, 'setor_id' => $SETOR,
    'nome' => $PREFIXO . ' Normalizacao', 'data_nascimento' => '14/03/1985',
], 1);
$criados['clientes'][] = $cid;

$le = function (int $id) use ($pdo) {
    $st = $pdo->prepare('SELECT data_nascimento FROM clientes WHERE id = ?');
    $st->execute([$id]);
    return $st->fetchColumn();
};

ok($le($cid) === '1985-03-14', 'aceita d/m/Y digitado à mão e grava Y-m-d');

Cliente::update($cid, ['data_nascimento' => '1990-12-25'], 1);
ok($le($cid) === '1990-12-25', 'aceita Y-m-d do input date');

// Os dois casos abaixo aparecem por erro de digitação (ano de dois dígitos
// virando 2068, por exemplo) e envenenariam o relatório.
Cliente::update($cid, ['data_nascimento' => '2100-01-01'], 1);
ok($le($cid) === null, 'data no FUTURO é recusada (vira NULL)');

Cliente::update($cid, ['data_nascimento' => '1850-01-01'], 1);
ok($le($cid) === null, 'data de mais de 130 anos atrás é recusada');

Cliente::update($cid, ['data_nascimento' => 'não é data'], 1);
ok($le($cid) === null, 'texto inválido vira NULL e NÃO derruba o cadastro inteiro');

Cliente::update($cid, ['data_nascimento' => '1990-12-25'], 1);
Cliente::update($cid, ['data_nascimento' => ''], 1);
ok($le($cid) === null, 'vazio limpa a data');

/* ===================================================================== */
secao('A data atravessa a conversão de prospecção em cliente');
/* ===================================================================== */

$cardId = Card::create([
    'account_id' => $ACC, 'cliente_nome' => $PREFIXO . ' Conversao',
    'coluna_id' => $COL, 'data_nascimento' => '1978-11-02', '_usuario_id' => 1,
]);
$criados['cards'][] = $cardId;

$st = $pdo->prepare('SELECT data_nascimento FROM cards WHERE id = ?');
$st->execute([$cardId]);
ok($st->fetchColumn() === '1978-11-02', 'a prospecção guarda a data');

$r = ConversaoCliente::converter($cardId, $ACC, [$ACC], 1);
ok($r['ok'] === true, 'conversão concluída: ' . ($r['erro'] ?? 'sem erro'));
if ($r['ok']) {
    $criados['clientes'][] = $r['cliente_id'];
    ok($le((int) $r['cliente_id']) === '1978-11-02',
       'a data ACOMPANHOU: perguntar de novo a quem já é cliente seria o erro');
}

/* ===================================================================== */
secao('O relatório do mês');
/* ===================================================================== */

$mesHoje  = (int) date('n');
$anoAtual = (int) date('Y');

$hoje = Cliente::create([
    'account_id' => $ACC, 'setor_id' => $SETOR, 'nome' => $PREFIXO . ' Hoje',
    'data_nascimento' => date('Y-m-d', strtotime('-40 years')), 'whatsapp' => '11987654321',
], 1);
$criados['clientes'][] = $hoje;

$fev = Cliente::create([
    'account_id' => $ACC, 'setor_id' => $SETOR, 'nome' => $PREFIXO . ' Bissexto',
    'data_nascimento' => '1992-02-29', 'telefone' => '5511912345678',
], 1);
$criados['clientes'][] = $fev;

$semData = Cliente::create([
    'account_id' => $ACC, 'setor_id' => $SETOR, 'nome' => $PREFIXO . ' SemData',
], 1);
$criados['clientes'][] = $semData;

$meus = array_values(array_filter(
    Aniversariantes::doMes([$ACC], $mesHoje),
    fn ($x) => str_starts_with((string) $x['nome'], $PREFIXO)
));
ok(count($meus) === 1, 'só quem faz aniversário no mês entra na lista do mês');
ok($meus[0]['hoje'] === true, 'quem faz hoje vem marcado');
ok($meus[0]['idade_que_faz'] === 40,
   'diz quantos anos a pessoa COMPLETA no ano, não a idade de hoje');
ok($meus[0]['whatsapp_digits'] === '5511987654321', 'monta o número do wa.me com o DDI');
ok(!array_key_exists('email', $meus[0]), 'não devolve e-mail: PII à toa numa lista');

// 29/02 aparece em fevereiro TODO ano, inclusive nos sem dia 29: a consulta
// compara mês e dia guardados, não constrói a data no ano corrente.
$emFev = array_values(array_filter(
    Aniversariantes::doMes([$ACC], 2),
    fn ($x) => str_starts_with((string) $x['nome'], $PREFIXO)
));
ok(count($emFev) === 1 && $emFev[0]['dia'] === 29, 'quem nasceu em 29/02 aparece em fevereiro');
ok($emFev[0]['whatsapp_digits'] === '5511912345678', 'número que já vem com 55 não ganha um segundo DDI');

$porMes = Aniversariantes::porMes([$ACC]);
ok(count($porMes) === 12, 'a contagem cobre os doze meses');
ok($porMes[2] >= 1, 'e enxerga fevereiro');

$cob = Aniversariantes::cobertura([$ACC]);
ok($cob['com'] >= 3 && $cob['sem'] >= 1,
   "a cobertura conta os dois lados ({$cob['com']} com data, {$cob['sem']} sem)");

/* ===================================================================== */
secao('Isolamento entre escritórios');
/* ===================================================================== */

ok(Aniversariantes::doMes([], $mesHoje) === [], 'sem conta acessível, lista vazia');

if ($ACC_B) {
    $daOutra = array_filter(
        Aniversariantes::doMes([$ACC_B], $mesHoje),
        fn ($x) => str_starts_with((string) $x['nome'], $PREFIXO)
    );
    ok($daOutra === [], 'a conta B NÃO vê os aniversariantes da conta A');

    $cobB = Aniversariantes::cobertura([$ACC_B]);
    ok($cobB['com'] + $cobB['sem'] !== $cob['com'] + $cob['sem'] || $ACC_B === $ACC,
       'a cobertura também é por conta, não global');
} else {
    echo "  [SKIP] só existe uma conta: isolamento não pôde ser testado\n";
}

/* ===================================================================== */
/* limpeza                                                                */
/* ===================================================================== */

echo "\n== limpeza ==\n";
foreach (array_unique($criados['clientes']) as $id) {
    try { $pdo->prepare('DELETE FROM clientes WHERE id = ?')->execute([$id]); } catch (\Throwable $e) {}
}
foreach (array_unique($criados['cards']) as $id) {
    try { $pdo->prepare('DELETE FROM cards WHERE id = ?')->execute([$id]); } catch (\Throwable $e) {}
}
$sobrou = (int) $pdo->query("SELECT COUNT(*) FROM clientes WHERE nome LIKE '$PREFIXO%'")->fetchColumn();
ok($sobrou === 0, 'nenhum cliente de teste ficou no banco');

printf("\n%s  %d PASS, %d FAIL\n", $FAILS === 0 ? 'OK' : 'FALHOU', $PASSES, $FAILS);
exit($FAILS === 0 ? 0 : 1);
