<?php
namespace App\Clientes;

use App\Core\Database;

/**
 * Os valores combinados com o cliente: o "Financeiro" da ficha do cliente
 * (edição CRM, 20/10/2026). Cada linha é um valor, com tipo:
 *
 *   mensal    recorrente todo mês, com dia de vencimento
 *   anual     recorrente uma vez por ano (entra no resumo também dividido por 12)
 *   fixo      pagamento fixo ou único: implantação, taxa de ativação, projeto fechado
 *   demanda   cobrado por uso ou por pedido (só o valor de referência)
 *
 * Início e fim opcionais; linha encerrada fica no histórico com "ativo" desligado
 * em vez de ser apagada (apagar só serve para lançamento errado).
 * Toda consulta filtra account_id, e o cliente tem de ser da conta.
 */
final class ValoresDoCliente
{
    public const TIPOS = ['mensal' => 'Mensal', 'anual' => 'Anual', 'fixo' => 'Fixo / único', 'demanda' => 'Por demanda'];

    /** @return array{itens:array, resumo:array} */
    public static function doCliente(int $accountId, int $clienteId): array
    {
        self::conferirCliente($accountId, $clienteId);
        $st = Database::getConnection()->prepare(
            'SELECT v.*, u.nome AS criado_por_nome FROM crm_cliente_valores v LEFT JOIN users u ON u.id = v.criado_por
              WHERE v.account_id = ? AND v.cliente_id = ? ORDER BY v.ativo DESC, FIELD(v.tipo, "mensal", "anual", "fixo", "demanda"), v.id'
        );
        $st->execute([$accountId, $clienteId]);
        $itens = array_map(static fn($r) => [
            'id' => (int) $r['id'], 'tipo' => $r['tipo'], 'tipo_rotulo' => self::TIPOS[$r['tipo']] ?? $r['tipo'],
            'descricao' => $r['descricao'], 'valor' => (float) $r['valor'],
            'dia_vencimento' => $r['dia_vencimento'] !== null ? (int) $r['dia_vencimento'] : null,
            'inicio' => $r['inicio'], 'fim' => $r['fim'], 'ativo' => (bool) $r['ativo'], 'observacao' => $r['observacao'],
            'criado_por' => $r['criado_por_nome'], 'criado_em' => $r['created_at'],
        ], $st->fetchAll(\PDO::FETCH_ASSOC));
        return ['itens' => $itens, 'resumo' => self::resumo($itens)];
    }

    /** Totais das linhas ativas. "Por mês" soma mensal + anual/12. Pura, para o teste. */
    public static function resumo(array $itens): array
    {
        $t = ['mensal' => 0.0, 'anual' => 0.0, 'fixo' => 0.0, 'demanda' => 0.0];
        $hoje = date('Y-m-d');
        foreach ($itens as $i) {
            if (!$i['ativo'] || (!empty($i['fim']) && $i['fim'] < $hoje)) continue;
            $t[$i['tipo']] = round($t[$i['tipo']] + (float) $i['valor'], 2);
        }
        return $t + ['por_mes' => round($t['mensal'] + $t['anual'] / 12, 2)];
    }

    public static function salvar(int $accountId, int $userId, int $clienteId, array $d): int
    {
        self::conferirCliente($accountId, $clienteId);
        $tipo = (string) ($d['tipo'] ?? '');
        if (!isset(self::TIPOS[$tipo])) throw new \InvalidArgumentException('Escolha o tipo do valor.');
        $desc = trim((string) ($d['descricao'] ?? ''));
        if ($desc === '' || mb_strlen($desc) > 160) throw new \InvalidArgumentException('Descreva o valor (ex.: Mensalidade do sistema).');
        $valor = self::numero($d['valor'] ?? null);
        if ($valor === null || $valor < 0 || $valor > 99999999) throw new \InvalidArgumentException('Informe um valor válido.');
        $dia = ($d['dia_vencimento'] ?? '') === '' || $d['dia_vencimento'] === null ? null : (int) $d['dia_vencimento'];
        if ($dia !== null && ($dia < 1 || $dia > 31)) throw new \InvalidArgumentException('O dia de vencimento vai de 1 a 31.');
        if (!in_array($tipo, ['mensal', 'anual'], true)) $dia = null;
        $inicio = self::data($d['inicio'] ?? null, 'início');
        $fim = self::data($d['fim'] ?? null, 'fim');
        if ($inicio && $fim && $fim < $inicio) throw new \InvalidArgumentException('O fim não pode ser antes do início.');
        $obs = trim((string) ($d['observacao'] ?? ''));
        $ativo = !isset($d['ativo']) || (bool) $d['ativo'];
        $pdo = Database::getConnection();
        $id = (int) ($d['id'] ?? 0);
        if ($id) {
            $st = $pdo->prepare('UPDATE crm_cliente_valores SET tipo = ?, descricao = ?, valor = ?, dia_vencimento = ?, inicio = ?, fim = ?, ativo = ?, observacao = ?
                                  WHERE id = ? AND account_id = ? AND cliente_id = ?');
            $st->execute([$tipo, $desc, $valor, $dia, $inicio, $fim, $ativo ? 1 : 0, $obs ?: null, $id, $accountId, $clienteId]);
            if ($st->rowCount() === 0) {
                $ex = $pdo->prepare('SELECT COUNT(*) FROM crm_cliente_valores WHERE id = ? AND account_id = ? AND cliente_id = ?');
                $ex->execute([$id, $accountId, $clienteId]);
                if (!(int) $ex->fetchColumn()) throw new \InvalidArgumentException('Valor não encontrado.');
            }
            return $id;
        }
        $pdo->prepare('INSERT INTO crm_cliente_valores (account_id, cliente_id, tipo, descricao, valor, dia_vencimento, inicio, fim, ativo, observacao, criado_por)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$accountId, $clienteId, $tipo, $desc, $valor, $dia, $inicio, $fim, $ativo ? 1 : 0, $obs ?: null, $userId ?: null]);
        return (int) $pdo->lastInsertId();
    }

    public static function remover(int $accountId, int $clienteId, int $id): void
    {
        $st = Database::getConnection()->prepare('DELETE FROM crm_cliente_valores WHERE id = ? AND account_id = ? AND cliente_id = ?');
        $st->execute([$id, $accountId, $clienteId]);
        if ($st->rowCount() === 0) throw new \InvalidArgumentException('Valor não encontrado.');
    }

    private static function conferirCliente(int $accountId, int $clienteId): void
    {
        $st = Database::getConnection()->prepare('SELECT id FROM clientes WHERE id = ? AND account_id = ? AND deleted_at IS NULL');
        $st->execute([$clienteId, $accountId]);
        if (!$st->fetchColumn()) throw new \InvalidArgumentException('Cliente não encontrado.');
    }

    /** "1.234,56", "1234.56" ou número → float; null se não é número. */
    public static function numero(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) return (float) $v;
        $s = trim((string) $v);
        if ($s === '') return null;
        $s = preg_replace('/[^\d,.\-]/', '', $s) ?? '';
        if (str_contains($s, ',')) $s = str_replace(['.', ','], ['', '.'], $s);
        return is_numeric($s) ? round((float) $s, 2) : null;
    }

    private static function data(mixed $v, string $nome): ?string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '') return null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) || !checkdate((int) substr($s, 5, 2), (int) substr($s, 8, 2), (int) substr($s, 0, 4))) {
            throw new \InvalidArgumentException("Data de $nome inválida.");
        }
        return $s;
    }
}
