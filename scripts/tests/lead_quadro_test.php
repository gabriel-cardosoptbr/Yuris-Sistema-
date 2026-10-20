<?php
/**
 * lead_quadro_test.php: o lead em mais de uma etapa e em mais de um setor, e o que
 * o card mostra a mais (App\Prospeccao\QuadroDoLead, migration 156).
 *
 *   - etapas a mais: a principal não entra; etapa de outra conta é recusada;
 *   - setores: lead sem setor ganha o primeiro marcado como principal; os outros
 *     ficam a mais; setor de outra conta é recusado;
 *   - quadro: etapas e setores a mais, orçamento aprovado e último contato;
 *   - outra conta não lê nem grava.
 *
 * Usa um lead da conta CRM local 304; tudo o que cria é desfeito no fim.
 * Uso: php scripts/tests/lead_quadro_test.php
 */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Prospeccao\QuadroDoLead as Q;

$OK = 0; $FALHAS = [];
function ok(string $m, bool $c): void { global $OK, $FALHAS; if ($c) { $OK++; echo "  [ok]   $m\n"; } else { $FALHAS[] = $m; echo "  [FALHA] $m\n"; } }
function recusa(callable $f): ?string { try { $f(); return null; } catch (\InvalidArgumentException $e) { return $e->getMessage(); } }

$pdo = Database::getConnection();
$card = $pdo->query('SELECT id, coluna_id, team_id FROM cards WHERE account_id = 304 AND deleted_at IS NULL AND team_id IS NULL ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
$orc = null; $inter = null;
try {
    if (!$card) throw new \RuntimeException('Sem lead sem setor na conta 304 local.');
    $id = (int) $card['id'];
    $etapas = array_column(Q::etapasDaConta(304), 'id');
    $setores = array_column(Q::setoresDaConta(304), 'id');
    $outras = array_values(array_diff($etapas, [(int) $card['coluna_id']]));

    echo "\n== Etapas ==\n";
    $r = Q::salvarEtapas(304, 0, $id, [$outras[0], $outras[1], (int) $card['coluna_id']]);
    ok('duas etapas a mais; a principal não entra', $r['etapas']['principal'] === (int) $card['coluna_id'] && count($r['etapas']['extras']) === 2);
    $colOutra = (int) $pdo->query('SELECT id FROM pipeline_columns WHERE account_id <> 304 ORDER BY id LIMIT 1')->fetchColumn();
    if ($colOutra) ok('etapa de outra conta é recusada', recusa(fn() => Q::salvarEtapas(304, 0, $id, [$colOutra])) !== null);
    ok('outra conta não grava etapas do lead', recusa(fn() => Q::salvarEtapas(476, 0, $id, [])) !== null);

    echo "\n== Setores ==\n";
    if (count($setores) >= 2) {
        $r = Q::salvarSetores(304, 0, $id, [$setores[1], $setores[0]]);
        ok('lead sem setor ganha o primeiro marcado como principal', $r['setores']['principal'] === $setores[1] && $r['setores']['extras'] === [$setores[0]]);
        $r = Q::salvarSetores(304, 0, $id, [$setores[1]]);
        ok('desmarcar o setor a mais', $r['setores']['extras'] === []);
        Q::salvarSetores(304, 0, $id, [$setores[1], $setores[0]]);
    }
    $teamOutra = (int) $pdo->query('SELECT id FROM teams WHERE account_id <> 304 AND deleted_at IS NULL ORDER BY id LIMIT 1')->fetchColumn();
    if ($teamOutra) ok('setor de outra conta é recusado', recusa(fn() => Q::salvarSetores(304, 0, $id, [$teamOutra])) !== null);
    ok('outra conta não lê o lead', recusa(fn() => Q::doLead(476, $id)) !== null);

    echo "\n== Quadro ==\n";
    $num = (int) $pdo->query('SELECT COALESCE(MAX(numero), 0) + 1 FROM crm_orcamentos WHERE account_id = 304')->fetchColumn();
    $pdo->prepare("INSERT INTO crm_orcamentos (account_id, numero, card_id, cliente_nome, status) VALUES (304, ?, ?, 'ZZTESTE', 'APROVADO')")->execute([$num, $id]);
    $orc = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO crm_orcamento_itens (account_id, orcamento_id, descricao, quantidade, valor_unitario) VALUES (304, ?, 'ZZTESTE', 2, 600)")->execute([$orc]);
    $pdo->prepare("INSERT INTO crm_interacoes (account_id, entidade, entidade_id, tipo, ocorrido_em) VALUES (304, 'card', ?, 'ligacao', NOW())")->execute([$id]);
    $inter = (int) $pdo->lastInsertId();
    $q = Q::quadro([304], 304);
    $l = $q['leads'][$id] ?? null;
    ok('quadro traz as etapas e os setores a mais', $l && count($l['etapas_extras']) === 2 && (count($setores) < 2 || ($l['setor'] === $setores[1] && $l['setores_extras'] === [$setores[0]])));
    ok('quadro traz o orçamento aprovado com o total', $l && $l['orcamentos']['total'] === 1 && $l['orcamentos']['aprovado'] === 1200.0);
    ok('quadro traz o último contato registrado', $l && $l['ultimo_contato'] !== null);
    ok('quadro traz o básico para a cópia (nome)', $l && ($l['nome'] ?? '') !== '');
    ok('quadro de outra conta não traz o lead', !isset(Q::quadro([476], 476)['leads'][$id]));
} catch (\Throwable $e) {
    $FALHAS[] = $e->getMessage(); echo '  [FALHA] ' . $e->getMessage() . "\n";
} finally {
    if ($card) {
        $pdo->prepare('DELETE FROM crm_card_etapas_extras WHERE card_id = ?')->execute([$card['id']]);
        $pdo->prepare('DELETE FROM crm_card_setores_extras WHERE card_id = ?')->execute([$card['id']]);
        $pdo->prepare('UPDATE cards SET team_id = NULL WHERE id = ?')->execute([$card['id']]);
    }
    if ($orc) { $pdo->exec("DELETE FROM crm_orcamento_itens WHERE orcamento_id = $orc"); $pdo->exec("DELETE FROM crm_orcamentos WHERE id = $orc"); }
    if ($inter) $pdo->exec("DELETE FROM crm_interacoes WHERE id = $inter");
}
echo "\n" . ($FALHAS ? count($FALHAS) . ' FALHA(S): ' . implode(' | ', $FALHAS) : "$OK ok/0") . "\n";
exit($FALHAS ? 1 : 0);
