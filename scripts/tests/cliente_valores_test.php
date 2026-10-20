<?php
/**
 * cliente_valores_test.php: o Financeiro da ficha do cliente (App\Clientes\ValoresDoCliente, migration 152).
 *
 *   - resumo: "por mês" soma mensal + anual/12; linha encerrada ou vencida não conta;
 *   - número em formato brasileiro ("2.600,00") e validações (tipo, valor, dia, datas);
 *   - salvar, editar, encerrar, remover; outra conta não lê nem grava no cliente.
 *
 * Usa um cliente da conta CRM local 304; as linhas criadas são apagadas no fim.
 * Uso: php scripts/tests/cliente_valores_test.php
 */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Clientes\ValoresDoCliente as V;
use App\Core\Database;

$OK = 0; $FALHAS = [];
function ok(string $m, bool $c): void { global $OK, $FALHAS; if ($c) { $OK++; echo "  [ok]   $m\n"; } else { $FALHAS[] = $m; echo "  [FALHA] $m\n"; } }
function recusa(callable $f): ?string { try { $f(); return null; } catch (\InvalidArgumentException $e) { return $e->getMessage(); } }

echo "\n== 1. Conta (sem banco) ==\n";
$r = V::resumo([
    ['tipo' => 'mensal', 'valor' => 2600, 'ativo' => true, 'fim' => null],
    ['tipo' => 'anual', 'valor' => 1200, 'ativo' => true, 'fim' => null],
    ['tipo' => 'fixo', 'valor' => 1000, 'ativo' => true, 'fim' => null],
    ['tipo' => 'mensal', 'valor' => 500, 'ativo' => false, 'fim' => null],
    ['tipo' => 'mensal', 'valor' => 300, 'ativo' => true, 'fim' => '2020-01-01'],
]);
ok('por mês = mensal + anual/12, sem encerrado nem vencido', $r['por_mes'] === 2700.0);
ok('fixo e anual somados à parte', $r['fixo'] === 1000.0 && $r['anual'] === 1200.0);
ok('"2.600,00" vira 2600', V::numero('2.600,00') === 2600.0);
ok('"1234.5" vira 1234.5', V::numero('1234.5') === 1234.5);
ok('texto não é número', V::numero('abc') === null);

echo "\n== 2. Com banco ==\n";
$pdo = Database::getConnection();
$cli = (int) $pdo->query('SELECT id FROM clientes WHERE account_id = 304 AND deleted_at IS NULL ORDER BY id LIMIT 1')->fetchColumn();
$criados = [];
try {
    if (!$cli) throw new \RuntimeException('Sem cliente na conta 304 local.');
    ok('sem tipo é recusado', recusa(fn() => V::salvar(304, 0, $cli, ['tipo' => 'x', 'descricao' => 'A', 'valor' => 1])) !== null);
    ok('valor inválido é recusado', recusa(fn() => V::salvar(304, 0, $cli, ['tipo' => 'mensal', 'descricao' => 'A', 'valor' => 'abc'])) !== null);
    ok('dia 32 é recusado', recusa(fn() => V::salvar(304, 0, $cli, ['tipo' => 'mensal', 'descricao' => 'A', 'valor' => 1, 'dia_vencimento' => 32])) !== null);
    ok('fim antes do início é recusado', recusa(fn() => V::salvar(304, 0, $cli, ['tipo' => 'mensal', 'descricao' => 'A', 'valor' => 1, 'inicio' => '2026-11-01', 'fim' => '2026-10-01'])) !== null);
    $a = V::salvar(304, 0, $cli, ['tipo' => 'mensal', 'descricao' => 'ZZTESTE mensalidade', 'valor' => '2.600,00', 'dia_vencimento' => 10, 'inicio' => '2026-11-01']);
    $b = V::salvar(304, 0, $cli, ['tipo' => 'fixo', 'descricao' => 'ZZTESTE implantação', 'valor' => 1000, 'dia_vencimento' => 5]);
    $criados = [$a, $b];
    $d = V::doCliente(304, $cli);
    $mine = array_values(array_filter($d['itens'], fn($i) => in_array($i['id'], $criados, true)));
    ok('duas linhas salvas', count($mine) === 2);
    ok('fixo não guarda dia de vencimento', array_values(array_filter($mine, fn($i) => $i['tipo'] === 'fixo'))[0]['dia_vencimento'] === null);
    V::salvar(304, 0, $cli, ['id' => $a, 'tipo' => 'mensal', 'descricao' => 'ZZTESTE mensalidade', 'valor' => 2600, 'ativo' => false]);
    $d2 = V::doCliente(304, $cli);
    ok('encerrar tira do total por mês', $d2['resumo']['por_mes'] === round($d['resumo']['por_mes'] - 2600, 2));
    ok('outra conta não lê o cliente', recusa(fn() => V::doCliente(476, $cli)) !== null);
    ok('outra conta não grava no cliente', recusa(fn() => V::salvar(476, 0, $cli, ['tipo' => 'mensal', 'descricao' => 'X', 'valor' => 1])) !== null);
    ok('outra conta não remove', recusa(fn() => V::remover(476, $cli, $b)) !== null);
    V::remover(304, $cli, $b);
    ok('remover apaga a linha', !in_array($b, array_column(V::doCliente(304, $cli)['itens'], 'id'), true));
} catch (\Throwable $e) {
    $FALHAS[] = $e->getMessage(); echo '  [FALHA] ' . $e->getMessage() . "\n";
} finally {
    if ($criados) $pdo->exec('DELETE FROM crm_cliente_valores WHERE id IN (' . implode(',', array_map('intval', $criados)) . ')');
}
echo "\n" . ($FALHAS ? count($FALHAS) . ' FALHA(S): ' . implode(' | ', $FALHAS) : "$OK ok/0") . "\n";
exit($FALHAS ? 1 : 0);
