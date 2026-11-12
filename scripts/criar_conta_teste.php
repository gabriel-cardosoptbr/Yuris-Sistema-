<?php
/**
 * scripts/criar_conta_teste.php — Cria a conta de um advogado em teste grátis
 * (12/11/2026), do mesmo jeito que o Painel Master cria um advogado solo.
 *
 * Espelha `public/api/master/create_account.php` (tipo='advogado'): accounts +
 * users (dono, is_advogado, OAB e código ADV) + subscriptions +
 * AccountBootstrapSeeder, numa transação, e depois a instância de WhatsApp
 * (best-effort, como no Master). Diferenças de propósito:
 *   1. Plano `teste_gratis`, conta em `trial` e assinatura em `trialing`.
 *   2. `configuracoes.periodo_teste = {"dias": N, "inicio": null}`: o prazo só
 *      começa a contar no primeiro login (App\Billing\PeriodoTeste). Até lá a
 *      tag no topo mostra "faltam N dias".
 *   3. `Account::audit()` no lugar de `MasterAudit::log()`, que exige sessão de
 *      super admin e não grava nada pela linha de comando.
 *
 * Não roda duas vezes por acidente: aborta se o login ou a OAB já existem.
 *
 * USO
 *   php scripts/criar_conta_teste.php --nome="Rodrigo Felipe Reginaldo" \
 *       --email=fulano@exemplo.com --oab=357697 --uf=SP [--dias=3] [--senha=...] \
 *       [--sem-whatsapp] [--dry-run]
 *
 * Sem --senha, gera uma senha provisória e a imprime uma vez. A pessoa troca
 * em Minha conta (api/minha_senha.php).
 * --sem-whatsapp: obrigatório no ambiente local, onde a configuração global da
 * Evolution aponta para a de produção.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Só roda pela linha de comando.\n"); }

require_once __DIR__ . '/../app/bootstrap.php';

use App\Billing\PeriodoTeste;
use App\Core\Database;
use App\Master\Account;
use App\Master\AccountBootstrapSeeder;
use App\WhatsAppAgente\WhatsAppProvisioningService;

$o = getopt('', ['nome:', 'email:', 'oab:', 'uf:', 'dias:', 'senha:', 'telefone:', 'sem-whatsapp', 'dry-run']);
$nome  = trim((string) ($o['nome'] ?? ''));
$email = strtolower(trim((string) ($o['email'] ?? '')));
$oab   = preg_replace('/\D/', '', (string) ($o['oab'] ?? ''));
$uf    = strtoupper(trim((string) ($o['uf'] ?? '')));
$dias  = (int) ($o['dias'] ?? 3);
$dry   = isset($o['dry-run']);

$erros = [];
if ($nome === '') $erros[] = '--nome é obrigatório';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $erros[] = '--email inválido';
if ($oab === '') $erros[] = '--oab é obrigatório';
if (!preg_match('/^[A-Z]{2}$/', $uf)) $erros[] = '--uf deve ter 2 letras';
if ($dias < 1 || $dias > 90) $erros[] = '--dias entre 1 e 90';
if ($erros) { fwrite(STDERR, implode("\n", $erros) . "\n"); exit(1); }

$pdo = Database::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "== Conta de teste grátis ==\n";
echo "Banco: " . $pdo->query('SELECT DATABASE()')->fetchColumn() . ($dry ? "  (dry-run, nada será gravado)" : '') . "\n";

$plano = $pdo->prepare('SELECT id, slug, ativo FROM plans WHERE slug = ? LIMIT 1');
$plano->execute([PeriodoTeste::PLANO]);
$plano = $plano->fetch(PDO::FETCH_ASSOC);
if (!$plano || !$plano['ativo']) { fwrite(STDERR, "Plano teste_gratis ausente ou inativo.\n"); exit(1); }

$dup = $pdo->prepare('SELECT id FROM users WHERE login = ? AND deleted_at IS NULL LIMIT 1');
$dup->execute([$email]);
if ($dup->fetchColumn()) { fwrite(STDERR, "Já existe usuário com o login {$email}.\n"); exit(1); }
$dupO = $pdo->prepare('SELECT id FROM users WHERE oab = ? AND deleted_at IS NULL LIMIT 1');
$dupO->execute([$oab]);
if ($dupO->fetchColumn()) { fwrite(STDERR, "Já existe advogado com a OAB {$oab}.\n"); exit(1); }

echo "Advogado: {$nome} · OAB/{$uf} {$oab} · login {$email}\n";
echo "Plano: teste_gratis (id {$plano['id']}) · {$dias} dias a partir do primeiro login\n";
if ($dry) { echo "OK no dry-run.\n"; exit(0); }

$senha = trim((string) ($o['senha'] ?? ''));
$senhaGerada = $senha === '';
if ($senhaGerada) {
    $alfa = 'abcdefghijkmnpqrstuvwxyz23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    for ($i = 0; $i < 10; $i++) $senha .= $alfa[random_int(0, strlen($alfa) - 1)];
}

$codigoAdv = null;
for ($i = 0; $i < 12 && $codigoAdv === null; $i++) {
    $c = 'ADV-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
    $ck = $pdo->prepare('SELECT id FROM users WHERE codigo_advogado = ? LIMIT 1');
    $ck->execute([$c]);
    if (!$ck->fetchColumn()) $codigoAdv = $c;
}
$codigoAdv ??= 'ADV-' . bin2hex(random_bytes(3));

try {
    $pdo->beginTransaction();

    $pdo->prepare(
        "INSERT INTO accounts (nome, email, estado, tipo, codigo_vinculo, plano, status, configuracoes, created_at, updated_at)
         VALUES (:nome, :em, :uf, 'advogado', :cod, :plano, 'trial', :cfg, NOW(), NOW())"
    )->execute([
        'nome' => $nome, 'em' => $email, 'uf' => $uf,
        'cod'  => implode('-', str_split(bin2hex(random_bytes(8)), 4)),
        'plano' => $plano['slug'],
        'cfg'  => json_encode(['periodo_teste' => ['dias' => $dias, 'inicio' => null]], JSON_UNESCAPED_UNICODE),
    ]);
    $accountId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO users (account_id, nome, login, senha_hash, perfil, role, status, telefone,
                            oab, oab_uf, codigo_advogado, is_advogado, created_at, updated_at)
         VALUES (:aid, :nome, :login, :sh, 'admin', 'owner', 'active', :tel, :oab, :uf, :adv, 1, NOW(), NOW())"
    )->execute([
        'aid' => $accountId, 'nome' => $nome, 'login' => $email,
        'sh'  => password_hash($senha, PASSWORD_BCRYPT),
        'tel' => trim((string) ($o['telefone'] ?? '')) ?: null,
        'oab' => $oab, 'uf' => $uf, 'adv' => $codigoAdv,
    ]);
    $userId = (int) $pdo->lastInsertId();

    // trial_ends_at provisório (criação + dias); o primeiro login o recalcula.
    $pdo->prepare(
        "INSERT INTO subscriptions (account_id, plan_id, status, billing_cycle, trial_ends_at,
                                    current_period_start, current_period_end, created_at, updated_at)
         VALUES (:aid, :pid, 'trialing', 'monthly', DATE_ADD(NOW(), INTERVAL :d DAY),
                 NOW(), DATE_ADD(NOW(), INTERVAL 1 MONTH), NOW(), NOW())"
    )->execute(['aid' => $accountId, 'pid' => (int) $plano['id'], 'd' => $dias]);
    $subId = (int) $pdo->lastInsertId();

    $seed = AccountBootstrapSeeder::bootstrapNew($pdo, $accountId, 'advogado', $userId);

    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Falhou, nada gravado: ' . $e->getMessage() . "\n");
    exit(1);
}

Account::audit($accountId, 'account.create', [
    'origem' => 'scripts/criar_conta_teste.php', 'tipo' => 'advogado', 'plano' => $plano['slug'],
    'periodo_teste_dias' => $dias, 'user_id' => $userId, 'subscription_id' => $subId,
    'oab' => "{$oab}/{$uf}", 'bootstrap_seed' => $seed,
]);

$wa = 'pulado (--sem-whatsapp)';
if (!isset($o['sem-whatsapp'])) {
    if (WhatsAppProvisioningService::isGloballyConfigured($pdo)) {
        $r = WhatsAppProvisioningService::provision($pdo, $accountId, $nome);
        $wa = !empty($r['success']) ? 'instância ' . ($r['instance'] ?? '?') . (!empty($r['skipped']) ? ' (já existia)' : ' criada') : 'falhou: ' . ($r['error'] ?? '?');
    } else {
        $wa = 'sem configuração global da Evolution';
    }
}

echo "----\n";
echo "Conta #{$accountId} · usuário #{$userId} · assinatura #{$subId} · código {$codigoAdv}\n";
echo "Seed: " . json_encode($seed, JSON_UNESCAPED_UNICODE) . "\n";
echo "WhatsApp: {$wa}\n";
echo "Login: {$email}\n";
if ($senhaGerada) echo "Senha provisória (aparece só agora): {$senha}\n";
