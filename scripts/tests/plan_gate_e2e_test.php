<?php
/**
 * Teste de COMPORTAMENTO dos gates de plano (ponta a ponta, com banco real).
 *
 * Uso: C:\xampp\php\php.exe scripts/tests/plan_gate_e2e_test.php
 *
 * Cria uma conta descartável assinando o plano 'solo', comprova que o helper
 * responde certo com a trava mestra desligada e ligada, e apaga tudo no fim.
 *
 * Os asserts que bloqueiam chamam exit(), então são testados em SUBPROCESSO:
 * dentro deste processo um exit() mataria a suíte antes da limpeza.
 *
 * A limpeza roda em finally e é ancorada no e-mail marcador, nunca em "apagar
 * o que for parecido".
 */

require_once __DIR__ . '/../../app/Helpers/PlanFeature.php';

use App\Helpers\PlanFeature;
use App\Models\Database;

$MARCADOR = '__plan_gate_e2e__@teste.invalid';
$pass = 0; $fail = 0;

function ok(string $n, bool $c, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  [ok]   $n\n"; }
    else     { $fail++; echo "  [FALHA] $n" . ($d ? " :: $d" : '') . "\n"; }
}

// Host lido da configuração, não fixo: em produção o app fala com 'yuris_db'.
$dbHost = getenv('DB_HOST') ?: null;
$dbPort = (int)(getenv('DB_PORT') ?: 0);
if (!$dbHost) {
    $envFile = __DIR__ . '/../../.env';
    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linha) {
            if (preg_match('/^\s*DB_HOST\s*=\s*"?([^"\r\n]+)"?/', $linha, $m)) $dbHost = trim($m[1]);
            if (preg_match('/^\s*DB_PORT\s*=\s*"?([^"\r\n]+)"?/', $linha, $m)) $dbPort = (int)trim($m[1]);
        }
    }
}
$dbHost = $dbHost ?: '127.0.0.1';
$dbPort = $dbPort ?: 3306;

$sock = @fsockopen($dbHost, $dbPort, $e1, $e2, 2.5);
if (!$sock) { echo "Banco indisponível em {$dbHost}:{$dbPort}. Suba o MySQL e rode de novo.\n"; exit(2); }
fclose($sock);

$pdo = Database::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "== Teste de comportamento dos gates de plano ==\n\n";

$accId = null;
$enforcementPreexistente = null;

try {
    /* ── Preparo ─────────────────────────────────────────────────────────── */
    $soloId = $pdo->query("SELECT id FROM plans WHERE slug='solo'")->fetchColumn();
    if (!$soloId) { echo "Plano 'solo' não existe. Rode antes: php database/migrations/run_110.php\n"; exit(2); }

    // Guarda o valor atual da trava para restaurar no fim.
    $st = $pdo->prepare("SELECT config_value FROM app_settings WHERE config_key=?");
    $st->execute([PlanFeature::ENFORCEMENT_KEY]);
    $v = $st->fetchColumn();
    $enforcementPreexistente = ($v === false) ? null : (string)$v;

    // codigo_vinculo é UNIQUE: precisa de valor distinto por conta, senão a
    // segunda inserção colide no '' da primeira.
    $accId = criarConta($pdo, 'ZZ Conta de teste E2E (apagar)', $MARCADOR);

    $pdo->prepare("INSERT INTO subscriptions (account_id, plan_id, status, billing_cycle) VALUES (?,?,'active','monthly')")
        ->execute([$accId, (int)$soloId]);

    echo "Conta de teste #$accId assinando o plano 'solo'\n\n";

    /* ── 1. Leitura das features do plano Solo ───────────────────────────── */
    echo "[1] O helper lê o plano Solo corretamente\n";
    ok('chat_interno DESLIGADO no Solo',  PlanFeature::isEnabled($accId, PlanFeature::F_CHAT_INTERNO) === false);
    ok('webhooks DESLIGADO no Solo',      PlanFeature::isEnabled($accId, PlanFeature::F_WEBHOOKS) === false);
    ok('aasp DESLIGADO no Solo',          PlanFeature::isEnabled($accId, PlanFeature::F_AASP) === false);
    ok('planejamento DESLIGADO no Solo',  PlanFeature::isEnabled($accId, PlanFeature::F_PLANEJAMENTO) === false);
    ok('whatsapp LIGADO no Solo',         PlanFeature::isEnabled($accId, PlanFeature::F_WHATSAPP) === true);
    ok('max_users = 2 no Solo',           PlanFeature::getLimit($accId, PlanFeature::F_MAX_USERS) === 2);
    ok('triagens = 50 no Solo',           PlanFeature::getLimit($accId, PlanFeature::F_TRIAGENS) === 50);
    ok('remaining(max_users, usado=1) = 1', PlanFeature::remaining($accId, PlanFeature::F_MAX_USERS, 1) === 1);
    ok('remaining(max_users, usado=5) = 0 (nunca negativo)', PlanFeature::remaining($accId, PlanFeature::F_MAX_USERS, 5) === 0);

    /* ── 2. Trava mestra DESLIGADA: nada bloqueia ────────────────────────── */
    echo "\n[2] Trava mestra DESLIGADA: observa, mas não bloqueia\n";
    $pdo->prepare("DELETE FROM app_settings WHERE config_key=?")->execute([PlanFeature::ENFORCEMENT_KEY]);

    $r = rodarSub($accId, 'assertEnabled', PlanFeature::F_CHAT_INTERNO);
    ok('assertEnabled não devolve nada ao cliente', trim($r['out']) === '', 'saiu: ' . substr($r['out'], 0, 120));
    ok('processo sai com código 0', $r['code'] === 0);
    // O modo observação tem que REGISTRAR. Se não registrar, ficamos cegos e
    // não dá para saber quem seria bloqueado antes de ligar a trava.
    ok('registrou a observação no log', str_contains($r['err'], '[PlanFeature][observacao]'),
       'stderr: ' . substr($r['err'], 0, 140));
    ok('log diz que NÃO bloqueou', str_contains($r['err'], 'bloqueio NÃO aplicado'));

    $r = rodarSub($accId, 'assertCanAddUser', '');
    ok('assertCanAddUser não bloqueia com 99 usuários simulados', trim($r['out']) === '');
    ok('observação do limite registrada', str_contains($r['err'], '[PlanFeature][observacao]'));

    /* ── 3. Trava mestra LIGADA: bloqueia de verdade ─────────────────────── */
    echo "\n[3] Trava mestra LIGADA: bloqueia com 402\n";
    $pdo->prepare("INSERT INTO app_settings (config_key, config_value) VALUES (?, '1')
                   ON DUPLICATE KEY UPDATE config_value='1'")
        ->execute([PlanFeature::ENFORCEMENT_KEY]);

    $r = rodarSub($accId, 'assertEnabled', PlanFeature::F_CHAT_INTERNO);
    $j = json_decode(trim($r['out']), true);
    ok('assertEnabled devolve JSON', is_array($j), 'saiu: ' . substr($r['out'], 0, 160));
    ok('JSON tem ok=false',          isset($j['ok']) && $j['ok'] === false);
    ok('JSON tem code correto',      ($j['code'] ?? null) === 'FEATURE_NOT_IN_PLAN');
    ok('JSON nomeia a feature',      ($j['feature'] ?? null) === PlanFeature::F_CHAT_INTERNO);
    ok('mensagem em português para o usuário final',
       isset($j['error']) && str_contains($j['error'], 'plano'), $j['error'] ?? '(sem error)');

    $r = rodarSub($accId, 'assertCanAddUser', '');
    $j = json_decode(trim($r['out']), true);
    ok('assertCanAddUser bloqueia acima do limite', ($j['code'] ?? null) === 'PLAN_LIMIT_EXCEEDED',
       'saiu: ' . substr($r['out'], 0, 160));
    ok('resposta informa limite e uso',
       ($j['limit'] ?? null) === 2 && isset($j['used']), json_encode($j));

    // Feature LIGADA no plano continua passando mesmo com a trava ligada.
    $r = rodarSub($accId, 'assertEnabled', PlanFeature::F_WHATSAPP);
    ok('feature incluída no plano NÃO é bloqueada', trim($r['out']) === '',
       'saiu: ' . substr($r['out'], 0, 120));

    /* ── 4. Conta sem assinatura: fail-soft libera ───────────────────────── */
    echo "\n[4] Fail-soft: conta sem assinatura continua liberada mesmo com a trava ligada\n";
    $semPlano = criarConta($pdo, 'ZZ Conta sem assinatura (apagar)', $MARCADOR);

    ok('isEnabled libera sem assinatura', PlanFeature::isEnabled($semPlano, PlanFeature::F_WEBHOOKS) === true);
    $r = rodarSub($semPlano, 'assertEnabled', PlanFeature::F_WEBHOOKS);
    ok('assertEnabled não bloqueia sem assinatura', trim($r['out']) === '',
       'saiu: ' . substr($r['out'], 0, 120));

} finally {
    /* ── Limpeza ─────────────────────────────────────────────────────────── */
    echo "\n[limpeza]\n";
    try {
        // Restaura a trava exatamente como estava antes do teste.
        $pdo->prepare("DELETE FROM app_settings WHERE config_key=?")->execute([PlanFeature::ENFORCEMENT_KEY]);
        if ($enforcementPreexistente !== null) {
            $pdo->prepare("INSERT INTO app_settings (config_key, config_value) VALUES (?,?)")
                ->execute([PlanFeature::ENFORCEMENT_KEY, $enforcementPreexistente]);
            echo "  trava mestra restaurada para '$enforcementPreexistente'\n";
        } else {
            echo "  trava mestra removida (não existia antes)\n";
        }

        // Apaga só o que este teste criou, ancorado no e-mail marcador.
        $ids = $pdo->prepare("SELECT id FROM accounts WHERE email = ?");
        $ids->execute([$MARCADOR]);
        $lista = $ids->fetchAll(PDO::FETCH_COLUMN);
        foreach ($lista as $id) {
            $pdo->prepare("DELETE FROM subscriptions WHERE account_id = ?")->execute([(int)$id]);
            $pdo->prepare("DELETE FROM accounts WHERE id = ? AND email = ?")->execute([(int)$id, $MARCADOR]);
        }
        echo "  " . count($lista) . " conta(s) de teste removida(s)\n";

        $sobrou = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE email = ?");
        $sobrou->execute([$MARCADOR]);
        echo "  sobrando: " . (int)$sobrou->fetchColumn() . " (esperado 0)\n";
    } catch (\Throwable $e) {
        echo "  ERRO NA LIMPEZA: " . $e->getMessage() . "\n";
        echo "  APAGUE À MÃO: DELETE FROM accounts WHERE email = '$MARCADOR';\n";
    }
}

echo "\n----\nResultado: $pass ok · $fail falha(s)\n";
exit($fail > 0 ? 1 : 0);

/** Cria conta descartável com codigo_vinculo único (a coluna é UNIQUE). */
function criarConta(PDO $pdo, string $nome, string $marcador): int
{
    $pdo->prepare("INSERT INTO accounts (nome, tipo, status, email, codigo_vinculo) VALUES (?,?,?,?,?)")
        ->execute([$nome, 'matriz', 'active', $marcador, 'E2E-' . bin2hex(random_bytes(5))]);
    return (int)$pdo->lastInsertId();
}

/**
 * Roda um assert em subprocesso e devolve a saída do guard + o código de saída.
 *
 * Precisa ser subprocesso porque deny() chama exit().
 *
 * stdout e stderr são capturados SEPARADAMENTE de propósito: a resposta ao
 * cliente (o JSON) sai por stdout via echo, enquanto error_log() escreve em
 * stderr. Misturar os dois com 2>&1 contamina o JSON com a linha de log e
 * quebra o json_decode, que foi exatamente o que aconteceu na primeira versão
 * deste teste.
 */
function rodarSub(int $accountId, string $metodo, string $feature): array
{
    $php  = PHP_BINARY;
    $base = str_replace('\\', '/', dirname(__DIR__, 2));
    $code = <<<PHP
require_once '{$base}/app/Helpers/PlanFeature.php';
use App\\Helpers\\PlanFeature;
if ('{$metodo}' === 'assertCanAddUser') { PlanFeature::assertUnderLimit({$accountId}, PlanFeature::F_MAX_USERS, 99, 'usuários'); }
else { PlanFeature::assertEnabled({$accountId}, '{$feature}'); }
PHP;
    $tmp    = sys_get_temp_dir() . '/plan_gate_sub_' . getmypid() . '.php';
    $errTmp = sys_get_temp_dir() . '/plan_gate_sub_' . getmypid() . '.err';
    file_put_contents($tmp, "<?php\n" . $code);

    $out = [];
    $codeOut = 0;
    exec('"' . $php . '" ' . escapeshellarg($tmp) . ' 2>' . escapeshellarg($errTmp), $out, $codeOut);

    $err = is_file($errTmp) ? (string)file_get_contents($errTmp) : '';
    @unlink($tmp);
    @unlink($errTmp);

    return [
        'out' => implode("\n", $out),   // só o que o guard devolve ao cliente
        'err' => $err,                  // log interno (observação/bloqueio)
        'code' => $codeOut,
    ];
}
