<?php
/**
 * termometro_test.php: a regra MISTA do termômetro dos leads da edição CRM.
 *
 *   - a etapa dá a partida e o teto (lead novo nasce frio, não quente);
 *   - o tempo sem contato só esfria, com limites inclusivos;
 *   - a resposta do lead esquenta até morno, só se recente, e nunca etapa congelada;
 *   - escolha manual vence tudo; coluna fora do funil vale só pelo tempo;
 *   - a validação recusa dias fora de ordem, negativos, não inteiros e nível
 *     de etapa inválido, descarta etapa desconhecida e completa pelo padrão;
 *   - gravar preserva o resto de `configuracoes` (o `produto`!), regra
 *     gravada inválida à mão volta ao padrão e o formato antigo é ignorado;
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

$R = T::PADRAO; // quente 2, morno 7, frio 30, resposta esquenta
$c = fn(?string $etapa, ?int $dias, bool $falou = false, ?string $manual = null) => T::classificar($R, $manual, $etapa, $dias, $falou);

echo "\n== 1. A etapa dá a partida ==\n";
ok('lead novo de hoje, sem resposta -> frio (não quente)', $c('novo', 0) === 'frio');
ok('em qualificação, conversa de hoje -> morno', $c('qualificacao', 0, true) === 'morno');
ok('follow-up sem resposta -> frio', $c('followup', 1) === 'frio');
ok('qualificado -> quente', $c('qualificado', 0) === 'quente');
ok('em atendimento, conversa de hoje -> quente', $c('especialista', 0) === 'quente');
ok('demonstração, contato ontem -> quente', $c('negociacao', 1) === 'quente');
ok('perdido -> congelado', $c('perdido', 0) === 'congelado');
ok('bloqueado -> congelado', $c('bloqueado', 0) === 'congelado');
ok('coluna fora do funil vale pelo tempo (hoje -> quente)', $c(null, 0) === 'quente');
ok('coluna fora do funil vale pelo tempo (10 dias -> frio)', $c(null, 10) === 'frio');

echo "\n== 2. O tempo só esfria ==\n";
ok('em atendimento há 2 dias (limite) -> quente', $c('especialista', 2) === 'quente');
ok('em atendimento esquecido há 3 dias -> morno', $c('especialista', 3) === 'morno');
ok('em atendimento há 8 dias -> frio', $c('especialista', 8) === 'frio');
ok('em atendimento há 31 dias -> congelado', $c('especialista', 31) === 'congelado');
ok('qualificação há 7 dias (limite) -> morno', $c('qualificacao', 7) === 'morno');
ok('qualificação há 8 dias -> frio', $c('qualificacao', 8) === 'frio');
ok('lead novo há 30 dias (limite) -> frio', $c('novo', 30) === 'frio');
ok('lead novo parado há 40 dias -> congelado', $c('novo', 40) === 'congelado');
ok('sem registro de contato vale só a etapa', $c('negociacao', null) === 'quente');
ok('detalhar diz que esfriou', T::detalhar($R, null, 'especialista', 4)['motivo'] === 'esfriou');

echo "\n== 3. A resposta do lead esquenta até morno ==\n";
ok('lead novo que respondeu hoje -> morno', $c('novo', 0, true) === 'morno');
ok('follow-up que respondeu ontem -> morno', $c('followup', 1, true) === 'morno');
ok('resposta não passa de morno (qualificação continua morno)', $c('qualificacao', 0, true) === 'morno');
ok('resposta antiga não esquenta (novo, respondeu há 5 dias)', $c('novo', 5, true) === 'frio');
ok('resposta não esquenta etapa congelada', $c('perdido', 0, true) === 'congelado');
ok('quente continua quente com resposta', $c('especialista', 0, true) === 'quente');
ok('detalhar diz que respondeu', T::detalhar($R, null, 'novo', 0, true)['motivo'] === 'respondeu');
$semResp = ['resposta_esquenta' => false] + $R;
ok('com a opção desligada, lead novo que respondeu fica frio', T::classificar($semResp, null, 'novo', 0, true) === 'frio');

echo "\n== 4. Escolha manual ==\n";
ok('manual vence a etapa', $c('negociacao', 0, false, 'frio') === 'frio');
ok('manual "congelado" vale', $c('novo', 0, false, 'congelado') === 'congelado');
ok('manual vence etapa congelada', $c('perdido', 0, false, 'quente') === 'quente');
ok('manual inválido é ignorado', $c('especialista', 0, false, 'fervendo') === 'quente');

echo "\n== 5. Validação ==\n";
$n = T::normalizar(['quente_dias' => '1', 'morno_dias' => 5, 'frio_dias' => 20, 'etapas' => ['novo' => 'Congelado', 'inexistente' => 'quente']]);
ok('aceita regra válida e converte para inteiro', $n['quente_dias'] === 1 && $n['morno_dias'] === 5 && $n['frio_dias'] === 20);
ok('nível da etapa normalizado', $n['etapas']['novo'] === 'congelado');
ok('descarta etapa desconhecida', !isset($n['etapas']['inexistente']));
ok('etapa que faltou vem do padrão', $n['etapas']['negociacao'] === 'quente');
ok('resposta_esquenta "on" vira true', T::normalizar(['resposta_esquenta' => 'on'])['resposta_esquenta'] === true);
ok('resposta_esquenta false fica false', T::normalizar(['resposta_esquenta' => false])['resposta_esquenta'] === false);
ok('recusa nível de etapa inválido', lanca(fn() => T::normalizar(['etapas' => ['novo' => 'fervendo']])));
ok('recusa dias fora de ordem', lanca(fn() => T::normalizar(['quente_dias' => 5, 'morno_dias' => 5, 'frio_dias' => 30])));
ok('recusa negativo', lanca(fn() => T::normalizar(['quente_dias' => -1, 'morno_dias' => 5, 'frio_dias' => 30])));
ok('recusa não inteiro', lanca(fn() => T::normalizar(['quente_dias' => '1.5', 'morno_dias' => 5, 'frio_dias' => 30])));
ok('recusa acima de 365', lanca(fn() => T::normalizar(['quente_dias' => 1, 'morno_dias' => 5, 'frio_dias' => 400])));
ok('o padrão é válido e estável', T::normalizar(T::PADRAO) === T::PADRAO);
ok('formato antigo é ignorado (vira o padrão)', T::normalizar(['etapas_quentes' => ['novo'], 'etapas_congeladas' => []]) === T::PADRAO);

echo "\n== 6. Card aceita congelado ==\n";
ok('"congelado" é temperatura válida', Card::_temperatura('Congelado') === 'congelado');

echo "\n== 7. Gravação (banco, desfeito no fim) ==\n";
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
        T::gravar($acc, ['quente_dias' => 1, 'morno_dias' => 3, 'frio_dias' => 10, 'etapas' => ['novo' => 'congelado'], 'resposta_esquenta' => false]);
        $cfg = json_decode($pdo->query("SELECT configuracoes FROM accounts WHERE id = $acc")->fetchColumn(), true);
        ok('gravar preserva produto e marca', $cfg['produto'] === 'fleetiflow' && $cfg['marca']['nome'] === 'X');
        $lida = T::daConta($acc);
        ok('a regra gravada volta pela leitura', $lida['frio_dias'] === 10 && $lida['etapas']['novo'] === 'congelado' && $lida['resposta_esquenta'] === false);
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
