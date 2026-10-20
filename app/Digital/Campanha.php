<?php
namespace App\Digital;

use App\Core\Database;

/**
 * Campanhas de tráfego pago por cliente (20/10/2026): plataforma, objetivo,
 * verba mensal, período, situação, link do gerenciador e o quadro de produção
 * dos criativos, se houver. Só cadastro e acompanhamento: o sistema não fala
 * com Meta, Google ou TikTok.
 */
final class Campanha
{
    public const PLATAFORMAS = ['meta' => 'Meta (Facebook e Instagram)', 'google' => 'Google Ads', 'tiktok' => 'TikTok Ads', 'linkedin' => 'LinkedIn Ads', 'outra' => 'Outra'];
    public const STATUS = ['planejada' => 'Planejada', 'ativa' => 'Ativa', 'pausada' => 'Pausada', 'encerrada' => 'Encerrada'];

    /** @param int[]|null $clienteIds null = todos da conta (quem chama já filtrou a permissão) */
    public static function listar(int $accountId, ?array $clienteIds = null, ?string $status = null): array
    {
        $where = ['k.account_id = ?']; $args = [$accountId];
        if ($clienteIds !== null) {
            if (!$clienteIds) return [];
            $where[] = 'k.cliente_id IN (' . implode(',', array_fill(0, count($clienteIds), '?')) . ')';
            array_push($args, ...array_map('intval', $clienteIds));
        }
        if ($status !== null && isset(self::STATUS[$status])) { $where[] = 'k.status = ?'; $args[] = $status; }
        $st = Database::getConnection()->prepare(
            'SELECT k.*, cl.nome AS cliente_nome, u.nome AS responsavel_nome FROM crm_campanhas k
               JOIN clientes cl ON cl.id = k.cliente_id AND cl.account_id = k.account_id
               LEFT JOIN users u ON u.id = k.responsavel_id AND u.account_id = k.account_id
              WHERE ' . implode(' AND ', $where) . " ORDER BY FIELD(k.status, 'ativa', 'planejada', 'pausada', 'encerrada'), k.inicio DESC, k.id DESC"
        );
        $st->execute($args);
        return array_map(fn($k) => [
            'id' => (int) $k['id'], 'cliente_id' => (int) $k['cliente_id'], 'cliente' => $k['cliente_nome'], 'nome' => $k['nome'],
            'plataforma' => $k['plataforma'], 'plataforma_rotulo' => self::PLATAFORMAS[$k['plataforma']] ?? $k['plataforma'],
            'objetivo' => $k['objetivo'], 'verba_mensal' => $k['verba_mensal'] !== null ? (float) $k['verba_mensal'] : null,
            'inicio' => $k['inicio'], 'fim' => $k['fim'], 'status' => $k['status'], 'status_rotulo' => self::STATUS[$k['status']] ?? $k['status'],
            'link' => $k['link'], 'responsavel_id' => $k['responsavel_id'] ? (int) $k['responsavel_id'] : null, 'responsavel' => $k['responsavel_nome'],
            'board_id' => $k['board_id'] ? (int) $k['board_id'] : null, 'observacao' => $k['observacao'],
        ], $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    public static function salvar(int $accountId, int $userId, array $d): int
    {
        $pdo = Database::getConnection();
        $nome = trim((string) ($d['nome'] ?? ''));
        if ($nome === '' || mb_strlen($nome) > 160) throw new \InvalidArgumentException('Informe o nome da campanha (até 160 caracteres).');
        $cliente = (int) ($d['cliente_id'] ?? 0);
        $c = $pdo->prepare('SELECT id FROM clientes WHERE id = ? AND account_id = ? AND deleted_at IS NULL');
        $c->execute([$cliente, $accountId]);
        if (!$cliente || !$c->fetchColumn()) throw new \InvalidArgumentException('Escolha o cliente da campanha.');
        $plat = (string) ($d['plataforma'] ?? '');
        if (!isset(self::PLATAFORMAS[$plat])) throw new \InvalidArgumentException('Escolha a plataforma.');
        $status = (string) ($d['status'] ?? 'planejada');
        if (!isset(self::STATUS[$status])) throw new \InvalidArgumentException('Situação inválida.');
        $verba = null;
        if (isset($d['verba_mensal']) && $d['verba_mensal'] !== '' && $d['verba_mensal'] !== null) {
            $verba = \App\Clientes\ValoresDoCliente::numero($d['verba_mensal']);
            if ($verba === null || $verba < 0) throw new \InvalidArgumentException('Verba inválida.');
        }
        $data = function ($v) { return !empty($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : null; };
        $inicio = $data($d['inicio'] ?? null); $fim = $data($d['fim'] ?? null);
        if ($inicio && $fim && $fim < $inicio) throw new \InvalidArgumentException('O fim vem depois do início.');
        $link = trim((string) ($d['link'] ?? ''));
        if ($link !== '' && !preg_match('#^https://#i', $link)) throw new \InvalidArgumentException('O link começa com https://.');
        $resp = !empty($d['responsavel_id']) ? (int) $d['responsavel_id'] : null;
        if ($resp) {
            $u = $pdo->prepare('SELECT id FROM users WHERE id = ? AND account_id = ? AND deleted_at IS NULL');
            $u->execute([$resp, $accountId]);
            if (!$u->fetchColumn()) throw new \InvalidArgumentException('Responsável não encontrado nesta conta.');
        }
        $board = !empty($d['board_id']) ? (int) $d['board_id'] : null;
        if ($board) {
            $b = $pdo->prepare('SELECT id FROM task_boards WHERE id = ? AND account_id = ? AND ativo = 1');
            $b->execute([$board, $accountId]);
            if (!$b->fetchColumn()) throw new \InvalidArgumentException('Quadro não encontrado nesta conta.');
        }
        $campos = [$cliente, $nome, $plat, trim((string) ($d['objetivo'] ?? '')) ?: null, $verba, $inicio, $fim, $status, $link ?: null, $resp, $board,
            trim((string) ($d['observacao'] ?? '')) ?: null];
        $id = (int) ($d['id'] ?? 0);
        if ($id) {
            $st = $pdo->prepare('UPDATE crm_campanhas SET cliente_id = ?, nome = ?, plataforma = ?, objetivo = ?, verba_mensal = ?, inicio = ?, fim = ?, status = ?,
                                        link = ?, responsavel_id = ?, board_id = ?, observacao = ? WHERE id = ? AND account_id = ?');
            $st->execute(array_merge($campos, [$id, $accountId]));
            $ex = $pdo->prepare('SELECT id FROM crm_campanhas WHERE id = ? AND account_id = ?');
            $ex->execute([$id, $accountId]);
            if (!$ex->fetchColumn()) throw new \InvalidArgumentException('Campanha não encontrada.');
            return $id;
        }
        $pdo->prepare('INSERT INTO crm_campanhas (cliente_id, nome, plataforma, objetivo, verba_mensal, inicio, fim, status, link, responsavel_id, board_id, observacao, account_id, criado_por)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(array_merge($campos, [$accountId, $userId ?: null]));
        return (int) $pdo->lastInsertId();
    }

    public static function remover(int $accountId, int $id): void
    {
        Database::getConnection()->prepare('DELETE FROM crm_campanhas WHERE id = ? AND account_id = ?')->execute([$id, $accountId]);
    }

    public static function clienteDa(int $accountId, int $id): ?int
    {
        $st = Database::getConnection()->prepare('SELECT cliente_id FROM crm_campanhas WHERE id = ? AND account_id = ?');
        $st->execute([$id, $accountId]);
        $c = $st->fetchColumn();
        return $c ? (int) $c : null;
    }
}
