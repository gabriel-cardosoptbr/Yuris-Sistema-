<?php
namespace App\Clientes;

use App\Core\Database;

/**
 * O card do cliente no quadro de Clientes da edição CRM (20/10/2026): tudo o que
 * ele mostra, de uma vez para o quadro inteiro, sem uma consulta por card.
 *
 *   cadastro     nome, tipo, documento, contato, cidade, situação, responsável,
 *                cliente desde quando
 *   setores      os setores do cliente (SetoresDoCliente), mais de um
 *   dinheiro     total por mês, serviços e taxas, o que está em atraso, a próxima
 *                cobrança e o total já recebido (CobrancaDoCliente)
 *   contratos    quantos, assinados e aguardando assinatura (app/Contratos)
 *   contato      o último contato registrado na ficha (crm_interacoes)
 *
 * A tela é public/assets/ff-cliente-crm.js, que redesenha o card do renderCard
 * de clientes.php. Toda consulta filtra pelas contas que a sessão vê.
 */
final class CardDoCliente
{
    /**
     * @param int[] $accountIds contas que a sessão vê no quadro
     * @return array{clientes: array<int, array>, setores: array}
     */
    public static function quadro(array $accountIds, int $contaAtual): array
    {
        $accountIds = array_values(array_filter(array_map('intval', $accountIds)));
        if (!$accountIds) return ['clientes' => [], 'setores' => []];
        $in = implode(',', array_fill(0, count($accountIds), '?'));
        $pdo = Database::getConnection();
        $hoje = CobrancaDoCliente::hoje();
        $base = SetoresDoCliente::quadro($accountIds);
        $out = [];

        $st = $pdo->prepare("SELECT c.id, c.account_id, c.nome, c.tipo_cliente, c.cpf_cnpj, c.whatsapp, c.telefone, c.email, c.cidade, c.uf,
                                    c.status, c.created_at, c.convertido_em, u.nome AS responsavel
                               FROM clientes c LEFT JOIN users u ON u.id = c.responsavel_id
                              WHERE c.account_id IN ($in) AND c.deleted_at IS NULL");
        $st->execute($accountIds);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $c) {
            $id = (int) $c['id'];
            $desde = substr((string) ($c['convertido_em'] ?: $c['created_at']), 0, 10);
            $out[$id] = [
                'nome' => $c['nome'], 'tipo' => $c['tipo_cliente'], 'documento' => $c['cpf_cnpj'],
                'whatsapp' => preg_replace('/\D/', '', (string) ($c['whatsapp'] ?: $c['telefone'])) ?: null,
                'email' => $c['email'] ?: null,
                'cidade' => trim(($c['cidade'] ?: '') . ($c['uf'] ? '/' . $c['uf'] : ''), '/') ?: null,
                'status' => $c['status'] ?: 'ativo', 'responsavel' => $c['responsavel'] ?: null,
                'desde' => $desde ?: null, 'dias_cliente' => $desde ? max(0, (int) ((strtotime($hoje) - strtotime($desde)) / 86400)) : null,
                'setores' => $base[$id]['setores'] ?? [], 'por_mes' => $base[$id]['por_mes'] ?? 0.0, 'avulso' => $base[$id]['avulso'] ?? 0.0,
                'atrasado' => 0.0, 'atrasadas' => 0, 'proxima' => null, 'recebido' => 0.0,
                'contratos' => ['total' => 0, 'assinados' => 0, 'aguardando' => 0],
                'ultimo_contato' => null, 'outra_conta' => (int) $c['account_id'] !== $contaAtual,
            ];
        }
        if (!$out) return ['clientes' => [], 'setores' => SetoresDoCliente::daConta($contaAtual)];

        // Cobranças: atraso, próxima e recebido.
        $cb = $pdo->prepare("SELECT cliente_id, status, vencimento, valor, valor_pago FROM crm_cliente_cobrancas
                              WHERE account_id IN ($in) AND status IN ('aberta', 'paga') ORDER BY vencimento");
        $cb->execute($accountIds);
        foreach ($cb->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $id = (int) $r['cliente_id'];
            if (!isset($out[$id])) continue;
            if ($r['status'] === 'paga') { $out[$id]['recebido'] += (float) ($r['valor_pago'] ?? $r['valor']); continue; }
            if ($r['vencimento'] < $hoje) { $out[$id]['atrasado'] += (float) $r['valor']; $out[$id]['atrasadas']++; continue; }
            $p = &$out[$id]['proxima'];
            if ($p === null) $p = ['vencimento' => $r['vencimento'], 'valor' => (float) $r['valor']];
            elseif ($p['vencimento'] === $r['vencimento']) $p['valor'] += (float) $r['valor'];
            unset($p);
        }

        // Contratos.
        try {
            $ct = $pdo->prepare("SELECT cliente_id, status, COUNT(*) AS n FROM crm_contratos
                                  WHERE account_id IN ($in) AND cliente_id IS NOT NULL AND status NOT IN ('cancelado') GROUP BY cliente_id, status");
            $ct->execute($accountIds);
            foreach ($ct->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $id = (int) $r['cliente_id'];
                if (!isset($out[$id])) continue;
                $out[$id]['contratos']['total'] += (int) $r['n'];
                if ($r['status'] === 'concluido') $out[$id]['contratos']['assinados'] += (int) $r['n'];
                if (in_array($r['status'], ['aguardando', 'parcial'], true)) $out[$id]['contratos']['aguardando'] += (int) $r['n'];
            }
        } catch (\Throwable $e) { /* sem o módulo de contratos */ }

        // Último contato registrado na ficha.
        try {
            $it = $pdo->prepare("SELECT entidade_id, MAX(ocorrido_em) AS quando FROM crm_interacoes
                                  WHERE account_id IN ($in) AND entidade = 'cliente' AND deleted_at IS NULL GROUP BY entidade_id");
            $it->execute($accountIds);
            foreach ($it->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $id = (int) $r['entidade_id'];
                if (isset($out[$id]) && $r['quando']) $out[$id]['ultimo_contato'] = $r['quando'];
            }
        } catch (\Throwable $e) { /* sem interações */ }

        foreach ($out as &$c) { $c['atrasado'] = round($c['atrasado'], 2); $c['recebido'] = round($c['recebido'], 2); }
        unset($c);
        return ['clientes' => $out, 'setores' => SetoresDoCliente::daConta($contaAtual)];
    }
}
