<?php
namespace App\Orcamentos;

use App\Core\Database;

/**
 * O catálogo de serviços do orçamento, por conta: nome, preço de tabela e as
 * taxas de cada um (REPASSE ao órgão, com prazo e visibilidade, ou CUSTO).
 *
 * Toda leitura e escrita filtra `account_id`: o id de um serviço vindo da tela
 * nunca basta sozinho.
 */
final class Catalogo
{
    /** Os nomes com que a conta começa, sem preço: o preço é do escritório. */
    public const INICIAIS = [
        'Transferência de propriedade', 'Licenciamento', 'Primeiro emplacamento', 'Segunda via de documento',
        'Baixa de gravame', 'Regularização de débitos', 'Vistoria', 'Outro',
    ];

    /**
     * Lista com as taxas. Conta sem serviço nenhum ganha a lista inicial, mas só no
     * modo veículo (Orcamento::modoVeiculo): a lista é de despachante, e a marca de
     * outro ramo (Inovaize, Seller Mentoria) começa vazia e cadastra os próprios
     * serviços (20/10/2026; antes a Inovaize ganhou "Transferência", "Licenciamento"...).
     */
    public static function listar(int $accountId, bool $soAtivos = false): array
    {
        $pdo = Database::getConnection();
        $existe = $pdo->prepare('SELECT COUNT(*) FROM crm_servicos WHERE account_id = ?');
        $existe->execute([$accountId]);
        if ((int) $existe->fetchColumn() === 0 && Orcamento::modoVeiculo(\App\Master\Account::findById($accountId) ?? [])) {
            $ins = $pdo->prepare('INSERT IGNORE INTO crm_servicos (account_id, nome, ordem) VALUES (?,?,?)');
            foreach (self::INICIAIS as $i => $nome) $ins->execute([$accountId, $nome, $i]);
        }

        $st = $pdo->prepare('SELECT id, nome, valor_padrao, ativo, ordem FROM crm_servicos
                              WHERE account_id = ?' . ($soAtivos ? ' AND ativo = 1' : '') . ' ORDER BY ordem, nome');
        $st->execute([$accountId]);
        $servicos = $st->fetchAll(\PDO::FETCH_ASSOC);

        $c = $pdo->prepare('SELECT id, servico_id, categoria, descricao, valor, prazo, visivel_cliente FROM crm_servico_componentes
                             WHERE account_id = ? ORDER BY ordem, id');
        $c->execute([$accountId]);
        $porServico = [];
        foreach ($c->fetchAll(\PDO::FETCH_ASSOC) as $comp) {
            $porServico[(int) $comp['servico_id']][] = [
                'id' => (int) $comp['id'], 'categoria' => $comp['categoria'], 'descricao' => $comp['descricao'],
                'valor' => (float) $comp['valor'], 'prazo' => $comp['prazo'], 'visivel_cliente' => (bool) $comp['visivel_cliente'],
            ];
        }

        return array_map(function ($s) use ($porServico) {
            $comp = $porServico[(int) $s['id']] ?? [];
            $valor = $s['valor_padrao'] === null ? null : (float) $s['valor_padrao'];
            $resumo = $valor === null ? null : Decomposicao::linha([
                'natureza' => 'SERVICO', 'descricao' => $s['nome'], 'quantidade' => 1, 'venda' => $valor, 'componentes' => $comp,
            ]);
            return [
                'id'             => (int) $s['id'],
                'nome'           => $s['nome'],
                'valor_padrao'   => $valor,
                'ativo'          => (bool) $s['ativo'],
                'componentes'    => $comp,
                // Como o cliente lê o serviço pelo preço de tabela, e quanto sobra.
                'para_o_cliente' => $resumo['para_o_cliente'] ?? [],
                'honorario'      => $resumo['honorario'] ?? null,
            ];
        }, $servicos);
    }

    /** Os componentes de vários serviços da conta, por id do serviço. */
    public static function componentesDe(int $accountId, array $servicoIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $servicoIds))));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::getConnection()->prepare(
            "SELECT servico_id, categoria, descricao, valor, prazo, visivel_cliente FROM crm_servico_componentes
              WHERE account_id = ? AND servico_id IN ($in) ORDER BY ordem, id"
        );
        $st->execute(array_merge([$accountId], $ids));
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $c) {
            $out[(int) $c['servico_id']][] = [
                'categoria' => $c['categoria'], 'descricao' => $c['descricao'], 'valor' => (float) $c['valor'],
                'prazo' => $c['prazo'], 'visivel_cliente' => (bool) $c['visivel_cliente'],
            ];
        }
        return $out;
    }

    public static function existe(int $accountId, int $servicoId): bool
    {
        $st = Database::getConnection()->prepare('SELECT 1 FROM crm_servicos WHERE id = ? AND account_id = ?');
        $st->execute([$servicoId, $accountId]);
        return (bool) $st->fetchColumn();
    }

    /** Cria ou altera um serviço (nome, preço de tabela, ativo). */
    public static function salvar(int $accountId, array $d): int
    {
        $nome = trim((string) ($d['nome'] ?? ''));
        if ($nome === '' || mb_strlen($nome) > 120) throw new \InvalidArgumentException('Dê um nome ao serviço (até 120 caracteres).');
        $valor = isset($d['valor_padrao']) && $d['valor_padrao'] !== '' && $d['valor_padrao'] !== null ? self::dinheiro($d['valor_padrao']) : null;
        $ativo = array_key_exists('ativo', $d) ? (int) (bool) $d['ativo'] : 1;
        $pdo = Database::getConnection();
        $id = (int) ($d['id'] ?? 0);

        $dup = $pdo->prepare('SELECT id FROM crm_servicos WHERE account_id = ? AND nome = ? AND id <> ?');
        $dup->execute([$accountId, $nome, $id]);
        if ($dup->fetchColumn()) throw new \InvalidArgumentException('Já existe um serviço com esse nome.');

        if ($id) {
            if (!self::existe($accountId, $id)) throw new \InvalidArgumentException('Serviço não encontrado.');
            $pdo->prepare('UPDATE crm_servicos SET nome = ?, valor_padrao = ?, ativo = ? WHERE id = ? AND account_id = ?')
                ->execute([$nome, $valor, $ativo, $id, $accountId]);
            return $id;
        }
        $ordem = $pdo->prepare('SELECT COALESCE(MAX(ordem), 0) + 1 FROM crm_servicos WHERE account_id = ?');
        $ordem->execute([$accountId]);
        $pdo->prepare('INSERT INTO crm_servicos (account_id, nome, valor_padrao, ativo, ordem) VALUES (?,?,?,?,?)')
            ->execute([$accountId, $nome, $valor, $ativo, (int) $ordem->fetchColumn()]);
        return (int) $pdo->lastInsertId();
    }

    /** Acrescenta uma taxa ao serviço. */
    public static function adicionarComponente(int $accountId, int $servicoId, array $d): int
    {
        if (!self::existe($accountId, $servicoId)) throw new \InvalidArgumentException('Serviço não encontrado.');
        $categoria = ($d['categoria'] ?? 'REPASSE') === 'CUSTO' ? 'CUSTO' : 'REPASSE';
        $descricao = trim((string) ($d['descricao'] ?? ''));
        if ($descricao === '' || mb_strlen($descricao) > 120) throw new \InvalidArgumentException('Descreva a taxa (até 120 caracteres).');
        $valor = self::dinheiro($d['valor'] ?? 0);
        if ($valor <= 0) throw new \InvalidArgumentException('O valor da taxa precisa ser maior que zero.');
        $prazo = $categoria === 'REPASSE' && ($d['prazo'] ?? '') === 'MENSAL' ? 'MENSAL' : 'IMEDIATO';
        $visivel = $categoria === 'REPASSE' ? (int) (bool) ($d['visivel_cliente'] ?? true) : 0;

        $pdo = Database::getConnection();
        $pdo->prepare('INSERT INTO crm_servico_componentes (account_id, servico_id, categoria, descricao, valor, prazo, visivel_cliente)
                       VALUES (?,?,?,?,?,?,?)')
            ->execute([$accountId, $servicoId, $categoria, $descricao, $valor, $prazo, $visivel]);
        return (int) $pdo->lastInsertId();
    }

    /** Ajusta o prazo e a visibilidade de uma taxa que já existe. */
    public static function ajustarComponente(int $accountId, int $componenteId, array $d): bool
    {
        $campos = []; $params = [];
        if (isset($d['prazo'])) { $campos[] = 'prazo = ?'; $params[] = $d['prazo'] === 'MENSAL' ? 'MENSAL' : 'IMEDIATO'; }
        if (array_key_exists('visivel_cliente', $d)) { $campos[] = 'visivel_cliente = ?'; $params[] = (int) (bool) $d['visivel_cliente']; }
        if (!$campos) return false;
        $params[] = $componenteId; $params[] = $accountId;
        $st = Database::getConnection()->prepare(
            'UPDATE crm_servico_componentes SET ' . implode(', ', $campos) . " WHERE id = ? AND account_id = ? AND categoria = 'REPASSE'"
        );
        $st->execute($params);
        return $st->rowCount() > 0;
    }

    public static function removerComponente(int $accountId, int $componenteId): bool
    {
        $st = Database::getConnection()->prepare('DELETE FROM crm_servico_componentes WHERE id = ? AND account_id = ?');
        $st->execute([$componenteId, $accountId]);
        return $st->rowCount() > 0;
    }

    /** "1.234,56", "1234.56" ou número → float com duas casas. */
    public static function dinheiro(mixed $v): float
    {
        if (is_int($v) || is_float($v)) return round((float) $v, 2);
        $s = trim((string) $v);
        if (str_contains($s, ',')) $s = str_replace(['.', ','], ['', '.'], $s);
        $n = is_numeric($s) ? (float) $s : 0.0;
        if ($n < 0 || $n > 999999.99) throw new \InvalidArgumentException('Valor fora do limite.');
        return round($n, 2);
    }
}
