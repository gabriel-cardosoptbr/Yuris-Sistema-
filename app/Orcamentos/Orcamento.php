<?php
namespace App\Orcamentos;

use App\Core\Database;
use App\Crm\Interacao;
use App\Prospeccao\Card;

/**
 * O orçamento do CRM (edição Fleetiflow): o mesmo da plataforma, para o lead.
 *
 *   RASCUNHO → APROVADO → CONVERTIDO (negócio fechado)
 *        ↘ REJEITADO → (reabrir) → RASCUNHO
 *
 * - Itens: SERVICO (do catálogo da conta, ou livre) e DEBITO do veículo (IPVA,
 *   multas...), que é repasse integral. A conta é `Decomposicao`.
 * - Rejeitado sai da lista dos ativos ("selecionei rejeitar e ele não some").
 * - Editar volta para RASCUNHO: o que o cliente aprovou deixou de ser o que está
 *   escrito. Convertido não muda mais.
 * - Ligado a um lead: aprovar grava o total em "valor da proposta" do card, e
 *   fechar o negócio grava em "valor fechado" com a data de hoje. O histórico do
 *   lead registra os dois.
 *
 * Toda consulta filtra `account_id`.
 */
final class Orcamento
{
    public const STATUS = ['RASCUNHO', 'APROVADO', 'REJEITADO', 'CONVERTIDO'];
    public const DEBITOS = ['IPVA', 'Multas', 'Dívida ativa', 'Licenciamento', 'Taxas diversas'];
    private const MAX_ITENS = 30;

    public static function listar(int $accountId, ?string $status = null, ?int $cardId = null): array
    {
        $where = ['o.account_id = ?']; $params = [$accountId];
        if ($status !== null && in_array($status, self::STATUS, true)) { $where[] = 'o.status = ?'; $params[] = $status; }
        elseif ($cardId === null) { $where[] = "o.status <> 'REJEITADO'"; }
        if ($cardId !== null) { $where[] = 'o.card_id = ?'; $params[] = $cardId; }

        $st = Database::getConnection()->prepare(
            'SELECT o.id, o.numero, o.card_id, o.cliente_nome, o.placa, o.status, o.created_at, o.aprovado_em, o.convertido_em,
                    u.nome AS vendedor_nome,
                    (SELECT COUNT(*) FROM crm_orcamento_itens i WHERE i.orcamento_id = o.id AND i.account_id = o.account_id) AS itens,
                    (SELECT COALESCE(SUM(ROUND(i.quantidade * i.valor_unitario - i.desconto, 2)), 0)
                       FROM crm_orcamento_itens i WHERE i.orcamento_id = o.id AND i.account_id = o.account_id) AS total
               FROM crm_orcamentos o
               LEFT JOIN users u ON u.id = o.vendedor_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY o.created_at DESC, o.id DESC
              LIMIT 500'
        );
        $st->execute($params);
        return array_map(fn($r) => [
            'id' => (int) $r['id'], 'numero' => (int) $r['numero'], 'card_id' => $r['card_id'] ? (int) $r['card_id'] : null,
            'cliente_nome' => $r['cliente_nome'], 'placa' => $r['placa'], 'status' => $r['status'],
            'vendedor_nome' => $r['vendedor_nome'], 'itens' => (int) $r['itens'], 'total' => (float) $r['total'],
            'created_at' => $r['created_at'], 'aprovado_em' => $r['aprovado_em'], 'convertido_em' => $r['convertido_em'],
        ], $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** O orçamento com os itens e as duas visões (cliente e escritório). */
    public static function ficha(int $accountId, int $id): ?array
    {
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT o.*, u.nome AS vendedor_nome FROM crm_orcamentos o
                               LEFT JOIN users u ON u.id = o.vendedor_id
                              WHERE o.id = ? AND o.account_id = ?');
        $st->execute([$id, $accountId]);
        $o = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$o) return null;

        $it = $pdo->prepare('SELECT id, natureza, servico_id, descricao, quantidade, valor_unitario, desconto
                               FROM crm_orcamento_itens WHERE orcamento_id = ? AND account_id = ? ORDER BY ordem, id');
        $it->execute([$id, $accountId]);
        $itens = array_map(fn($i) => [
            'id' => (int) $i['id'], 'natureza' => $i['natureza'], 'servico_id' => $i['servico_id'] ? (int) $i['servico_id'] : null,
            'descricao' => $i['descricao'], 'quantidade' => (float) $i['quantidade'], 'valor_unitario' => (float) $i['valor_unitario'],
            'desconto' => (float) $i['desconto'],
            'subtotal' => Decomposicao::r((float) $i['quantidade'] * (float) $i['valor_unitario'] - (float) $i['desconto']),
        ], $it->fetchAll(\PDO::FETCH_ASSOC));

        $venda = self::decompor($accountId, $itens);

        return [
            'id'                => (int) $o['id'],
            'numero'            => (int) $o['numero'],
            'status'            => $o['status'],
            'card_id'           => $o['card_id'] ? (int) $o['card_id'] : null,
            'cliente_id'        => $o['cliente_id'] ? (int) $o['cliente_id'] : null,
            'cliente_nome'      => $o['cliente_nome'],
            'cliente_documento' => $o['cliente_documento'],
            'cliente_telefone'  => $o['cliente_telefone'],
            'placa'             => $o['placa'],
            'veiculo'           => $o['veiculo'],
            'vendedor_id'       => $o['vendedor_id'] ? (int) $o['vendedor_id'] : null,
            'vendedor_nome'     => $o['vendedor_nome'],
            'canal'             => $o['canal'],
            'observacoes'       => $o['observacoes'],
            'created_at'        => $o['created_at'],
            'aprovado_em'       => $o['aprovado_em'],
            'convertido_em'     => $o['convertido_em'],
            'itens'             => $itens,
            'total'             => $venda['venda'],
            'para_o_cliente'    => $venda['para_o_cliente'],
            'interno'           => [
                'valor_recebido'     => $venda['venda'],
                'repasses_imediatos' => $venda['repasses_imediatos'],
                'repasses_mensais'   => $venda['repasses_mensais'],
                'custos'             => $venda['custos'],
                'honorario'          => $venda['honorario'],
                'saidas'             => array_merge(...array_map(fn($l) => $l['saidas'], $venda['linhas'] ?: [['saidas' => []]])),
                // Serviço sem taxa nenhuma no catálogo: o valor inteiro conta como honorário.
                'sem_taxas'          => array_values(array_map(fn($l) => $l['linha']['descricao'], array_filter(
                    $venda['linhas'],
                    fn($l) => $l['linha']['natureza'] === 'SERVICO' && !$l['saidas']
                ))),
            ],
        ];
    }

    /**
     * Cria (sem `$id`) ou corrige um orçamento.
     *
     * @return int o id
     * @throws \InvalidArgumentException com a mensagem para a tela
     */
    public static function salvar(int $accountId, array $d, int $userId, ?int $id = null): int
    {
        $pdo = Database::getConnection();
        if ($id) {
            $atual = self::situacao($accountId, $id);
            if ($atual === null) throw new \InvalidArgumentException('Orçamento não encontrado.');
            if ($atual === 'CONVERTIDO') throw new \InvalidArgumentException('Orçamento de negócio fechado não pode ser alterado.');
        }

        // O lead e o cliente, sempre desta conta.
        $cardId = (int) ($d['card_id'] ?? 0) ?: null;
        $card = null;
        if ($cardId) {
            $c = $pdo->prepare('SELECT id, cliente_nome, empresa_nome, cpf_cnpj, telefone_whatsapp FROM cards
                                 WHERE id = ? AND account_id = ? AND deleted_at IS NULL');
            $c->execute([$cardId, $accountId]);
            $card = $c->fetch(\PDO::FETCH_ASSOC) ?: null;
            if (!$card) throw new \InvalidArgumentException('Lead não encontrado.');
        }
        $clienteId = (int) ($d['cliente_id'] ?? 0) ?: null;
        if ($clienteId) {
            $c = $pdo->prepare('SELECT 1 FROM clientes WHERE id = ? AND account_id = ? AND deleted_at IS NULL');
            $c->execute([$clienteId, $accountId]);
            if (!$c->fetchColumn()) throw new \InvalidArgumentException('Cliente não encontrado.');
        }
        $nome = trim((string) ($d['cliente_nome'] ?? '')) ?: trim((string) ($card['cliente_nome'] ?? '')) ?: trim((string) ($card['empresa_nome'] ?? ''));
        if ($nome === '') throw new \InvalidArgumentException('Informe o nome do cliente.');
        $documento = trim((string) ($d['cliente_documento'] ?? '')) ?: trim((string) ($card['cpf_cnpj'] ?? ''));
        $telefone = trim((string) ($d['cliente_telefone'] ?? '')) ?: trim((string) ($card['telefone_whatsapp'] ?? ''));

        $vendedor = (int) ($d['vendedor_id'] ?? 0) ?: null;
        if ($vendedor) {
            $v = $pdo->prepare('SELECT 1 FROM users WHERE id = ? AND account_id = ? AND deleted_at IS NULL');
            $v->execute([$vendedor, $accountId]);
            if (!$v->fetchColumn()) throw new \InvalidArgumentException('Vendedor não encontrado nesta conta.');
        }
        $placa = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($d['placa'] ?? '')));
        if (strlen($placa) > 12) throw new \InvalidArgumentException('Placa inválida.');

        $itens = self::itensValidos($accountId, $d['itens'] ?? []);

        $campos = [
            'card_id' => $cardId, 'cliente_id' => $clienteId, 'cliente_nome' => mb_substr($nome, 0, 190),
            'cliente_documento' => $documento !== '' ? mb_substr($documento, 0, 30) : null,
            'cliente_telefone' => $telefone !== '' ? mb_substr($telefone, 0, 50) : null,
            'placa' => $placa !== '' ? $placa : null,
            'veiculo' => trim((string) ($d['veiculo'] ?? '')) !== '' ? mb_substr(trim((string) $d['veiculo']), 0, 120) : null,
            'vendedor_id' => $vendedor,
            'canal' => trim((string) ($d['canal'] ?? '')) !== '' ? mb_substr(trim((string) $d['canal']), 0, 60) : null,
            'observacoes' => trim((string) ($d['observacoes'] ?? '')) !== '' ? mb_substr(trim((string) $d['observacoes']), 0, 4000) : null,
        ];

        $pdo->beginTransaction();
        try {
            if ($id) {
                $sets = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($campos)));
                $pdo->prepare("UPDATE crm_orcamentos SET $sets, status = 'RASCUNHO', aprovado_em = NULL WHERE id = :id AND account_id = :acc")
                    ->execute($campos + ['id' => $id, 'acc' => $accountId]);
                $pdo->prepare('DELETE FROM crm_orcamento_itens WHERE orcamento_id = ? AND account_id = ?')->execute([$id, $accountId]);
            } else {
                $n = $pdo->prepare('SELECT COALESCE(MAX(numero), 0) + 1 FROM crm_orcamentos WHERE account_id = ? FOR UPDATE');
                $n->execute([$accountId]);
                $cols = implode(', ', array_keys($campos));
                $vals = implode(', ', array_map(fn($k) => ":$k", array_keys($campos)));
                $pdo->prepare("INSERT INTO crm_orcamentos (account_id, numero, $cols, status, created_by)
                               VALUES (:acc, :numero, $vals, 'RASCUNHO', :uid)")
                    ->execute($campos + ['acc' => $accountId, 'numero' => (int) $n->fetchColumn(), 'uid' => $userId]);
                $id = (int) $pdo->lastInsertId();
            }
            $ins = $pdo->prepare('INSERT INTO crm_orcamento_itens (account_id, orcamento_id, natureza, servico_id, descricao, quantidade, valor_unitario, desconto, ordem)
                                  VALUES (?,?,?,?,?,?,?,?,?)');
            foreach ($itens as $i => $item) {
                $ins->execute([$accountId, $id, $item['natureza'], $item['servico_id'], $item['descricao'],
                    $item['quantidade'], $item['valor_unitario'], $item['desconto'], $i]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $id;
    }

    public static function aprovar(int $accountId, int $id, int $userId): void
    {
        self::mudar($accountId, $id, ['RASCUNHO'], 'APROVADO', 'Só um orçamento em rascunho pode ser aprovado.', 'aprovado_em = NOW()');
        $f = self::ficha($accountId, $id);
        if ($f && $f['card_id']) {
            Card::update($f['card_id'], ['valor_proposta' => $f['total'], '_usuario_id' => $userId]);
            self::nota($accountId, $f, 'Orçamento nº ' . $f['numero'] . ' aprovado', $userId);
        }
    }

    /** Negócio fechado: grava o valor fechado e a data no lead. */
    public static function converter(int $accountId, int $id, int $userId): void
    {
        self::mudar($accountId, $id, ['APROVADO'], 'CONVERTIDO', 'Só um orçamento aprovado pode virar negócio fechado.', 'convertido_em = NOW()');
        $f = self::ficha($accountId, $id);
        if ($f && $f['card_id']) {
            Card::update($f['card_id'], ['valor_fechado_final' => $f['total'], 'data_fechamento' => substr(\App\Tarefas\TaskEntrega::agoraLocal(), 0, 10), '_usuario_id' => $userId]);
            self::nota($accountId, $f, 'Negócio fechado pelo orçamento nº ' . $f['numero'], $userId);
        }
    }

    public static function rejeitar(int $accountId, int $id): void
    {
        self::mudar($accountId, $id, ['RASCUNHO', 'APROVADO'], 'REJEITADO', 'Orçamento de negócio fechado não pode ser rejeitado.', 'aprovado_em = NULL');
    }

    public static function reabrir(int $accountId, int $id): void
    {
        self::mudar($accountId, $id, ['REJEITADO'], 'RASCUNHO', 'Só um orçamento rejeitado pode ser reaberto.');
    }

    /** O orçamento como mensagem de WhatsApp para o cliente (a visão do cliente, nunca a interna). */
    public static function textoParaOCliente(array $f, string $nomeDaConta): string
    {
        $real = fn(float $v) => 'R$ ' . number_format($v, 2, ',', '.');
        $linhas = ["*Orçamento nº {$f['numero']}*" . ($nomeDaConta !== '' ? " · {$nomeDaConta}" : '')];
        if ($f['placa']) $linhas[] = 'Veículo: ' . $f['placa'] . ($f['veiculo'] ? ', ' . $f['veiculo'] : '');
        $linhas[] = '';
        foreach ($f['para_o_cliente'] as $l) $linhas[] = $l['descricao'] . ': ' . $real((float) $l['valor']);
        $linhas[] = '';
        $linhas[] = '*Total: ' . $real((float) $f['total']) . '*';
        return implode("\n", $linhas);
    }

    // ── Apoio ────────────────────────────────────────────────────────────────

    /** A decomposição dos itens com as taxas do catálogo da conta. */
    public static function decompor(int $accountId, array $itens): array
    {
        $componentes = Catalogo::componentesDe($accountId, array_column($itens, 'servico_id'));
        return Decomposicao::orcamento(array_map(fn($i) => [
            'id' => $i['id'] ?? null,
            'natureza' => $i['natureza'],
            'descricao' => $i['descricao'],
            'quantidade' => (float) $i['quantidade'],
            'venda' => (float) $i['subtotal'],
            'componentes' => $i['natureza'] === 'SERVICO' && $i['servico_id'] ? ($componentes[$i['servico_id']] ?? []) : [],
        ], $itens));
    }

    private static function itensValidos(int $accountId, mixed $brutos): array
    {
        if (!is_array($brutos) || !$brutos) throw new \InvalidArgumentException('Adicione ao menos um item.');
        if (count($brutos) > self::MAX_ITENS) throw new \InvalidArgumentException('No máximo ' . self::MAX_ITENS . ' itens por orçamento.');
        $out = [];
        foreach ($brutos as $b) {
            $natureza = ($b['natureza'] ?? 'SERVICO') === 'DEBITO' ? 'DEBITO' : 'SERVICO';
            $descricao = trim((string) ($b['descricao'] ?? ''));
            if ($descricao === '' || mb_strlen($descricao) > 200) throw new \InvalidArgumentException('Todo item precisa de uma descrição (até 200 caracteres).');
            $servico = $natureza === 'SERVICO' ? ((int) ($b['servico_id'] ?? 0) ?: null) : null;
            if ($servico && !Catalogo::existe($accountId, $servico)) throw new \InvalidArgumentException('Serviço inválido em algum item.');
            $qtd = Catalogo::dinheiro($b['quantidade'] ?? 1);
            if ($qtd <= 0) $qtd = 1;
            $valor = Catalogo::dinheiro($b['valor_unitario'] ?? 0);
            if ($valor <= 0) throw new \InvalidArgumentException("Valor inválido em \"{$descricao}\".");
            $desconto = Catalogo::dinheiro($b['desconto'] ?? 0);
            $out[] = ['natureza' => $natureza, 'servico_id' => $servico, 'descricao' => $descricao,
                      'quantidade' => $qtd, 'valor_unitario' => $valor, 'desconto' => $desconto];
        }
        return $out;
    }

    private static function situacao(int $accountId, int $id): ?string
    {
        $st = Database::getConnection()->prepare('SELECT status FROM crm_orcamentos WHERE id = ? AND account_id = ?');
        $st->execute([$id, $accountId]);
        $s = $st->fetchColumn();
        return $s === false ? null : (string) $s;
    }

    private static function mudar(int $accountId, int $id, array $de, string $para, string $erro, string $extra = ''): void
    {
        $atual = self::situacao($accountId, $id);
        if ($atual === null) throw new \InvalidArgumentException('Orçamento não encontrado.');
        if (!in_array($atual, $de, true)) throw new \InvalidArgumentException($erro);
        $in = implode(',', array_fill(0, count($de), '?'));
        Database::getConnection()->prepare(
            "UPDATE crm_orcamentos SET status = ?" . ($extra !== '' ? ", $extra" : '') . " WHERE id = ? AND account_id = ? AND status IN ($in)"
        )->execute(array_merge([$para, $id, $accountId], $de));
    }

    /** No histórico do lead, como nota. Falha aqui não desfaz a mudança. */
    private static function nota(int $accountId, array $f, string $assunto, int $userId): void
    {
        try {
            $real = 'R$ ' . number_format((float) $f['total'], 2, ',', '.');
            Interacao::registrar(
                ['entidade' => 'card', 'id' => (int) $f['card_id'], 'account_id' => $accountId, 'titulo' => $f['cliente_nome']],
                ['tipo' => 'nota', 'assunto' => $assunto, 'conteudo' => 'Total ' . $real . ($f['placa'] ? ' · placa ' . $f['placa'] : '')],
                $userId
            );
        } catch (\Throwable $e) {
            error_log('[crm_orcamento] nota no lead: ' . $e->getMessage());
        }
    }
}
