<?php
/**
 * Runner da migration 110 (grade oficial de planos v1).
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_110.php
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_110.php
 *
 * Flags:
 *   --print-matrix  imprime a grade e sai. NÃO toca no banco, serve para
 *                   conferir os números contra a planilha antes de aplicar.
 *   --dry-run       conecta e diz o que faria, sem gravar nada.
 *
 * IDEMPOTENTE: rodar duas vezes não duplica plano nem feature.
 * NÃO DESTRUTIVO: os planos legados são renomeados + desativados, nunca
 * apagados, porque assinaturas existentes apontam para plan_id e devem
 * continuar valendo o preço que o cliente contratou.
 */

$dryRun      = in_array('--dry-run', $argv ?? [], true);
$printMatrix = in_array('--print-matrix', $argv ?? [], true);

/* ── Definição da grade (fonte: planilha "YURIS - Planos Oficiais v1") ────── */
// null = ilimitado · 0 = desabilitado · N = limite numérico
$PLANOS = [
    'solo' => [
        'nome' => 'Solo',
        'descricao' => 'Para o advogado autônomo organizar a rotina e não perder prazo nem cliente.',
        'mensal' => 14900, 'anual' => 152400, 'trial' => 14, 'destaque' => 0, 'ordem' => 1,
        'features' => [
            'max_users' => 2, 'monitors.limit' => 1, 'ai.triagens_mes' => 50,
            'max_filiais' => 0, 'chat_interno' => 0, 'webhooks' => 0,
            'aasp_enabled' => 0, 'planejamento' => 0, 'advogados_associados' => 0,
            'whatsapp_enabled' => 1, 'integracoes_api' => 0,
            'max_processos' => null, 'max_cards' => null,
        ],
    ],
    'equipe' => [
        'nome' => 'Equipe',
        'descricao' => 'Para o escritório pequeno que atende e capta em equipe.',
        'mensal' => 24900, 'anual' => 254400, 'trial' => 14, 'destaque' => 1, 'ordem' => 2,
        'features' => [
            'max_users' => 5, 'monitors.limit' => 3, 'ai.triagens_mes' => 200,
            'max_filiais' => 0, 'chat_interno' => 1, 'webhooks' => 0,
            'aasp_enabled' => 0, 'planejamento' => 1, 'advogados_associados' => 1,
            'whatsapp_enabled' => 1, 'integracoes_api' => 0,
            'max_processos' => null, 'max_cards' => null,
        ],
    ],
    'escritorio' => [
        'nome' => 'Escritório',
        'descricao' => 'Para o escritório estruturado que quer automação, AASP e unidades conectadas.',
        'mensal' => 44900, 'anual' => 458400, 'trial' => 14, 'destaque' => 0, 'ordem' => 3,
        'features' => [
            'max_users' => 10, 'monitors.limit' => 6, 'ai.triagens_mes' => 500,
            'max_filiais' => 3, 'chat_interno' => 1, 'webhooks' => 1,
            'aasp_enabled' => 1, 'planejamento' => 1, 'advogados_associados' => 1,
            'whatsapp_enabled' => 1, 'integracoes_api' => 1,
            'max_processos' => null, 'max_cards' => null,
        ],
    ],
    'studio' => [
        'nome' => 'Studio',
        'descricao' => 'Para bancas maiores: operação completa e prioridade no suporte.',
        'mensal' => 74900, 'anual' => 764400, 'trial' => 14, 'destaque' => 0, 'ordem' => 4,
        'features' => [
            'max_users' => 20, 'monitors.limit' => 12, 'ai.triagens_mes' => 1500,
            'max_filiais' => null, 'chat_interno' => 1, 'webhooks' => 1,
            'aasp_enabled' => 1, 'planejamento' => 1, 'advogados_associados' => 1,
            'whatsapp_enabled' => 1, 'integracoes_api' => 1,
            'max_processos' => null, 'max_cards' => null,
        ],
    ],
    'enterprise' => [
        'nome' => 'Enterprise',
        'descricao' => 'Sob consulta. Implantação assistida, migração de dados e integrações sob medida. Mensalidade negociada.',
        'mensal' => 0, 'anual' => 0, 'trial' => 0, 'destaque' => 0, 'ordem' => 5,
        'features' => [
            'max_users' => null, 'monitors.limit' => null, 'ai.triagens_mes' => null,
            'max_filiais' => null, 'chat_interno' => 1, 'webhooks' => 1,
            'aasp_enabled' => 1, 'planejamento' => 1, 'advogados_associados' => 1,
            'whatsapp_enabled' => 1, 'integracoes_api' => 1,
            'max_processos' => null, 'max_cards' => null,
        ],
    ],
];

/* ── --print-matrix: confere a grade sem tocar no banco ──────────────────── */
if ($printMatrix) {
    echo "== Grade oficial de planos v1 (migration 110) ==\n\n";
    $keys = array_keys($PLANOS['solo']['features']);
    printf("%-22s", 'feature');
    foreach ($PLANOS as $slug => $_) printf("%12s", $slug);
    echo "\n" . str_repeat('-', 22 + 12 * count($PLANOS)) . "\n";

    printf("%-22s", 'preço/mês');
    foreach ($PLANOS as $def) printf("%12s", 'R$ ' . number_format($def['mensal'] / 100, 0, ',', '.'));
    echo "\n";
    printf("%-22s", 'preço/ano');
    foreach ($PLANOS as $def) printf("%12s", 'R$ ' . number_format($def['anual'] / 100, 0, ',', '.'));
    echo "\n\n";

    foreach ($keys as $k) {
        printf("%-22s", $k);
        foreach ($PLANOS as $def) {
            // array_key_exists e NÃO ??: o valor null aqui significa "ilimitado",
            // e o ?? o confundiria com chave ausente.
            if (!array_key_exists($k, $def['features'])) { printf("%12s", '(AUSENTE)'); continue; }
            $v = $def['features'][$k];
            printf("%12s", $v === null ? 'ilimitado' : $v);
        }
        echo "\n";
    }

    // Sanidade: toda chave precisa existir em TODOS os planos, senão um plano
    // fica com a feature ausente e o BillingGuard libera por fail-soft.
    echo "\n-- checagem de consistência --\n";
    $erros = 0;
    foreach ($PLANOS as $slug => $def) {
        $faltando = array_diff($keys, array_keys($def['features']));
        $sobrando = array_diff(array_keys($def['features']), $keys);
        if ($faltando) { echo "  [ERRO] '$slug' sem: " . implode(', ', $faltando) . "\n"; $erros++; }
        if ($sobrando) { echo "  [ERRO] '$slug' com chave extra: " . implode(', ', $sobrando) . "\n"; $erros++; }
    }
    // O anual precisa bater com ~15% de desconto sobre 12x o mensal.
    foreach ($PLANOS as $slug => $def) {
        if ($def['mensal'] === 0) continue;
        $esperado = round($def['mensal'] * 0.85 / 100) * 12 * 100;
        if (abs($def['anual'] - $esperado) > 1200) {
            echo "  [ERRO] '$slug' anual={$def['anual']} não bate com 15% off (esperado ~{$esperado})\n";
            $erros++;
        }
    }
    // Cada plano precisa ser >= que o anterior nos limites numéricos.
    $ordem = ['solo', 'equipe', 'escritorio', 'studio'];
    foreach (['max_users', 'monitors.limit', 'ai.triagens_mes'] as $k) {
        for ($i = 1; $i < count($ordem); $i++) {
            $ant = $PLANOS[$ordem[$i - 1]]['features'][$k];
            $atu = $PLANOS[$ordem[$i]]['features'][$k];
            if ($ant !== null && $atu !== null && $atu < $ant) {
                echo "  [ERRO] '$k' regride de {$ordem[$i-1]}($ant) para {$ordem[$i]}($atu)\n";
                $erros++;
            }
        }
    }
    echo $erros === 0 ? "  [ok] grade consistente\n" : "  $erros problema(s)\n";
    exit($erros === 0 ? 0 : 1);
}

require_once __DIR__ . '/../../app/Models/Database.php';

use App\Models\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "== Migration 110: grade oficial de planos v1 ==\n";
echo "DB: " . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
if ($dryRun) echo "MODO: dry-run (nada será gravado)\n";
echo "----\n";

/* ── 1. Aposenta os planos legados ───────────────────────────────────────── */
// teste_gratis e pago_padrao ficam intocados de propósito: o primeiro é o
// trial usado na criação de contas advogado, o segundo é placeholder em uso.
echo "[1] Aposentando planos legados\n";
// Slugs que ESTE run libera ao renomear. Em --dry-run o UPDATE não acontece,
// então sem esta lista o passo [2] enxergaria o 'enterprise' antigo ainda
// ocupando o slug e reportaria "já existe", escondendo que o plano novo seria
// criado. O relatório do dry-run precisa refletir o resultado real.
$slugsLiberados = [];
foreach (['basico', 'profissional', 'enterprise'] as $slugLegado) {
    $st = $pdo->prepare('SELECT id, nome FROM plans WHERE slug = ? LIMIT 1');
    $st->execute([$slugLegado]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo "  [skip] '$slugLegado' não existe\n"; continue; }

    // Se já existe o *_legado, não mexe (migration já rodou antes).
    $chk = $pdo->prepare('SELECT COUNT(*) FROM plans WHERE slug = ?');
    $chk->execute([$slugLegado . '_legado']);
    if ((int)$chk->fetchColumn() > 0) {
        echo "  [skip] '{$slugLegado}_legado' já existe\n";
        continue;
    }

    $nAssin = $pdo->prepare('SELECT COUNT(*) FROM subscriptions WHERE plan_id = ?');
    $nAssin->execute([(int)$row['id']]);
    $qtd = (int)$nAssin->fetchColumn();

    if (!$dryRun) {
        $up = $pdo->prepare('UPDATE plans SET slug = ?, ativo = 0 WHERE id = ?');
        $up->execute([$slugLegado . '_legado', (int)$row['id']]);
    }
    $slugsLiberados[] = $slugLegado;
    echo "  [ok] '$slugLegado' -> '{$slugLegado}_legado' (ativo=0)"
       . ($qtd > 0 ? "  ATENÇÃO: $qtd assinatura(s) continuam nele" : "") . "\n";
}

/* ── 2 e 3. Cria os planos novos + features ──────────────────────────────── */
echo "[2] Planos novos\n";
$planIds = [];
foreach ($PLANOS as $slug => $def) {
    $st = $pdo->prepare('SELECT id FROM plans WHERE slug = ? LIMIT 1');
    $st->execute([$slug]);
    $id = $st->fetchColumn();

    // Em dry-run o slug liberado no passo [1] ainda está ocupado no banco pelo
    // plano legado. Ignora esse "já existe" para não mascarar a criação.
    if ($id && $dryRun && in_array($slug, $slugsLiberados, true)) $id = false;

    if ($id) {
        $planIds[$slug] = (int)$id;
        echo "  [skip] '$slug' já existe (id={$id})\n";
        continue;
    }
    if ($dryRun) { echo "  [dry] criaria '$slug' (" . number_format($def['mensal'] / 100, 2, ',', '.') . "/mês)\n"; continue; }

    $ins = $pdo->prepare(
        'INSERT INTO plans (slug, nome, descricao, preco_mensal_cents, preco_anual_cents,
                            moeda, trial_dias, ativo, destaque, ordem)
         VALUES (?,?,?,?,?,"BRL",?,1,?,?)'
    );
    $ins->execute([
        $slug, $def['nome'], $def['descricao'], $def['mensal'], $def['anual'],
        $def['trial'], $def['destaque'], $def['ordem'],
    ]);
    $planIds[$slug] = (int)$pdo->lastInsertId();
    echo "  [ok] '$slug' criado (id={$planIds[$slug]}, R$ "
       . number_format($def['mensal'] / 100, 2, ',', '.') . "/mês)\n";
}

echo "[3] Features por plano\n";
$totIns = 0; $totSkip = 0;
foreach ($PLANOS as $slug => $def) {
    if (!isset($planIds[$slug])) { echo "  [skip] '$slug' sem id (dry-run)\n"; continue; }
    $pid = $planIds[$slug];
    $novas = [];
    foreach ($def['features'] as $fk => $lv) {
        $chk = $pdo->prepare('SELECT COUNT(*) FROM plan_features WHERE plan_id = ? AND feature_key = ?');
        $chk->execute([$pid, $fk]);
        if ((int)$chk->fetchColumn() > 0) { $totSkip++; continue; }
        if (!$dryRun) {
            $ins = $pdo->prepare('INSERT INTO plan_features (plan_id, feature_key, limit_value, is_enabled) VALUES (?,?,?,1)');
            $ins->execute([$pid, $fk, $lv]); // null vira NULL = ilimitado
        }
        $novas[] = $fk . '=' . ($lv === null ? '∞' : $lv);
        $totIns++;
    }
    echo "  [$slug] " . (count($novas) ? count($novas) . ' feature(s): ' . implode(', ', $novas) : 'nada novo') . "\n";
}
echo "  total: $totIns inserida(s), $totSkip já existiam\n";

/* ── Resumo final ────────────────────────────────────────────────────────── */
echo "----\n[resumo] Planos ativos:\n";
foreach ($pdo->query('SELECT slug, nome, preco_mensal_cents, ativo, ordem FROM plans ORDER BY ativo DESC, ordem, id') as $p) {
    printf("  %-22s %-22s R$ %10s  %s\n",
        $p['slug'], $p['nome'],
        number_format(((int)$p['preco_mensal_cents']) / 100, 2, ',', '.'),
        ((int)$p['ativo'] === 1 ? 'ativo' : 'INATIVO'));
}
echo "== Migration 110 " . ($dryRun ? "(dry-run) " : "") . "concluida ==\n";
