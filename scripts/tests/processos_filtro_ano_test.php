<?php
/**
 * processos_filtro_ano_test.php — o filtro de ano do calendário não esconde processo.
 *
 * O RELATO (23/10/2026): "não tá vindo os processos". O indicador dizia "1
 * processo ativo" e o calendário de 2026 mostrava "0 processos".
 *
 * A causa, medida em produção: o processo tinha data de início em 2019 e NENHUM
 * prazo. `Processo::list` com from/to filtrava por
 * `proximo_prazo BETWEEN ... OR data_inicio BETWEEN ...`, e para esse processo
 * as duas condições são falsas (NULL BETWEEN é falso, e 2019 não está em 2026).
 * Ele não aparecia em ano nenhum.
 *
 * Regra que fica: processo SEM PRAZO vem sempre, em qualquer ano. Processo COM
 * prazo continua obedecendo ao ano.
 *
 * ESCREVE NO BANCO (linhas com marca, apagadas no fim). Não rode em produção.
 *
 * Uso local: C:\xampp\php\php.exe scripts/tests/processos_filtro_ano_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Processos\Processo;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

$FAILS = 0; $PASSES = 0;
function pass(string $m): void { global $PASSES; $PASSES++; echo "  [PASS] $m\n"; }
function fail(string $m): void { global $FAILS;  $FAILS++;  echo "  [FAIL] $m\n"; }
function secao(string $t): void { echo "\n== $t ==\n"; }
function ok(bool $c, string $m): void { $c ? pass($m) : fail($m); }

$ACC = (int) $pdo->query('SELECT id FROM accounts ORDER BY id LIMIT 1')->fetchColumn();
if ($ACC <= 0) { echo "SKIP: nenhuma conta.\n"; exit(0); }
$pdo->exec("DELETE FROM processos WHERE numero LIKE 'ZZTESTE_ANO_%'");

function cria(int $acc, string $sufixo, ?string $inicio, ?string $prazo): int {
    return (int) Processo::create([
        'account_id' => $acc, 'numero' => 'ZZTESTE_ANO_' . $sufixo,
        'cliente_nome' => 'Teste', 'status' => 'ativo',
        'data_inicio' => $inicio, 'proximo_prazo' => $prazo,
    ]);
}

$ID = [
    'caso_real'      => cria($ACC, 'caso_real',      '2019-08-28', null),         // o do relato
    'sem_nada'       => cria($ACC, 'sem_nada',       null,         null),
    'prazo_no_ano'   => cria($ACC, 'prazo_no_ano',   '2019-01-01', '2026-03-10'),
    'prazo_outro'    => cria($ACC, 'prazo_outro',    '2019-01-01', '2024-05-02'),
    'inicio_no_ano'  => cria($ACC, 'inicio_no_ano',  '2026-02-01', null),
];

// A criação preenche data_inicio com a data de HOJE quando vem vazia, então este
// caso só existe se alguém grava NULL direto (importação, por exemplo). A coluna
// aceita NULL, então forço aqui. Sem isto o teste não testava o que o nome diz.
$pdo->prepare('UPDATE processos SET data_inicio = NULL WHERE id = ?')->execute([$ID['sem_nada']]);

$lista = fn(array $f) => array_map(fn($p) => (int)$p['id'], Processo::list(['account_ids' => [$ACC], 'user_id' => 0] + $f));
$vendo2026 = $lista(['from' => '2026-01-01', 'to' => '2026-12-31']);
$vendo2024 = $lista(['from' => '2024-01-01', 'to' => '2024-12-31']);

secao('1. calendário de 2026');
ok(in_array($ID['caso_real'], $vendo2026, true),     'O CASO DO RELATO: aberto em 2019, sem prazo, aparece vendo 2026');
ok(in_array($ID['sem_nada'], $vendo2026, true),      'sem prazo e sem data de início também aparece');
ok(in_array($ID['prazo_no_ano'], $vendo2026, true),  'prazo em 2026 aparece');
ok(in_array($ID['inicio_no_ano'], $vendo2026, true), 'sem prazo, aberto em 2026, continua aparecendo');
ok(!in_array($ID['prazo_outro'], $vendo2026, true),  'COM prazo em 2024 e aberto em 2019, NÃO aparece em 2026 (prazo continua mandando)');

secao('2. calendário de 2024');
ok(in_array($ID['prazo_outro'], $vendo2024, true),   'o processo com prazo em 2024 aparece vendo 2024');
ok(in_array($ID['caso_real'], $vendo2024, true),     'o processo sem prazo aparece em qualquer ano');
ok(!in_array($ID['prazo_no_ano'], $vendo2024, true), 'prazo em 2026 não aparece vendo 2024');

secao('3. sem o filtro de ano');
$todos = $lista([]);
ok(count(array_intersect($ID, $todos)) === count($ID), 'sem from/to, a lista traz os cinco');

secao('4. a regra está no código');
$fonte = (string) file_get_contents(__DIR__ . '/../../app/Processos/Processo.php');
ok(str_contains($fonte, 'OR p.proximo_prazo IS NULL)'), 'Processo::list tem a cláusula de processo sem prazo');

secao('limpeza');
$pdo->exec("DELETE FROM processos WHERE numero LIKE 'ZZTESTE_ANO_%'");
$sobrou = (int) $pdo->query("SELECT COUNT(*) FROM processos WHERE numero LIKE 'ZZTESTE_ANO_%'")->fetchColumn();
ok($sobrou === 0, 'nenhum processo de teste sobrou');

echo "\n== RESULTADO ==\n  passou: $PASSES\n  falhou: $FAILS\n";
exit($FAILS > 0 ? 1 : 0);
