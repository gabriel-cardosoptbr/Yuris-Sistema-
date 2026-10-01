<?php
/**
 * termometro_test.php: a regra do termômetro dos leads da edição CRM.
 *
 *   - classificação: escolha manual > etapa congelada > etapa quente > dias;
 *   - os limites de dias são inclusivos e "nunca teve contato" é congelado;
 *   - a validação recusa dias fora de ordem, negativos, não inteiros e etapa
 *     nas duas listas, e descarta etapa desconhecida;
 *   - gravar preserva o resto de `configuracoes` (o `produto`!) e regra
 *     gravada inválida à mão volta ao padrão em vez de quebrar a tela;
 *   - `cards.temperatura` aceita "congelado".
 *
 * Uso: php scripts/tests/termometro_test.php
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Prospeccao\Card;
use App\Prospeccao\Termometro as T;

$OK = 0;
$FALHAS = [];

function ok(string $msg, bool $cond): void
{
    global $OK, $FALHAS;
    if ($cond) { $OK++; echo "  [ok]   $msg\n"; }
    else { $FALHAS[] = $msg; echo "  [FALHA] $msg\n"; }
}
function lanca(callable $f): bool
{
    try { $f(); return false; } catch (\InvalidArgumentException $e) { return true; }
}

$R = T::PADRAO; // quente 2, morno 7, frio 30

echo "\n== 1. Classificação ==\n";
ok('contato hoje -> quente', T::classificar($R, null, 'novo', 0) === 'quente');
ok('2 dias (limite) -> quente', T::classificar($R, null, 'novo', 2) === 'quente');
ok('3 dias -> morno', T::classificar($R, null, 'novo', 3) === 'morno');
ok('7 dias (limite) -> morno', T::classificar($R, null, 'novo', 7) === 'morno');
ok('8 dias -> frio', T::classificar($R, null, 'novo', 8) === 'frio');
ok('30 dias (limite) -> frio', T::classificar($R, null, 'novo', 30) === 'frio');
ok('31 dias -> congelado', T::classificar($R, null, 'novo', 31) === 'congelado');
ok('nunca teve contato -> congelado', T::classificar($R, null, 'novo', null) === 'congelado');
ok('etapa quente vence o tempo (negociação parada há 40 dias)', T::classificar($R, null, 'negociacao', 40) === 'quente');
ok('etapa congelada vence o contato recente (perdido ontem)', T::classificar($R, null, 'perdido', 1) === 'congelado');
ok('escolha manual vence a etapa', T::classificar($R, 'frio', 'negociacao', 0) === 'frio');
ok('manual "congelado" vale', T::classificar($R, 'congelado', 'novo', 0) === 'congelado');
ok('manual inválido é ignorado', T::classificar($R, 'fervendo', 'novo', 0) === 'quente');
ok('sem etapa conhecida, vale o tempo', T::classificar($R, null, null, 5) === 'morno');

echo "\n== 2. Validação ==\n";
$n = T::normalizar(['quente_dias' => '1', 'morno_dias' => 5, 'frio_dias' => 20, 'etapas_quentes' => ['negociacao', 'inexistente'], 'etapas_congeladas' => []]);
ok('aceita regra válida e converte para inteiro', $n['quente_dias'] === 1 && $n['morno_dias'] === 5 && $n['frio_dias'] === 20);
ok('descarta etapa desconhecida', $n['etapas_quentes'] === ['negociacao']);
ok('recusa dias fora de ordem', lanca(fn() => T::normalizar(['quente_dias' => 5, 'morno_dias' => 5, 'frio_dias' => 30])));
ok('recusa negativo', lanca(fn() => T::normalizar(['quente_dias' => -1, 'morno_dias' => 5, 'frio_dias' => 30])));
ok('recusa não inteiro', lanca(fn() => T::normalizar(['quente_dias' => '1.5', 'morno_dias' => 5, 'frio_dias' => 30])));
ok('recusa acima de 365', lanca(fn() => T::normalizar(['quente_dias' => 1, 'morno_dias' => 5, 'frio_dias' => 400])));
ok('recusa etapa quente e congelada ao mesmo tempo', lanca(fn() => T::normalizar(['etapas_quentes' => ['novo'], 'etapas_congeladas' => ['novo']] + T::PADRAO)));
ok('o padrão é válido', T::normalizar(T::PADRAO) === T::PADRAO);

echo "\n== 3. Card aceita congelado ==\n";
ok('"congelado" é temperatura válida', Card::_temperatura('Congelado') === 'congelado');

echo "\n== 4. Gravação (banco, desfeito no fim) ==\n";
try {
    $pdo = \App\Core\Database::getConnection();
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) { $pdo = null; echo "  [pulado] sem banco\n"; }
if ($pdo) {
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO accounts (nome, tipo, codigo_vinculo, plano, status, configuracoes, created_at, updated_at) VALUES ('Teste Termometro', 'matriz', ?, 'equipe', 'active', ?, NOW(), NOW())")
            ->execute([bin2hex(random_bytes(8)), json_encode(['produto' => 'fleetiflow', 'marca' => ['nome' => 'X']])]);
        $acc = (int)$pdo->lastInsertId();
        ok('conta sem regra recebe o padrão', T::daConta($acc) === T::PADRAO);
        T::gravar($acc, ['quente_dias' => 1, 'morno_dias' => 3, 'frio_dias' => 10, 'etapas_quentes' => ['especialista'], 'etapas_congeladas' => ['perdido']]);
        $cfg = json_decode($pdo->query("SELECT configuracoes FROM accounts WHERE id = $acc")->fetchColumn(), true);
        ok('gravar preserva produto e marca', $cfg['produto'] === 'fleetiflow' && $cfg['marca']['nome'] === 'X');
        ok('a regra gravada volta pela leitura', T::daConta($acc)['frio_dias'] === 10 && T::daConta($acc)['etapas_quentes'] === ['especialista']);
        ok('gravar regra inválida lança e não grava', lanca(fn() => T::gravar($acc, ['quente_dias' => 9, 'morno_dias' => 3, 'frio_dias' => 10])) && T::daConta($acc)['quente_dias'] === 1);
        $cfg['termometro'] = ['quente_dias' => 50, 'morno_dias' => 3, 'frio_dias' => 1];
        $pdo->prepare("UPDATE accounts SET configuracoes = ? WHERE id = ?")->execute([json_encode($cfg), $acc]);
        ok('regra inválida gravada à mão volta ao padrão, sem erro', T::daConta($acc) === T::PADRAO);
    } catch (\Throwable $e) {
        ok('gravação sem exceção: ' . $e->getMessage(), false);
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}

echo "\n----\n";
if (!$FALHAS) { echo "Resultado: {$OK} ok · 0 falha(s)\n"; exit(0); }
echo 'Resultado: ' . $OK . ' ok · ' . count($FALHAS) . " falha(s)\n\n";
foreach ($FALHAS as $f) echo "  - $f\n";
exit(1);
