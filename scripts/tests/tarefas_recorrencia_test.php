<?php
/**
 * tarefas_recorrencia_test.php — a renovação de tarefas recorrentes.
 *
 * O QUE ESTAVA ERRADO, medido em produção em 12/09/2026:
 *
 * A tela de Tarefas rodava um cron dentro da requisição, com uma trava em
 * arquivo que prometia "no máximo uma vez por hora". A pasta `storage/` era do
 * root, o Apache não conseguia escrever, a gravação falhava em SILÊNCIO e a
 * trava congelou por 102 dias. Sem trava, o cron rodava em TODA abertura:
 * 214 tarefas, 24 ms cada, cerca de 5 segundos de espera por vez.
 *
 * E não convergia: a renovação avançava UM período a partir de um prazo vencido
 * há meses, então a tarefa seguia vencida e voltava na próxima abertura. Para
 * sempre. Daí as 14.508 linhas de histórico imutável para 344 tarefas.
 *
 * O que está sob teste, então, é o que impede isso de voltar:
 *
 *  - a data avança até o FUTURO, numa passada, para cada tipo de recorrência
 *  - virada de mês e de ano, dia 31 em mês de 30, e ano bissexto
 *  - recorrência com intervalo inválido NÃO trava o servidor
 *  - `data_fim` é respeitada, e a recorrência termina em vez de andar sozinha
 *  - a renovação automática NÃO gera aviso no sino
 *  - listar tarefas não roda mais nenhum cron
 *
 * ESCREVE NO BANCO. Não rode em produção.
 *
 * Uso local: C:\xampp\php\php.exe scripts/tests/tarefas_recorrencia_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Notificacoes\Movimento;
use App\Tarefas\TaskRecurrence;

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

/**
 * Monta uma recorrência SEM tocar no banco.
 *
 * `TaskRecurrence` não tem construtor: `loadById()` cria o objeto vazio e
 * atribui `->data` de fora. Então é assim que se monta uma para teste, e é
 * assim que dá para exercitar o cálculo de data puro, que é a parte perigosa,
 * sem criar linha nenhuma.
 *
 * (Primeira versão deste teste passava o array para `new TaskRecurrence(...)`.
 * O array era ignorado em silêncio, `$data` ficava vazio, e TODOS os cálculos
 * caíam no mesmo ramo. O teste "falhava" apontando para o código certo.)
 */
function recorrencia(array $cfg): TaskRecurrence
{
    $r = new TaskRecurrence();

    /*
     * Reflexão, e não uma linha no banco.
     *
     * `$data` é privado e só `loadById()` o preenche, lendo de
     * `task_recurrences`. O que está sob teste aqui é o CÁLCULO DE DATA puro, e
     * criar dezenas de linhas só para exercitar aritmética de calendário
     * deixaria o teste lento e cheio de limpeza que pode falhar pela metade.
     *
     * A parte que toca o banco de verdade é testada mais abaixo, no bloco do
     * aviso e nas asserções sobre o código dos endpoints.
     */
    $prop = new ReflectionProperty(TaskRecurrence::class, 'data');
    $prop->setAccessible(true);
    $prop->setValue($r, array_merge([
        'id' => 999999, 'tipo' => 'diaria', 'intervalo' => 1,
        'dias_semana' => null, 'dia_mes' => null, 'data_fim' => null, 'unidade' => 'day',
    ], $cfg));

    return $r;
}

/** Só a data, para comparar sem a hora atrapalhar. */
function dia(string $dataHora): string { return substr($dataHora, 0, 10); }

/* ===================================================================== */
secao('calcularProximaData continua avançando UM período');

/*
 * Não mexi nessa função de propósito: quem acabou de concluir a tarefa de hoje
 * quer a da semana que vem, e não a de daqui a seis meses. O comportamento
 * antigo precisa continuar igual.
 */
$r = recorrencia(['tipo' => 'diaria', 'intervalo' => 1]);
eq('2020-01-02', dia($r->calcularProximaData('2020-01-01 10:00:00')),
   'diária avança um dia, mesmo partindo de 2020');

$r = recorrencia(['tipo' => 'mensal', 'dia_mes' => 15]);
eq('2020-02-15', dia($r->calcularProximaData('2020-01-15 10:00:00')),
   'mensal avança um mês');

/* ===================================================================== */
secao('proximaDataFutura avança ATÉ passar de hoje, numa passada');

$hoje = date('Y-m-d');

foreach ([
    'diária'      => ['tipo' => 'diaria',  'intervalo' => 1],
    'semanal'     => ['tipo' => 'semanal', 'intervalo' => 1],
    'quinzenal'   => ['tipo' => 'quinzenal', 'intervalo' => 1],
    'mensal'      => ['tipo' => 'mensal',  'dia_mes' => 10],
    'anual'       => ['tipo' => 'anual',   'intervalo' => 1],
    'custom dias' => ['tipo' => 'custom',  'intervalo' => 3, 'unidade' => 'day'],
] as $nome => $cfg) {
    $r = recorrencia($cfg);
    $d = $r->proximaDataFutura('2020-01-10 09:00:00');
    ok($d !== '' && dia($d) > $hoje, "$nome: a partir de 2020 chega no futuro ($d)");
}

// É isto que mata o reprocessamento eterno: rodar de novo não acha nada vencido.
$r = recorrencia(['tipo' => 'diaria', 'intervalo' => 1]);
$primeira = $r->proximaDataFutura('2020-01-10 09:00:00');
$segunda  = $r->proximaDataFutura($primeira);
ok(dia($segunda) > dia($primeira), 'chamar de novo sobre a data futura continua avançando, sem laço infinito');

/* ===================================================================== */
secao('Virada de mês, de ano e ano bissexto');

// Dia 31 em mês que não tem 31: tem de cair no último dia, não pular o mês.
$r = recorrencia(['tipo' => 'mensal', 'dia_mes' => 31]);
eq('2026-02-28', dia($r->calcularProximaData('2026-01-31 09:00:00')),
   'dia 31 em fevereiro de ano comum vira 28');

eq('2024-02-29', dia($r->calcularProximaData('2024-01-31 09:00:00')),
   'e 29 em ano bissexto');

// Virada de ano.
$r = recorrencia(['tipo' => 'mensal', 'dia_mes' => 5]);
eq('2026-01-05', dia($r->calcularProximaData('2025-12-05 09:00:00')),
   'mensal vira o ano corretamente');

$r = recorrencia(['tipo' => 'diaria', 'intervalo' => 1]);
eq('2026-01-01', dia($r->calcularProximaData('2025-12-31 09:00:00')),
   'diária vira o ano corretamente');

// 29 de fevereiro mais um ano: não existe 29/02/2025.
$r = recorrencia(['tipo' => 'anual']);
$d = $r->calcularProximaData('2024-02-29 09:00:00');
ok($d !== '' && in_array(dia($d), ['2025-02-28', '2025-03-01'], true),
   'anual a partir de 29/02 cai num dia real de 2025 (' . dia($d) . ')');

// A hora não se perde no caminho: prazo é hora marcada.
$r = recorrencia(['tipo' => 'diaria', 'intervalo' => 1]);
ok(str_contains($r->calcularProximaData('2026-03-10 14:30:00'), '14:30'),
   'a hora do prazo é preservada ao avançar');

/* ===================================================================== */
secao('Recorrência mal configurada NÃO trava o servidor');

/*
 * Este é o teste que justifica o teto de voltas. Sem ele, `intervalo = 0` faria
 * a data não andar e o laço giraria para sempre DENTRO da requisição: o pior
 * final possível, muito pior que a tarefa continuar vencida.
 */
$t0 = microtime(true);
$r  = recorrencia(['tipo' => 'custom', 'intervalo' => 0, 'unidade' => 'day']);
$d  = $r->proximaDataFutura('2020-01-01 09:00:00');
$ms = (microtime(true) - $t0) * 1000;
ok($ms < 2000, 'intervalo zero responde rápido em vez de girar: ' . round($ms) . ' ms');

$t0 = microtime(true);
$r  = recorrencia(['tipo' => 'custom', 'intervalo' => 1, 'unidade' => 'unidade_que_nao_existe']);
$r->proximaDataFutura('2020-01-01 09:00:00');
ok((microtime(true) - $t0) * 1000 < 2000, 'unidade inválida também não trava');

// Recorrência diária desde 2000: são 9.500 dias. O teto de 400 voltas segura, e
// a tarefa fica com data antiga em vez de o servidor pendurar.
$t0 = microtime(true);
$r  = recorrencia(['tipo' => 'diaria', 'intervalo' => 1]);
$r->proximaDataFutura('2000-01-01 09:00:00');
ok((microtime(true) - $t0) * 1000 < 3000, 'data absurdamente antiga respeita o teto e responde');

/* ===================================================================== */
secao('data_fim encerra a recorrência em vez de andar para sempre');

$r = recorrencia(['tipo' => 'diaria', 'intervalo' => 1, 'data_fim' => '2020-01-05']);
eq('', $r->proximaDataFutura('2020-01-01 09:00:00'),
   'com data_fim no passado, devolve vazio (o chamador desativa a recorrência)');

$r = recorrencia(['tipo' => 'diaria', 'intervalo' => 1, 'data_fim' => date('Y-m-d', strtotime('+400 days'))]);
$d = $r->proximaDataFutura('2020-01-01 09:00:00');
ok($d !== '', 'com data_fim no futuro, continua renovando');

/* ===================================================================== */
secao('A renovação automática NÃO vira aviso no sino');

/*
 * Eram 214 avisos "Tarefa X foi alterada, pelo sistema" por rodada. É o ruído
 * que as duas naturezas de aviso existem para evitar, e que eu não previ ao
 * pendurar a notificação no gravador de histórico: um trabalho em LOTE passa
 * por ali igual a um clique de pessoa.
 */
ok(in_array('renovada_auto', Movimento::IGNORADAS, true),
   "'renovada_auto' está na lista de ações que não geram aviso");

$tarefa = (int) $pdo->query("SELECT id FROM tasks ORDER BY id DESC LIMIT 1")->fetchColumn();
if ($tarefa) {
    $pdo->exec("DELETE FROM account_notifications WHERE tipo = 'movimento.tarefa' AND entidade_id = $tarefa");
    Movimento::registrar('tarefa', $tarefa, 'renovada_auto', null);
    eq(0, (int) $pdo->query("SELECT COUNT(*) FROM account_notifications WHERE tipo = 'movimento.tarefa' AND entidade_id = $tarefa")->fetchColumn(),
       'renovar automaticamente não cria aviso nenhum');

    // E o que uma pessoa faz continua avisando: a regra não virou "tarefa não avisa".
    Movimento::registrar('tarefa', $tarefa, 'updated', null);
    ok((int) $pdo->query("SELECT COUNT(*) FROM account_notifications WHERE tipo = 'movimento.tarefa' AND entidade_id = $tarefa")->fetchColumn() > 0,
       'mas alteração feita por gente continua avisando');
    $pdo->exec("DELETE FROM account_notifications WHERE tipo = 'movimento.tarefa' AND entidade_id = $tarefa");
} else {
    echo "  [SKIP] não há tarefa no banco local para exercitar o aviso\n";
}

/* ===================================================================== */
secao('Listar tarefas não roda mais cron nenhum');

$fonteApi = file_get_contents(__DIR__ . '/../../public/api/tasks.php');
ok(!preg_match('/^\s*\\\\?App\\\\Tarefas\\\\RecurrenceCronService::tickIfDue\(\);/m', $fonteApi),
   'o GET de /api/tasks.php não chama mais o renovador');
ok(str_contains($fonteApi, 'AQUI RODAVA UM CRON'),
   '  e deixou registrado por que, para ninguém colocar de volta');

$fonteTick = file_get_contents(__DIR__ . '/../../public/api/tasks_recurrence_tick.php');
ok(str_contains($fonteTick, 'RecurrenceCronService::executar'),
   'e o cron de verdade do servidor passou a fazer a renovação');

$fonteServico = file_get_contents(__DIR__ . '/../../app/Tarefas/RecurrenceCronService.php');
ok(str_contains($fonteServico, 'if (!self::updateLock())'),
   'a trava só libera o trabalho se conseguiu MESMO gravar');
/*
 * A CHAMADA, e não a palavra em qualquer lugar.
 *
 * A primeira versão desta asserção era `str_contains($fonte, 'proximaDataFutura')`,
 * e a prova de não vacuidade mostrou que ela NÃO mordia: trocar a chamada de
 * volta para `calcularProximaData` deixava a palavra no comentário logo acima, e
 * o teste passava feliz. Asserção que casa com comentário não testa código.
 */
ok((bool) preg_match('/\$rec->proximaDataFutura\(/', $fonteServico),
   'e a renovação CHAMA a data que converge, não a que avança um período');
ok(!preg_match('/\$rec->calcularProximaData\(/', $fonteServico),
   '  e não chama mais a que avança um período só');

printf("\n%s  %d PASS, %d FAIL\n", $FAILS === 0 ? 'OK' : 'FALHOU', $PASSES, $FAILS);
exit($FAILS === 0 ? 0 : 1);
