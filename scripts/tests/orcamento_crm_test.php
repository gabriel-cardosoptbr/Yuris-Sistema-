<?php
/**
 * orcamento_crm_test.php: o orçamento do CRM (app/Orcamentos, migration 143).
 *
 *   - a decomposição bate com a planilha do despachante: o cliente vê Detran
 *     R$ 295,91 + Honorário R$ 200,00; o escritório, Detran imediato, SERPRO
 *     mensal e honorário R$ 191,39;
 *   - débito do veículo (IPVA, multas) é repasse integral, nunca honorário;
 *   - criar, aprovar (valor da proposta no lead), fechar negócio (valor fechado
 *     no lead), rejeitar (sai dos ativos), reabrir e editar (volta a rascunho);
 *   - convertido não muda; outra conta não vê nem usa nada desta.
 *
 * Linhas marcadas com ZZTESTE_ORC_<pid>, apagadas no fim (o lead de teste fica
 * excluído logicamente: o histórico dele é imutável).
 * Uso: php scripts/tests/orcamento_crm_test.php
 */
if (PHP_SAPI !== 'cli') exit;

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Orcamentos\Catalogo;
use App\Orcamentos\Decomposicao;
use App\Orcamentos\Orcamento;

$OK = 0;
$FALHAS = [];
function ok(string $msg, bool $cond): void
{
    global $OK, $FALHAS;
    if ($cond) { $OK++; echo "  [ok]   $msg\n"; }
    else { $FALHAS[] = $msg; echo "  [FALHA] $msg\n"; }
}
function recusa(callable $f): ?string
{
    try { $f(); return null; } catch (\InvalidArgumentException $e) { return $e->getMessage(); }
}

echo "\n== 1. A conta (sem banco) ==\n";
$transf = ['natureza' => 'SERVICO', 'descricao' => 'Transferência', 'quantidade' => 1, 'venda' => 495.91, 'componentes' => [
    ['categoria' => 'REPASSE', 'descricao' => 'Detran', 'valor' => 295.91, 'prazo' => 'IMEDIATO', 'visivel_cliente' => true],
    ['categoria' => 'REPASSE', 'descricao' => 'SERPRO', 'valor' => 8.61, 'prazo' => 'MENSAL', 'visivel_cliente' => false],
]];
$l = Decomposicao::linha($transf);
ok('o cliente vê Detran 295,91 e Honorário 200,00', $l['para_o_cliente'] === [
    ['descricao' => 'Detran', 'valor' => 295.91, 'repasse' => true],
    ['descricao' => 'Honorário', 'valor' => 200.0, 'repasse' => false],
]);
ok('fica para o escritório 191,39', $l['honorario'] === 191.39);
$o = Decomposicao::orcamento([
    $transf,
    ['natureza' => 'DEBITO', 'descricao' => 'IPVA', 'quantidade' => 1, 'venda' => 500],
    ['natureza' => 'DEBITO', 'descricao' => 'Multas', 'quantidade' => 1, 'venda' => 1000],
]);
ok('total 1.995,91', $o['venda'] === 1995.91);
ok('repasses imediatos 1.795,91 e mensais 8,61', $o['repasses_imediatos'] === 1795.91 && $o['repasses_mensais'] === 8.61);
ok('IPVA e multas não viram honorário', $o['honorario'] === 191.39);
ok('o cliente lê taxas e débitos primeiro, honorário no fim', array_column($o['para_o_cliente'], 'descricao') === ['Detran', 'IPVA', 'Multas', 'Honorário']);
$sem = Decomposicao::linha(['natureza' => 'SERVICO', 'descricao' => 'Vistoria', 'quantidade' => 1, 'venda' => 150, 'componentes' => []]);
ok('serviço sem taxa: o cliente vê a linha inteira, e ela é honorário', $sem['para_o_cliente'][0]['descricao'] === 'Vistoria' && $sem['honorario'] === 150.0);
$dois = Decomposicao::linha(['quantidade' => 2, 'venda' => 991.82] + $transf);
ok('quantidade multiplica as taxas', $dois['repasses'] === 609.04 && $dois['honorario'] === 382.78);
ok('dinheiro aceita "1.234,56"', Catalogo::dinheiro('1.234,56') === 1234.56 && Catalogo::dinheiro('495.91') === 495.91);

$pdo = null;
try {
    $pdo = Database::getConnection();
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $temTabela = (bool) $pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_orcamentos'")->fetchColumn();
    $conta = $pdo->query("SELECT a.id FROM accounts a WHERE a.deleted_at IS NULL
        AND EXISTS (SELECT 1 FROM users u WHERE u.account_id = a.id AND u.status = 'active' AND u.deleted_at IS NULL)
        AND EXISTS (SELECT 1 FROM pipeline_columns c WHERE c.account_id = a.id) ORDER BY a.id LIMIT 1")->fetchColumn();
    $outra = $pdo->query('SELECT id FROM accounts WHERE deleted_at IS NULL AND id <> ' . (int) $conta . ' ORDER BY id LIMIT 1')->fetchColumn();
} catch (\Throwable $e) {
    echo '  [pulado] sem banco: ' . $e->getMessage() . "\n";
    $temTabela = false; $conta = null;
}

if (!$pdo || !$temTabela || !$conta) {
    echo "\n  [pulado] precisa do banco, da migration 143 e de uma conta com usuário e funil\n";
} else {
    $acc = (int) $conta;
    $outra = (int) $outra;
    $eu = (int) $pdo->query("SELECT id FROM users WHERE account_id = $acc AND status = 'active' AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
    $marca = 'ZZTESTE_ORC_' . getmypid();
    $col = (int) $pdo->query("SELECT id FROM pipeline_columns WHERE account_id = $acc ORDER BY ordem, id LIMIT 1")->fetchColumn();
    $servicos = [];
    $orcs = [];
    try {
        echo "\n== 2. Catálogo ==\n";
        Catalogo::listar($acc);
        $sid = Catalogo::salvar($acc, ['nome' => $marca . ' Transferência', 'valor_padrao' => '495,91']);
        $servicos[] = $sid;
        Catalogo::adicionarComponente($acc, $sid, ['categoria' => 'REPASSE', 'descricao' => 'Detran', 'valor' => '295,91', 'prazo' => 'IMEDIATO', 'visivel_cliente' => true]);
        $serproId = Catalogo::adicionarComponente($acc, $sid, ['categoria' => 'REPASSE', 'descricao' => 'SERPRO', 'valor' => 8.61, 'prazo' => 'IMEDIATO', 'visivel_cliente' => true]);
        ok('ajustar a SERPRO para mensal e dentro do honorário', Catalogo::ajustarComponente($acc, $serproId, ['prazo' => 'MENSAL', 'visivel_cliente' => false]));
        $s = array_values(array_filter(Catalogo::listar($acc), fn($x) => $x['id'] === $sid))[0] ?? null;
        ok('o catálogo diz o que o cliente vê e quanto fica', $s && $s['honorario'] === 191.39 && count($s['para_o_cliente']) === 2);
        ok('outra conta não ajusta a taxa desta', $outra === 0 || !Catalogo::ajustarComponente($outra, $serproId, ['prazo' => 'IMEDIATO']));
        ok('nome repetido é recusado', recusa(fn() => Catalogo::salvar($acc, ['nome' => $marca . ' Transferência'])) !== null);

        echo "\n== 3. Orçamento ligado ao lead ==\n";
        $pdo->prepare('INSERT INTO cards (account_id, coluna_id, titulo, cliente_nome, telefone_whatsapp, responsavel_user_id, created_at) VALUES (?,?,?,?,?,?, NOW())')
            ->execute([$acc, $col, $marca, $marca . ' Cliente', '11999990000', $eu]);
        $card = (int) $pdo->lastInsertId();
        $dados = ['card_id' => $card, 'placa' => 'fyn-2d30', 'itens' => [
            ['natureza' => 'SERVICO', 'servico_id' => $sid, 'descricao' => 'Transferência de propriedade', 'valor_unitario' => '495,91'],
            ['natureza' => 'DEBITO', 'servico_id' => $sid, 'descricao' => 'IPVA', 'valor_unitario' => 500],
            ['natureza' => 'DEBITO', 'descricao' => 'Multas', 'valor_unitario' => 1000],
        ]];
        $id = Orcamento::salvar($acc, $dados, $eu);
        $orcs[] = $id;
        $f = Orcamento::ficha($acc, $id);
        ok('nasce em rascunho, com o nome e o WhatsApp do lead', $f['status'] === 'RASCUNHO' && $f['cliente_nome'] === $marca . ' Cliente' && $f['cliente_telefone'] === '11999990000');
        ok('placa normalizada', $f['placa'] === 'FYN2D30');
        ok('débito não carrega serviço do catálogo', $f['itens'][1]['servico_id'] === null);
        ok('total e as duas visões', $f['total'] === 1995.91 && $f['interno']['honorario'] === 191.39 && $f['interno']['repasses_mensais'] === 8.61);
        ok('o cliente lê Detran, IPVA, Multas e Honorário 200', array_column($f['para_o_cliente'], 'valor') === [295.91, 500.0, 1000.0, 200.0]);
        $txt = Orcamento::textoParaOCliente($f, 'Escritório');
        ok('o texto do WhatsApp é a visão do cliente (sem SERPRO)', str_contains($txt, 'Honorário: R$ 200,00') && !str_contains($txt, 'SERPRO'));
        ok('outra conta não abre o orçamento', $outra === 0 || Orcamento::ficha($outra, $id) === null);
        ok('lead de outra conta é recusado', $outra === 0 || recusa(fn() => Orcamento::salvar($outra, $dados, $eu)) !== null);
        ok('sem item é recusado', recusa(fn() => Orcamento::salvar($acc, ['cliente_nome' => 'X', 'itens' => []], $eu)) !== null);

        echo "\n== 4. Situações ==\n";
        Orcamento::aprovar($acc, $id, $eu);
        $vp = (float) $pdo->query("SELECT valor_proposta FROM cards WHERE id = $card")->fetchColumn();
        ok('aprovar grava o valor da proposta no lead', $vp === 1995.91);
        ok('aprovar de novo é recusado', recusa(fn() => Orcamento::aprovar($acc, $id, $eu)) !== null);
        $id2 = Orcamento::salvar($acc, $dados, $eu, $id);
        ok('editar devolve para rascunho', $id2 === $id && Orcamento::ficha($acc, $id)['status'] === 'RASCUNHO');
        Orcamento::aprovar($acc, $id, $eu);
        Orcamento::converter($acc, $id, $eu);
        $vf = $pdo->query("SELECT valor_fechado_final, data_fechamento FROM cards WHERE id = $card")->fetch(\PDO::FETCH_ASSOC);
        ok('fechar o negócio grava valor fechado e data no lead', (float) $vf['valor_fechado_final'] === 1995.91 && $vf['data_fechamento'] !== null);
        ok('negócio fechado não se edita', recusa(fn() => Orcamento::salvar($acc, $dados, $eu, $id)) !== null);
        ok('negócio fechado não se rejeita', recusa(fn() => Orcamento::rejeitar($acc, $id)) !== null);
        $notas = (int) $pdo->query("SELECT COUNT(*) FROM crm_interacoes WHERE entidade = 'card' AND entidade_id = $card")->fetchColumn();
        ok('o histórico do lead registra aprovação e fechamento', $notas >= 2);

        $rej = Orcamento::salvar($acc, ['cliente_nome' => $marca . ' Avulso', 'itens' => [['descricao' => 'Vistoria', 'valor_unitario' => 150]]], $eu);
        $orcs[] = $rej;
        Orcamento::rejeitar($acc, $rej);
        ok('rejeitado sai dos ativos', !in_array($rej, array_column(Orcamento::listar($acc), 'id'), true));
        ok('e fica na aba Rejeitado', in_array($rej, array_column(Orcamento::listar($acc, 'REJEITADO'), 'id'), true));
        Orcamento::reabrir($acc, $rej);
        ok('reabrir devolve para rascunho', Orcamento::ficha($acc, $rej)['status'] === 'RASCUNHO');
        ok('o lead lista os orçamentos dele', in_array($id, array_column(Orcamento::listar($acc, null, $card), 'id'), true));
        $n1 = Orcamento::ficha($acc, $id)['numero'];
        $n2 = Orcamento::ficha($acc, $rej)['numero'];
        ok('numeração seguida por conta', $n2 === $n1 + 1);
    } catch (\Throwable $e) {
        ok('sem exceção: ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(), false);
    } finally {
        if ($orcs) {
            $in = implode(',', array_map('intval', $orcs));
            $pdo->exec("DELETE FROM crm_orcamento_itens WHERE orcamento_id IN ($in)");
            $pdo->exec("DELETE FROM crm_orcamentos WHERE id IN ($in)");
        }
        if ($servicos) {
            $in = implode(',', array_map('intval', $servicos));
            $pdo->exec("DELETE FROM crm_servico_componentes WHERE servico_id IN ($in)");
            $pdo->exec("DELETE FROM crm_servicos WHERE id IN ($in)");
        }
        $cards = $pdo->query("SELECT id FROM cards WHERE account_id = $acc AND titulo = " . $pdo->quote($marca))->fetchAll(\PDO::FETCH_COLUMN);
        if ($cards) {
            $in = implode(',', array_map('intval', $cards));
            $pdo->exec("DELETE FROM crm_interacoes WHERE entidade = 'card' AND entidade_id IN ($in)");
            $pdo->exec("DELETE FROM account_notifications WHERE entidade = 'card' AND entidade_id IN ($in)");
            // card_history é imutável (LGPD): o lead de teste sai por exclusão lógica.
            $pdo->exec("UPDATE cards SET deleted_at = NOW() WHERE id IN ($in)");
        }
        $resto = (int) $pdo->query("SELECT COUNT(*) FROM crm_orcamentos WHERE cliente_nome LIKE " . $pdo->quote($marca . '%'))->fetchColumn();
        ok('limpeza: nada do teste ficou no banco', $resto === 0);
    }
}

echo "\nResultado: $OK ok · " . count($FALHAS) . " falha(s)\n";
if ($FALHAS) exit(1);
