<?php
/**
 * scripts/manutencao/sincronizar_contas_projetos.php — mede as contas reais
 * deste sistema e grava a foto delas como contas dos projetos da aba Projetos
 * (App\Projetos\Conta::sincronizar).
 *
 * Pedido da Inovaize (14/10/2026): "tem 10 contas cadastradas, quanto cada conta
 * está consumindo dentro do projeto". O Yuris e as edições CRM rodam neste mesmo
 * sistema, então o consumo de cada conta pode ser medido aqui:
 *
 *   usuários ativos · mensagens de WhatsApp nos últimos 30 dias · chamadas e
 *   custo de IA nos últimos 30 dias (ai_usage_log) · clientes · processos ·
 *   leads · plano e preço de tabela (plans)
 *
 * O custo de IA vira o "custo direto" da conta (convertido pela cotação do dólar
 * da conta destino). Receita, peso de consumo e observação NÃO são tocados: são
 * da pessoa, na tela.
 *
 * ISOLAMENTO: é uma operação de manutenção, só por CLI. Lê as contas de origem e
 * escreve só na conta destino (a dona da aba Projetos). A tela e a API da aba
 * nunca leem dado de outra conta.
 *
 * USO
 *   php scripts/manutencao/sincronizar_contas_projetos.php --conta=119 --yuris=yuris \
 *       --mapa=116:crm-fleetiflow,118:crm-via-autodoc,119:crm-inovaize            (confere)
 *   ... --aplicar                                                                 (grava)
 *
 *   --conta   conta destino (com a aba Projetos ligada)
 *   --yuris   slug do projeto que recebe TODAS as contas do produto Yuris (jurídico)
 *   --mapa    conta:slug, contas da edição CRM que vão, cada uma, para um projeto
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Só via CLI.\n"); }
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Master\Account;
use App\Projetos\Conta;
use App\Projetos\Projeto;

$o = getopt('', ['conta:', 'yuris:', 'mapa:', 'aplicar']);
$destino = (int) ($o['conta'] ?? 0);
if ($destino <= 0) exit("Informe --conta=ID (a conta com a aba Projetos).\n");
$aplicar = isset($o['aplicar']);

$pdo = \App\Core\Database::getConnection();
$acc = Account::findById($destino);
if (!$acc || !Projeto::habilitado($acc)) exit("Conta $destino não existe ou não tem a aba Projetos ligada.\n");
$cotacao = Projeto::cotacaoUsd($acc);
echo "destino: conta #{$acc['id']} \"{$acc['nome']}\" · cotação US\$ 1 = R\$ " . number_format($cotacao, 2, ',', '.') . "\n";
echo $aplicar ? "APLICANDO\n\n" : "CONFERÊNCIA: nada será gravado\n\n";

/** id do projeto pelo slug, na conta destino. */
$projeto = function (string $slug) use ($pdo, $destino): ?int {
    $st = $pdo->prepare("SELECT id FROM projetos WHERE account_id = ? AND slug = ? AND deleted_at IS NULL LIMIT 1");
    $st->execute([$destino, $slug]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int) $id;
};

/** Uma contagem que pode não existir neste banco (tabela ou coluna): null em vez de erro. */
$contar = function (string $sql, array $p) use ($pdo): ?float {
    try { $st = $pdo->prepare($sql); $st->execute($p); $v = $st->fetchColumn(); return $v === false || $v === null ? 0.0 : (float) $v; }
    catch (\Throwable $e) { return null; }
};

$precos = [];
foreach ($pdo->query("SELECT slug, nome, preco_mensal_cents FROM plans")->fetchAll(\PDO::FETCH_ASSOC) as $pl) $precos[$pl['slug']] = $pl;

$situacao = ['active' => 'ativa', 'trial' => 'teste', 'overdue' => 'inadimplente'];

/** A foto de uma conta. */
$medir = function (array $a) use ($contar, $precos, $situacao, $cotacao): array {
    $id = (int) $a['id'];
    $desde = (new \DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s');
    $iaCusto = $contar("SELECT COALESCE(SUM(estimated_cost), 0) FROM ai_usage_log WHERE account_id = ? AND created_at >= ?", [$id, $desde]);
    $plano = $precos[$a['plano']] ?? null;
    $met = array_filter([
        'mensagens_30d' => $contar("SELECT COUNT(*) FROM whatsapp_messages WHERE account_id = ? AND created_at >= ? AND deleted_at IS NULL", [$id, $desde]),
        'ia_chamadas_30d' => $contar("SELECT COUNT(*) FROM ai_usage_log WHERE account_id = ? AND created_at >= ?", [$id, $desde]),
        'ia_custo_usd_30d' => $iaCusto === null ? null : round($iaCusto, 4),
        'clientes' => $contar("SELECT COUNT(*) FROM clientes WHERE account_id = ? AND deleted_at IS NULL", [$id]),
        'processos' => $contar("SELECT COUNT(*) FROM processos WHERE account_id = ? AND deleted_at IS NULL", [$id]),
        'leads' => $contar("SELECT COUNT(*) FROM cards WHERE account_id = ? AND deleted_at IS NULL", [$id]),
        'preco_tabela' => $plano && (int) $plano['preco_mensal_cents'] > 0 ? (int) $plano['preco_mensal_cents'] / 100 : null,
    ], fn($v) => $v !== null);
    foreach (['mensagens_30d', 'ia_chamadas_30d', 'clientes', 'processos', 'leads'] as $k) if (isset($met[$k])) $met[$k] = (int) $met[$k];
    return [
        'identificador' => 'conta #' . $id,
        'nome' => (string) $a['nome'],
        'plano' => $plano['nome'] ?? ($a['plano'] ?: null),
        'status' => $situacao[$a['status']] ?? 'cancelada',
        'usuarios' => (int) $contar("SELECT COUNT(*) FROM users WHERE account_id = ? AND status = 'active' AND deleted_at IS NULL", [$id]),
        'custo_direto_centavos' => (int) round(($iaCusto ?? 0) * $cotacao * 100),
        'inicio' => $a['created_at'] ? substr((string) $a['created_at'], 0, 10) : null,
        'metricas' => $met,
    ];
};

$lotes = []; // projeto_id => [linhas]
if (!empty($o['yuris'])) {
    $pid = $projeto((string) $o['yuris']);
    if (!$pid) exit("Projeto '{$o['yuris']}' não existe na conta $destino.\n");
    $todas = $pdo->query("SELECT * FROM accounts WHERE deleted_at IS NULL ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($todas as $a) if (Account::moduloJuridicoDisponivel($a)) $lotes[$pid][] = $medir($a);
    echo "Yuris (jurídico) -> {$o['yuris']}: " . count($lotes[$pid] ?? []) . " contas\n";
}
foreach (array_filter(explode(',', (string) ($o['mapa'] ?? ''))) as $par) {
    [$idConta, $slug] = array_pad(explode(':', $par, 2), 2, '');
    $pid = $projeto(trim($slug));
    $a = Account::findById((int) $idConta);
    if (!$pid || !$a) { echo "ignorado $par: conta ou projeto não existe\n"; continue; }
    $lotes[$pid][] = $medir($a);
    echo "conta #{$a['id']} {$a['nome']} -> $slug\n";
}
echo "\n";

$pdo->beginTransaction();
try {
    foreach ($lotes as $pid => $linhas) {
        foreach (Conta::sincronizar($pdo, $destino, $pid, $linhas, $aplicar) as $l) echo $l, "\n";
    }
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    exit("\nERRO, nada gravado: " . $e->getMessage() . "\n");
}
echo $aplicar ? "\npronto.\n" : "\n(conferência) rode de novo com --aplicar.\n";
