<?php
namespace App\Digital;

use App\Core\Database;

/**
 * A frequência contratada pelo cliente (20/10/2026): "6 vídeos por mês",
 * "3 posts por semana". É o previsto do previsto x realizado.
 *
 * Semanal vira mês pela conta de semanas do mês (dias do mês ÷ 7, arredondado
 * no total): 3 por semana em 30 dias = 13. Vale do mês do início ao mês do fim.
 */
final class Frequencia
{
    public const PERIODOS = ['mensal' => 'por mês', 'semanal' => 'por semana'];

    public static function doCliente(int $accountId, int $clienteId): array
    {
        $st = Database::getConnection()->prepare('SELECT * FROM crm_cliente_frequencias WHERE account_id = ? AND cliente_id = ? ORDER BY tipo, inicio');
        $st->execute([$accountId, $clienteId]);
        return array_map(fn($f) => [
            'id' => (int) $f['id'], 'tipo' => $f['tipo'], 'tipo_rotulo' => Entrega::TIPOS[$f['tipo']] ?? $f['tipo'],
            'quantidade' => (int) $f['quantidade'], 'periodo' => $f['periodo'], 'periodo_rotulo' => self::PERIODOS[$f['periodo']] ?? $f['periodo'],
            'inicio' => $f['inicio'], 'fim' => $f['fim'],
        ], $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    public static function salvar(int $accountId, int $userId, int $clienteId, array $d): int
    {
        $tipo = (string) ($d['tipo'] ?? '');
        if (!isset(Entrega::TIPOS[$tipo])) throw new \InvalidArgumentException('Escolha o tipo de entrega.');
        $qtd = filter_var($d['quantidade'] ?? null, FILTER_VALIDATE_INT);
        if ($qtd === false || $qtd < 1 || $qtd > 500) throw new \InvalidArgumentException('A quantidade vai de 1 a 500.');
        $periodo = (string) ($d['periodo'] ?? 'mensal');
        if (!isset(self::PERIODOS[$periodo])) throw new \InvalidArgumentException('Período inválido.');
        $inicio = (string) ($d['inicio'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $inicio)) throw new \InvalidArgumentException('Informe quando a frequência começa.');
        $fim = !empty($d['fim']) ? (string) $d['fim'] : null;
        if ($fim !== null && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fim) || $fim < $inicio)) throw new \InvalidArgumentException('O fim vem depois do início.');

        $pdo = Database::getConnection();
        $c = $pdo->prepare('SELECT id FROM clientes WHERE id = ? AND account_id = ? AND deleted_at IS NULL');
        $c->execute([$clienteId, $accountId]);
        if (!$c->fetchColumn()) throw new \InvalidArgumentException('Cliente não encontrado nesta conta.');

        $id = (int) ($d['id'] ?? 0);
        if ($id) {
            $st = $pdo->prepare('UPDATE crm_cliente_frequencias SET tipo = ?, quantidade = ?, periodo = ?, inicio = ?, fim = ? WHERE id = ? AND account_id = ? AND cliente_id = ?');
            $st->execute([$tipo, $qtd, $periodo, $inicio, $fim, $id, $accountId, $clienteId]);
            if (!$st->rowCount()) {
                $ex = $pdo->prepare('SELECT id FROM crm_cliente_frequencias WHERE id = ? AND account_id = ? AND cliente_id = ?');
                $ex->execute([$id, $accountId, $clienteId]);
                if (!$ex->fetchColumn()) throw new \InvalidArgumentException('Frequência não encontrada.');
            }
            return $id;
        }
        $pdo->prepare('INSERT INTO crm_cliente_frequencias (account_id, cliente_id, tipo, quantidade, periodo, inicio, fim, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$accountId, $clienteId, $tipo, $qtd, $periodo, $inicio, $fim, $userId ?: null]);
        return (int) $pdo->lastInsertId();
    }

    public static function remover(int $accountId, int $clienteId, int $id): void
    {
        Database::getConnection()->prepare('DELETE FROM crm_cliente_frequencias WHERE id = ? AND account_id = ? AND cliente_id = ?')
            ->execute([$id, $accountId, $clienteId]);
    }

    /** Quantas entregas de uma frequência caem num mês. */
    public static function noMes(int $quantidade, string $periodo, string $mes): int
    {
        if ($periodo !== 'semanal') return $quantidade;
        $dias = (int) date('t', strtotime(substr($mes, 0, 7) . '-01'));
        return (int) round($quantidade * $dias / 7);
    }

    /**
     * Previsto por cliente e tipo num mês.
     * @param int[] $clienteIds
     * @return array<int, array<string, int>>
     */
    public static function previstoNoMes(int $accountId, string $mes, array $clienteIds): array
    {
        if (!$clienteIds) return [];
        $ini = substr($mes, 0, 7) . '-01';
        $fim = date('Y-m-t', strtotime($ini));
        $in = implode(',', array_fill(0, count($clienteIds), '?'));
        $st = Database::getConnection()->prepare(
            "SELECT cliente_id, tipo, quantidade, periodo FROM crm_cliente_frequencias
              WHERE account_id = ? AND cliente_id IN ($in) AND inicio <= ? AND (fim IS NULL OR fim >= ?)"
        );
        $st->execute(array_merge([$accountId], $clienteIds, [$fim, $ini]));
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $f) {
            $c = (int) $f['cliente_id'];
            $out[$c][$f['tipo']] = ($out[$c][$f['tipo']] ?? 0) + self::noMes((int) $f['quantidade'], $f['periodo'], $mes);
        }
        return $out;
    }
}
