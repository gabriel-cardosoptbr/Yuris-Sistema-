<?php
/**
 * receita_mensalidades_test.php: a receita do painel comercial conta a mensalidade
 * em todo mês em que ela está ativa (App\Clientes\CobrancaDoCliente::sqlReceitaDosValores,
 * 23/10/2026, "não tá somando todas as mensalidades").
 *
 *   - mensalidade antiga entra em todo mês até hoje, no dia de vencimento;
 *   - só a primeira vez é venda (venda preenchida); as outras vêm com venda nula;
 *   - dia 31 em mês curto cai no último dia; a primeira nunca antes do início;
 *   - anual no aniversário; serviço uma vez; "por demanda" não entra;
 *   - para no fim do valor e, inativo sem fim, no dia em que foi encerrado;
 *   - não projeta depois do mês corrente;
 *   - a conta do painel: vendas novas, receita do período e ticket só das vendas novas.
 *
 * Cria um cliente provisório na conta CRM local 304 e apaga tudo no fim.
 * Uso: php scripts/tests/receita_mensalidades_test.php
 */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Clientes\CobrancaDoCliente as C;
use App\Core\Database;

$OK = 0; $FALHAS = [];
function ok(string $m, bool $c): void { global $OK, $FALHAS; if ($c) { $OK++; echo "  [ok]   $m\n"; } else { $FALHAS[] = $m; echo "  [FALHA] $m\n"; } }

$pdo = Database::getConnection();
$cli = 0;
try {
    $setor = $pdo->query("SELECT setor_id FROM clientes WHERE account_id = 304 AND deleted_at IS NULL LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO clientes (account_id, setor_id, nome) VALUES (304, ?, 'ZZTESTE receita mensalidades')")->execute([$setor ?: null]);
    $cli = (int) $pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO crm_cliente_valores (account_id, cliente_id, tipo, descricao, valor, dia_vencimento, inicio, fim, ativo, updated_at) VALUES (304, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $agora = date('Y-m-d H:i:s');
    $mesAtual = date('Y-m');
    $tresAtras = date('Y-m', strtotime(date('Y-m-01') . ' -3 months'));
    // Mensalidade de 1.000 que começou há 3 meses, dia 10.
    $ins->execute([$cli, 'mensal', 'ZZ mensal antiga', 1000, 10, "$tresAtras-15", null, 1, $agora]);
    // Mensalidade de 200, dia 31, começou em janeiro de 2026 e terminou em 31/03/2026.
    $ins->execute([$cli, 'mensal', 'ZZ mensal dia 31', 200, 31, '2026-01-31', '2026-03-31', 1, $agora]);
    // Anual de 1.200 desde 05/03/2024.
    $ins->execute([$cli, 'anual', 'ZZ anual', 1200, null, '2024-03-05', null, 1, $agora]);
    // Serviço de 500 em 20/02/2026.
    $ins->execute([$cli, 'servico', 'ZZ servico', 500, null, '2026-02-20', null, 1, $agora]);
    // Por demanda: não entra.
    $ins->execute([$cli, 'demanda', 'ZZ demanda', 999, null, '2026-01-01', null, 1, $agora]);
    // Encerrada sem fim: mensal de 300 desde 01/01/2026, inativa desde 15/02/2026.
    $ins->execute([$cli, 'mensal', 'ZZ encerrada', 300, 1, '2026-01-01', null, 0, '2026-02-15 09:00:00']);

    // Só as linhas deste cliente (a venda nula não traz o cliente, então filtra dentro).
    $sql = str_replace("WHERE v.tipo <> 'demanda'", "WHERE v.cliente_id = $cli AND v.tipo <> 'demanda'", C::sqlReceitaDosValores());
    $linhas = $pdo->query("SELECT x.dt, x.valor, x.venda FROM ($sql) x ORDER BY x.dt, x.valor")->fetchAll(\PDO::FETCH_ASSOC);
    $de = fn(float $valor) => array_values(array_filter($linhas, fn($l) => (float) $l['valor'] === $valor));

    echo "\n== Mensalidade antiga ==\n";
    $m = $de(1000);
    ok('entra em todo mês do início até o mês corrente (4 vezes)', count($m) === 4);
    ok('a primeira é no início (dia 15, depois do dia 10)', ($m[0]['dt'] ?? '') === "$tresAtras-15");
    ok('as seguintes no dia 10', ($m[1]['dt'] ?? '') === date('Y-m-10', strtotime("$tresAtras-01 +1 month")) && ($m[3]['dt'] ?? '') === "$mesAtual-10");
    ok('só a primeira é venda', $m[0]['venda'] === "c$cli-$tresAtras-15" && $m[1]['venda'] === null && $m[3]['venda'] === null);

    echo "\n== Dia 31 e fim ==\n";
    $d = array_column($de(200), 'dt');
    ok('jan, fev (último dia) e mar; para no fim', $d === ['2026-01-31', '2026-02-28', '2026-03-31']);

    echo "\n== Anual, serviço, demanda, encerrada ==\n";
    $a = array_column($de(1200), 'dt');
    $esperado = ['2024-03-05', '2025-03-05', '2026-03-05'];
    ok('anual no aniversário, até o ano corrente', $a === $esperado);
    ok('serviço uma vez, na data, e é venda', count($de(500)) === 1 && $de(500)[0]['dt'] === '2026-02-20' && $de(500)[0]['venda'] !== null);
    ok('"por demanda" não entra', count($de(999)) === 0);
    ok('inativa sem fim para no dia em que foi encerrada (jan e fev)', array_column($de(300), 'dt') === ['2026-01-01', '2026-02-01']);
    ok('nada depois do fim do mês corrente', max(array_column($linhas, 'dt')) <= date('Y-m-t'));

    echo "\n== A conta do painel ==\n";
    // Fevereiro de 2026: mensal dia 31 (200), anual não, serviço (500, venda nova), encerrada (300).
    $r = $pdo->query("SELECT COUNT(DISTINCT x.venda) AS n, SUM(x.valor) AS v, SUM(CASE WHEN x.venda IS NOT NULL THEN x.valor END) AS vn
                        FROM ($sql) x WHERE x.dt BETWEEN '2026-02-01' AND '2026-02-28'")->fetch(\PDO::FETCH_ASSOC);
    ok('fevereiro: receita 1.000 (200 + 500 + 300)', (float) $r['v'] === 1000.0);
    ok('fevereiro: uma venda nova (o serviço), de 500', (int) $r['n'] === 1 && (float) $r['vn'] === 500.0);
    $r = $pdo->query("SELECT COUNT(DISTINCT x.venda) AS n, SUM(x.valor) AS v FROM ($sql) x WHERE x.dt BETWEEN '$mesAtual-01' AND '" . date('Y-m-t') . "'")->fetch(\PDO::FETCH_ASSOC);
    ok('mês corrente: a mensalidade antiga conta, sem venda nova', (float) $r['v'] === 1000.0 && (int) $r['n'] === 0);
} catch (\Throwable $e) {
    $FALHAS[] = $e->getMessage(); echo '  [FALHA] ' . $e->getMessage() . "\n";
} finally {
    if ($cli) {
        $pdo->exec("DELETE FROM crm_cliente_cobrancas WHERE cliente_id = $cli AND account_id = 304");
        $pdo->exec("DELETE FROM crm_cliente_valores WHERE cliente_id = $cli AND account_id = 304");
        $pdo->exec("DELETE FROM clientes WHERE id = $cli AND account_id = 304 AND nome = 'ZZTESTE receita mensalidades'");
    }
}
echo "\n" . ($FALHAS ? count($FALHAS) . ' FALHA(S): ' . implode(' | ', $FALHAS) : "$OK ok/0") . "\n";
exit($FALHAS ? 1 : 0);
