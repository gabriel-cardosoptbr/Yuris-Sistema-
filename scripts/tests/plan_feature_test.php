<?php
/**
 * Testes do enforcement por plano (PlanFeature + migration 110).
 *
 * Uso: C:\xampp\php\php.exe scripts/tests/plan_feature_test.php
 *
 * PRECISA DE BANCO. Não dá para testar sem: App\Core\Database::getConnection()
 * faz die() quando a conexão falha (Database.php:45-53), então nenhum código do
 * sistema sobrevive a um banco fora do ar. O fail-soft que importa, e que este
 * teste cobre, é o outro: banco DE PÉ, mas sem a migration 110 / sem assinatura
 * / sem a linha em plan_features. Nesse caso tudo tem que LIBERAR, senão um
 * deploy trancaria clientes para fora.
 *
 * Parte A: fail-soft e trava mestra.
 * Parte B: grade de planos semeada pela migration 110.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Billing\PlanFeature;

$pass = 0; $fail = 0; $skip = 0;

function ok(string $nome, bool $cond, string $detalhe = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  [ok]   $nome\n"; }
    else       { $fail++; echo "  [FALHA] $nome" . ($detalhe ? " :: $detalhe" : '') . "\n"; }
}
function skip(string $nome, string $motivo): void {
    global $skip; $skip++; echo "  [skip] $nome :: $motivo\n";
}

echo "== Testes: enforcement por plano ==\n\n";

/* ─────────────────────────────────────────────────────────────────────────
   PARTE 0 — site x migration (NÃO precisa de banco)

   Este bloco existe por causa de um problema real: antes da migration 110
   havia TRÊS tabelas de preço divergentes (cards do site, JSON-LD do site e
   banco). Aqui a página pública é conferida contra a mesma fonte que alimenta
   o banco, para a divergência não voltar sem ninguém perceber.
   ───────────────────────────────────────────────────────────────────────── */
echo "[0] Página pública x grade da migration\n";

$GRADE = [
    'solo'       => ['nome' => 'Solo',       'mensal' => 149, 'anual' => 127, 'usuarios' => 2,  'monitores' => 1,  'triagens' => '50'],
    'equipe'     => ['nome' => 'Equipe',     'mensal' => 249, 'anual' => 212, 'usuarios' => 5,  'monitores' => 3,  'triagens' => '200'],
    'escritorio' => ['nome' => 'Escritório', 'mensal' => 449, 'anual' => 382, 'usuarios' => 10, 'monitores' => 6,  'triagens' => '500'],
    'studio'     => ['nome' => 'Studio',     'mensal' => 749, 'anual' => 637, 'usuarios' => 20, 'monitores' => 12, 'triagens' => '1.500'],
];

$sitePath = __DIR__ . '/../../public/planos.php';
$site = @file_get_contents($sitePath);
ok('public/planos.php legível', $site !== false);

if ($site !== false) {
    foreach ($GRADE as $slug => $g) {
        ok("site mostra R$ {$g['mensal']} ({$g['nome']})",
            str_contains($site, '<span class="plan-amount">' . $g['mensal'] . '</span>'));
        ok("site mostra anual R$ {$g['anual']} ({$g['nome']})",
            str_contains($site, 'R$ ' . $g['anual'] . '/mês'));
        ok("JSON-LD tem {$g['mensal']}.00 ({$g['nome']})",
            str_contains($site, '"price": "' . $g['mensal'] . '.00"'));
        ok("site mostra {$g['triagens']} triagens ({$g['nome']})",
            str_contains($site, '<strong>' . $g['triagens'] . '</strong> triagens'));
        ok("site mostra {$g['monitores']} monitor(es) ({$g['nome']})",
            str_contains($site, '<strong>' . $g['monitores'] . '</strong> monitor'));
    }
    // Os preços antigos não podem ter sobrado em lugar nenhum.
    foreach (['220', '370', '670'] as $velho) {
        ok("preço antigo R$ $velho não aparece mais",
            !str_contains($site, '<span class="plan-amount">' . $velho . '</span>'));
    }
    ok('faixa Enterprise presente', str_contains($site, 'enterprise-band'));
    ok('add-ons presentes', str_contains($site, 'addon-item'));
}

/* ─────────────────────────────────────────────────────────────────────────
   PARTES A e B — precisam de banco
   ───────────────────────────────────────────────────────────────────────── */

// Sonda a porta antes de chamar o Database: getConnection() faz die() e
// derrubaria o teste inteiro com uma mensagem confusa.
//
// O host NÃO pode ser fixo em 127.0.0.1: no XAMPP é isso, mas em produção o
// app roda em container e fala com o host 'yuris_db'. Com o valor fixo o teste
// passava "parcial" em produção sem avisar que pulou a metade que importa.
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

$dbUp = false;
$sock = @fsockopen($dbHost, $dbPort, $errno, $errstr, 2.5);
if ($sock) { fclose($sock); $dbUp = true; }

if (!$dbUp) {
    echo "\n[A] e [B] PULADOS: banco indisponível em {$dbHost}:{$dbPort}.\n";
    echo "    Local: suba o MySQL no painel do XAMPP e rode de novo.\n";
    echo "\n----\n";
    echo "Resultado parcial: $pass ok · $fail falha(s)\n";
    echo "ATENÇÃO: a cobertura de fail-soft e da grade NÃO foi executada.\n";
    exit($fail > 0 ? 1 : 0);
}
echo "  (banco: {$dbHost}:{$dbPort})\n";
$pdo = \App\Core\Database::getConnection();

echo "\n[A] Fail-soft: banco de pé, conta sem plano configurado -> LIBERA\n";

// account_id 0 = sessão sem conta: nunca pode bloquear
ok('isEnabled(0, chat_interno) libera', PlanFeature::isEnabled(0, PlanFeature::F_CHAT_INTERNO) === true);
ok('isEnabled(0, webhooks) libera',     PlanFeature::isEnabled(0, PlanFeature::F_WEBHOOKS) === true);

// Conta inexistente: sem linha em plan_features -> getLimit false -> libera
$idFantasma = 999999;
ok('isEnabled(conta inexistente) libera', PlanFeature::isEnabled($idFantasma, PlanFeature::F_WEBHOOKS) === true);
ok('getLimit(conta inexistente) = false (sem infra)', PlanFeature::getLimit($idFantasma, PlanFeature::F_MAX_USERS) === false);
ok('remaining() = PHP_INT_MAX sem infra', PlanFeature::remaining($idFantasma, PlanFeature::F_MAX_USERS, 999) === PHP_INT_MAX);

// A trava mestra precisa vir DESLIGADA por padrão.
ok('enforcementEnabled() desligado por padrão', PlanFeature::enforcementEnabled() === false,
   'se isto falhar, um deploy tiraria acesso de cliente sem aviso');

// assertEnabled NÃO pode matar o processo quando a feature é permitida nem
// quando a trava está desligada. Se matasse, este teste nem chegaria ao fim.
PlanFeature::assertEnabled($idFantasma, PlanFeature::F_WEBHOOKS);
PlanFeature::assertUnderLimit($idFantasma, PlanFeature::F_MAX_USERS, 9999);
ok('assertEnabled/assertUnderLimit não abortam em fail-soft', true);

// A triagem de IA nunca pode barrar o atendimento por falta de infra.
ok('hasTriagemDisponivel() libera sem infra', PlanFeature::hasTriagemDisponivel($idFantasma) === true);

// countTriagensMes é best-effort: sem banco devolve 0, não explode.
ok('countTriagensMes() não lança sem banco', is_int(PlanFeature::countTriagensMes($idFantasma)));

/* ─────────────────────────────────────────────────────────────────────────
   PARTE B — grade semeada (precisa de banco + migration 110)
   ───────────────────────────────────────────────────────────────────────── */
echo "\n[B] Grade de planos no banco\n";

// Grade esperada: espelha a planilha "YURIS - Planos Oficiais v1 (5 planos)".
// null = ilimitado.
$ESPERADO = [
    'solo'       => ['mensal' => 14900, 'max_users' => 2,    'monitors.limit' => 1,    'ai.triagens_mes' => 50,   'chat_interno' => 0, 'webhooks' => 0, 'aasp_enabled' => 0],
    'equipe'     => ['mensal' => 24900, 'max_users' => 5,    'monitors.limit' => 3,    'ai.triagens_mes' => 200,  'chat_interno' => 1, 'webhooks' => 0, 'aasp_enabled' => 0],
    'escritorio' => ['mensal' => 44900, 'max_users' => 10,   'monitors.limit' => 6,    'ai.triagens_mes' => 500,  'chat_interno' => 1, 'webhooks' => 1, 'aasp_enabled' => 1],
    'studio'     => ['mensal' => 74900, 'max_users' => 20,   'monitors.limit' => 12,   'ai.triagens_mes' => 1500, 'chat_interno' => 1, 'webhooks' => 1, 'aasp_enabled' => 1],
    'enterprise' => ['mensal' => 0,     'max_users' => null, 'monitors.limit' => null, 'ai.triagens_mes' => null, 'chat_interno' => 1, 'webhooks' => 1, 'aasp_enabled' => 1],
];

{
    $planos = $pdo->query("SELECT id, slug, preco_mensal_cents, ativo, ordem FROM plans")->fetchAll(PDO::FETCH_ASSOC);
    $porSlug = [];
    foreach ($planos as $p) $porSlug[$p['slug']] = $p;

    foreach ($ESPERADO as $slug => $exp) {
        if (!isset($porSlug[$slug])) {
            ok("plano '$slug' existe", false, 'rode: php database/migrations/run_110.php');
            continue;
        }
        $p = $porSlug[$slug];
        ok("plano '$slug' com preço correto",
            (int)$p['preco_mensal_cents'] === $exp['mensal'],
            "esperado {$exp['mensal']}, veio {$p['preco_mensal_cents']}");
        ok("plano '$slug' está ativo", (int)$p['ativo'] === 1);

        $fs = $pdo->prepare('SELECT feature_key, limit_value, is_enabled FROM plan_features WHERE plan_id = ?');
        $fs->execute([(int)$p['id']]);
        $feats = [];
        foreach ($fs->fetchAll(PDO::FETCH_ASSOC) as $f) $feats[$f['feature_key']] = $f;

        foreach ($exp as $k => $v) {
            if ($k === 'mensal') continue;
            if (!isset($feats[$k])) { ok("'$slug'.$k semeada", false, 'feature ausente'); continue; }
            $lv = $feats[$k]['limit_value'];
            $bate = ($v === null) ? ($lv === null) : ((int)$lv === (int)$v);
            ok("'$slug'.$k = " . ($v === null ? 'ilimitado' : $v), $bate,
                'veio ' . ($lv === null ? 'NULL' : $lv));
        }
    }

    // Os planos legados não podem continuar ativos concorrendo com a grade nova.
    foreach (['basico', 'profissional'] as $antigo) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM plans WHERE slug = ? AND ativo = 1');
        $st->execute([$antigo]);
        ok("plano legado '$antigo' não está mais ativo", (int)$st->fetchColumn() === 0);
    }

    // teste_gratis precisa sobreviver: é o plano usado na criação de conta advogado.
    $st = $pdo->query("SELECT COUNT(*) FROM plans WHERE slug = 'teste_gratis'");
    ok("'teste_gratis' preservado", (int)$st->fetchColumn() === 1);
}

/* ── Resumo ─────────────────────────────────────────────────────────────── */
echo "\n----\n";
echo "Resultado: $pass ok · $fail falha(s) · $skip pulado(s)\n";
exit($fail > 0 ? 1 : 0);
