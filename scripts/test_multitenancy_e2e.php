<?php
/**
 * Teste End-to-End do sistema multi-tenant Yuris.
 *
 * Executa um cenário completo:
 *   1. Cria Filial "São Paulo" + admin
 *   2. Solicita e aprova vínculo Matriz↔Filial
 *   3. Cria processo na Filial
 *   4. Valida: Matriz vê o processo da Filial automaticamente (gap M→F fechado)
 *   5. Valida: Filial NÃO vê processos da Matriz
 *   6. Compartilha 1 processo da Matriz com a Filial (resource_shares)
 *   7. Valida: Filial vê APENAS o processo compartilhado, não os outros
 *   8. Cria "advogado externo" (account própria) + compartilha 1 processo
 *   9. Valida: Advogado vê SÓ o processo compartilhado, nada mais
 *
 * Uso:  C:\xampp\php\php.exe scripts\test_multitenancy_e2e.php
 */

require __DIR__ . '/../app/Models/Database.php';
require __DIR__ . '/../app/Models/Account.php';
require __DIR__ . '/../app/Models/AccountVinculo.php';
require __DIR__ . '/../app/Models/ResourceShare.php';
require __DIR__ . '/../app/Models/Processo.php';
require __DIR__ . '/../app/Helpers/AccountContext.php';

use App\Models\Database;
use App\Models\Account;
use App\Models\AccountVinculo;
use App\Models\ResourceShare;
use App\Models\Processo;

// Inicia sessão antes de qualquer output (evita warning quando AccountContext::fromSession a recria)
if (PHP_SAPI === 'cli') @session_start();

// ── helpers ─────────────────────────────────────────────────────────────────────
function step(string $title) { echo "\n\n──── $title ────\n"; }
function ok(string $msg)     { echo "  ✔ $msg\n"; }
function fail(string $msg)   { echo "  ✘ FALHA: $msg\n"; exit(1); }
function info(string $msg)   { echo "    $msg\n"; }
function fmt($v): string { return is_array($v) ? json_encode($v) : (string)$v; }
function assert_eq($a, $b, string $msg) {
    ($a === $b) ? ok($msg . " (=" . fmt($a) . ")") : fail("$msg | esperado=" . fmt($b) . " obtido=" . fmt($a));
}
function assert_contains(array $list, $needle, string $msg) {
    in_array($needle, $list, false) ? ok($msg) : fail("$msg | '$needle' não está em " . json_encode($list));
}
function assert_not_contains(array $list, $needle, string $msg) {
    !in_array($needle, $list, false) ? ok($msg) : fail("$msg | '$needle' ESTÁ em " . json_encode($list));
}

$pdo = Database::getConnection();
$RUN = substr(bin2hex(random_bytes(3)), 0, 5); // sufixo único por execução para logins

// Registra IDs criados durante o teste para cleanup garantido mesmo se alguma asserção falhar.
$_CREATED = ['accounts' => [], 'users' => [], 'processos' => [], 'vinculos' => [], 'shares' => []];

register_shutdown_function(function () use (&$_CREATED) {
    try {
        $pdo = \App\Models\Database::getConnection();
        // Ordem: shares → vinculos → processos → users → accounts (FK-safe)
        if ($_CREATED['shares']) {
            $in = implode(',', array_map('intval', $_CREATED['shares']));
            $pdo->exec("DELETE FROM resource_shares WHERE id IN ($in)");
        }
        if ($_CREATED['accounts']) {
            $in = implode(',', array_map('intval', $_CREATED['accounts']));
            // Apaga TUDO que aponta para essas contas, na ordem segura
            $pdo->exec("DELETE FROM resource_shares WHERE from_account_id IN ($in) OR to_account_id IN ($in)");
            $pdo->exec("DELETE FROM account_vinculos WHERE matriz_account_id IN ($in) OR filial_account_id IN ($in)");
            $pdo->exec("DELETE FROM processos WHERE account_id IN ($in)");
            $pdo->exec("DELETE FROM users WHERE account_id IN ($in)");
            $pdo->exec("DELETE FROM accounts WHERE id IN ($in)");
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "\n[cleanup shutdown] " . $e->getMessage() . "\n");
    }
});

// ── 0. Estado inicial ───────────────────────────────────────────────────────────
step('0. Estado inicial');
$matriz = Account::findById(1);
if (!$matriz) fail('Conta matriz id=1 não existe');
info("Matriz: #{$matriz['id']} {$matriz['nome']} (tipo={$matriz['tipo']}, codigo={$matriz['codigo_vinculo']})");
$processosMatrizCount = (int)$pdo->query("SELECT COUNT(*) FROM processos WHERE account_id = 1 AND deleted_at IS NULL")->fetchColumn();
info("Matriz tem $processosMatrizCount processos");

// ── 1. Cria filial + admin ──────────────────────────────────────────────────────
step('1. Criar Filial + Admin');
$filialId = Account::create(['nome' => "Filial Teste E2E-{$RUN}", 'tipo' => 'filial']);
$_CREATED['accounts'][] = $filialId;
ok("Filial criada id=$filialId");
$filial = Account::findById($filialId);
info("codigo_vinculo da filial: {$filial['codigo_vinculo']}");

$stmt = $pdo->prepare(
    "INSERT INTO users (account_id, nome, login, senha_hash, perfil, role, status, codigo_advogado, created_at, updated_at)
     VALUES (:acc, 'Admin Filial SP', :login, :hash, 'admin', 'owner', 'active', :cod, NOW(), NOW())"
);
$stmt->execute([
    'acc'   => $filialId,
    'login' => "admin.filial.sp.{$RUN}",
    'hash'  => password_hash('teste123', PASSWORD_DEFAULT),
    'cod'   => 'ADV-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
]);
$adminFilialId = (int)$pdo->lastInsertId();
ok("Admin da filial criado id=$adminFilialId");

// ── 2. Solicita vínculo (filial→matriz) e aprova ────────────────────────────────
step('2. Solicitar e aprovar vínculo Matriz↔Filial');
$vinculoId = AccountVinculo::solicitar((int)$matriz['id'], $filialId, $adminFilialId);
ok("Vínculo solicitado id=$vinculoId (status=pending)");
AccountVinculo::aprovar($vinculoId, 1); // user_id=1 (admin da matriz)
$vinculo = AccountVinculo::findById($vinculoId);
assert_eq($vinculo['status'], 'active', 'Vínculo aprovado');

// ── 3. Cria processo na filial ──────────────────────────────────────────────────
step('3. Criar processo na Filial');
$procFilialId = (int) Processo::create([
    'account_id'   => $filialId,
    'numero'       => 'PROC-FILIAL-E2E-001',
    'cliente_nome' => 'Cliente Filial SP',
    'status'       => 'ativo',
]);
ok("Processo da filial criado id=$procFilialId");

// ── 4. Matriz deve ver o processo da filial automaticamente ────────────────────
step('4. Matriz vê processos da filial vinculada (gap M→F)');
$_SESSION = ['user_id'=>1,'account_id'=>1,'account_tipo'=>'matriz','user_role'=>'owner'];
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION = ['user_id'=>1,'account_id'=>1,'account_tipo'=>'matriz','user_role'=>'owner'];
$ctxMatriz = App\Helpers\AccountContext::fromSession();
$acessoMatriz = $ctxMatriz->getAccessibleAccountIds();
assert_contains($acessoMatriz, 1, "Matriz tem acesso ao próprio account_id");
assert_contains($acessoMatriz, $filialId, "Matriz herdou acesso à filial id=$filialId");

$processosVistosPelaMatriz = Processo::list(['account_ids' => $acessoMatriz]);
$numeros = array_column($processosVistosPelaMatriz, 'numero');
assert_contains($numeros, 'PROC-FILIAL-E2E-001', "Matriz VÊ o processo da filial");
info("Matriz enxerga " . count($processosVistosPelaMatriz) . " processos no total");

// ── 5. Filial NÃO vê processos da matriz ────────────────────────────────────────
step('5. Filial NÃO vê processos da matriz (assimetria)');
$_SESSION = ['user_id'=>$adminFilialId,'account_id'=>$filialId,'account_tipo'=>'filial','user_role'=>'owner'];
$ctxFilial = App\Helpers\AccountContext::fromSession();
$acessoFilial = $ctxFilial->getAccessibleAccountIds();
assert_eq(count($acessoFilial), 1, "Filial só vê o próprio tenant");
assert_eq($acessoFilial[0], $filialId, "Filial->accessible == [filialId]");

$processosVistosPelaFilial = Processo::list(['account_ids' => $acessoFilial]);
$numerosFilial = array_column($processosVistosPelaFilial, 'numero');
assert_contains($numerosFilial, 'PROC-FILIAL-E2E-001', "Filial vê o próprio processo");
info("Filial enxerga " . count($processosVistosPelaFilial) . " processo(s)");
if (count($processosVistosPelaFilial) > 1) fail("Filial está vendo mais processos do que deveria");

// ── 6. Matriz compartilha 1 processo dela com a filial (share específico) ──────
step('6. Matriz compartilha 1 processo específico com a Filial');
// pega um processo qualquer da matriz
$procMatrizId = (int)$pdo->query("SELECT id FROM processos WHERE account_id = 1 AND deleted_at IS NULL LIMIT 1")->fetchColumn();
$shareId = ResourceShare::create([
    'resource_type'    => 'processo',
    'resource_id'      => $procMatrizId,
    'from_account_id'  => 1,
    'to_account_id'    => $filialId,
    'permission_level' => 'view',
    'criado_por'       => 1,
]);
ok("Resource share id=$shareId criado (matriz→filial, processo #$procMatrizId)");

// ── 7. Filial agora vê SEU processo + o compartilhado, nada mais ───────────────
step('7. Filial vê próprio + compartilhado, NÃO os outros');
$processosFilialAgora = Processo::list(['account_ids' => $acessoFilial]);
$idsFilial = array_map(fn($p) => (int)$p['id'], $processosFilialAgora);
info("Filial agora enxerga " . count($processosFilialAgora) . " processos");
assert_contains($idsFilial, $procFilialId, "Filial vê o próprio processo");
assert_contains($idsFilial, $procMatrizId, "Filial vê o processo compartilhado pela matriz");

// pega um processo da matriz que NÃO foi compartilhado
$outroProcMatriz = (int)$pdo->query("SELECT id FROM processos WHERE account_id = 1 AND id != $procMatrizId AND deleted_at IS NULL LIMIT 1")->fetchColumn();
assert_not_contains($idsFilial, $outroProcMatriz, "Filial NÃO vê processo da matriz não compartilhado (#$outroProcMatriz)");

// ── 8. "Advogado externo": cria conta isolada + share só de 1 processo ─────────
step('8. Advogado associado externo (conta isolada)');
$advAccountId = Account::create(['nome' => "Dr. Externo E2E-{$RUN}", 'tipo' => 'matriz']);
$_CREATED['accounts'][] = $advAccountId;
ok("Conta do advogado criada id=$advAccountId (matriz isolada, sem vínculo)");
$stmt = $pdo->prepare(
    "INSERT INTO users (account_id, nome, login, senha_hash, perfil, role, status, codigo_advogado, created_at, updated_at)
     VALUES (:acc, 'Dr. Externo', :login, :h, 'admin', 'owner', 'active', :cod, NOW(), NOW())"
);
$stmt->execute([
    'acc'   => $advAccountId,
    'login' => "dr.externo.{$RUN}",
    'h'     => password_hash('teste123', PASSWORD_DEFAULT),
    'cod'   => 'ADV-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
]);
$advUserId = (int)$pdo->lastInsertId();

// Matriz compartilha 1 processo específico com o advogado
$advShareId = ResourceShare::create([
    'resource_type'    => 'processo',
    'resource_id'      => $procFilialId, // compartilha o processo da filial com o advogado
    'from_account_id'  => 1,             // matriz (que enxerga a filial) faz o share
    'to_account_id'    => $advAccountId,
    'permission_level' => 'view',
    'criado_por'       => 1,
]);
ok("Share matriz→advogado criado id=$advShareId (processo #$procFilialId)");

// ── 9. Advogado vê SÓ o processo compartilhado ─────────────────────────────────
step('9. Advogado vê APENAS o processo convidado');
$_SESSION = ['user_id'=>$advUserId,'account_id'=>$advAccountId,'account_tipo'=>'matriz','user_role'=>'owner'];
$ctxAdv = App\Helpers\AccountContext::fromSession();
$acessoAdv = $ctxAdv->getAccessibleAccountIds();
assert_eq($acessoAdv, [$advAccountId], "Advogado vê só sua própria conta (isolada)");

$processosAdv = Processo::list(['account_ids' => $acessoAdv]);
info("Advogado enxerga " . count($processosAdv) . " processo(s)");
assert_eq(count($processosAdv), 1, "Advogado vê exatamente 1 processo (o compartilhado)");
assert_eq((int)$processosAdv[0]['id'], $procFilialId, "É o processo que foi compartilhado com ele");

// ── 10. Compartilhamento individual via codigo_advogado ────────────────────────
step('10. Compartilhar processo com UM advogado específico (to_user_id)');
// Pega código do advogado externo (já é user advUserId)
$advCodigo = $pdo->prepare("SELECT codigo_advogado FROM users WHERE id = ?");
$advCodigo->execute([$advUserId]);
$codigoAdv = $advCodigo->fetchColumn();
info("Código do advogado: $codigoAdv");
if (!$codigoAdv || strpos($codigoAdv, 'ADV-') !== 0) fail("codigo_advogado não foi gerado");
ok("codigo_advogado válido");

// Cria 2º processo na matriz que NÃO será compartilhado individualmente
$proc2 = (int) Processo::create([
    'account_id'   => 1,
    'numero'       => "PROC-MATRIZ-2-{$RUN}",
    'cliente_nome' => 'Cliente Matriz 2',
    'status'       => 'ativo',
]);
ok("Processo 2 criado na matriz id=$proc2");

// Compartilha proc2 com USER específico (não com toda a conta dele)
$shareUserId = ResourceShare::create([
    'resource_type'    => 'processo',
    'resource_id'      => $proc2,
    'from_account_id'  => 1,
    'to_user_id'       => $advUserId,
    'to_account_id'    => $advAccountId,
    'permission_level' => 'view',
    'criado_por'       => 1,
]);
ok("Share individual user_id=$advUserId criado id=$shareUserId");

// Filtro deve considerar to_user_id
$_SESSION = ['user_id'=>$advUserId,'account_id'=>$advAccountId,'account_tipo'=>'matriz','user_role'=>'owner'];
$ctxAdv2 = App\Helpers\AccountContext::fromSession();
$processosAdv2 = Processo::list([
    'account_ids' => $ctxAdv2->getAccessibleAccountIds(),
    'user_id'     => $ctxAdv2->getUserId(),
]);
$idsAdv2 = array_map(fn($p) => (int)$p['id'], $processosAdv2);
assert_contains($idsAdv2, $proc2, "Advogado vê processo compartilhado individualmente (to_user_id)");
assert_contains($idsAdv2, $procFilialId, "Advogado ainda vê processo compartilhado para conta dele");

// ── 11. Module share: matriz libera "juridico" para conta isolada ──────────────
step('11. Module share: matriz libera módulo juridico para o advogado');
$moduleShareId = ResourceShare::create([
    'resource_type'    => 'module',
    'resource_id'      => 0,
    'module_key'       => 'juridico',
    'from_account_id'  => 1,
    'to_account_id'    => $advAccountId,
    'permission_level' => 'view',
    'criado_por'       => 1,
]);
ok("Module share juridico criado id=$moduleShareId");

// Agora o advogado deve ver os account_ids da matriz quando passar module='juridico'
$advAccessibleNoModule = $ctxAdv2->getAccessibleAccountIds();
$advAccessibleJuridico = $ctxAdv2->getAccessibleAccountIds('juridico');
assert_eq($advAccessibleNoModule, [$advAccountId], "Sem módulo: advogado só vê própria conta");
assert_contains($advAccessibleJuridico, 1, "Com módulo juridico: advogado vê também a matriz id=1");
info("accessible[juridico]: " . json_encode($advAccessibleJuridico));

// ── 12. Cleanup ─────────────────────────────────────────────────────────────────
step('12. Cleanup');
// O cleanup real é feito pelo register_shutdown_function (chamado mesmo em falha).
// Aqui só anotamos que vamos limpar; o shutdown apaga tudo na ordem segura.
ok("Cleanup será executado por shutdown handler (ordem FK-safe)");

echo "\n\n══════════════════════════════════════════════════\n";
echo "✅ TODOS OS CENÁRIOS PASSARAM\n";
echo "══════════════════════════════════════════════════\n";
