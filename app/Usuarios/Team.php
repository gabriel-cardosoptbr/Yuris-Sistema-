<?php
namespace App\Usuarios;

use App\Core\Database;

/**
 * Setor (time) dentro da conta. Na tela é "Setor"; no banco é `teams`.
 *
 * O setor marca a conversa do WhatsApp (whatsapp_chats.team_id) e, desde a
 * migration 140, também o lead (cards.team_id), e tem um gestor
 * (teams.gestor_user_id). Antes de a 140 ser aplicada, as duas colunas novas são
 * ignoradas e o resto funciona como sempre (ver temColuna()).
 */
class Team
{
    /** Cores para setor criado sem cor escolhida (pelo robô, por exemplo). */
    public const CORES = ['#3B82F6', '#10B981', '#8B5CF6', '#F59E0B', '#EF4444', '#14B8A6', '#F97316', '#EC4899'];

    /** @var array<string,bool> cache de coluna existente, por "tabela.coluna" */
    private static array $colunas = [];

    /**
     * A coluna existe neste banco? Serve para o código novo não quebrar quando
     * sobe antes da migration 140.
     */
    public static function temColuna(string $tabela, string $coluna): bool
    {
        $chave = $tabela . '.' . $coluna;
        if (!array_key_exists($chave, self::$colunas)) {
            try {
                $st = Database::getConnection()->prepare(
                    'SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
                );
                $st->execute([$tabela, $coluna]);
                self::$colunas[$chave] = (int)$st->fetchColumn() > 0;
            } catch (\Throwable $e) {
                self::$colunas[$chave] = false;
            }
        }
        return self::$colunas[$chave];
    }

    /** Colunas do gestor para os SELECT de setor (vazio antes da migration 140). */
    private static function colunasGestor(): string
    {
        if (!self::temColuna('teams', 'gestor_user_id')) return '';
        return ', t.gestor_user_id,
                (SELECT u.nome FROM users u
                  WHERE u.id = t.gestor_user_id AND u.deleted_at IS NULL LIMIT 1) AS gestor_nome';
    }

    /**
     * Retorna os times visíveis para um usuário na aba de filtro.
     *
     * - owner/admin  → vê TODOS os times da conta
     * - demais roles → vê apenas os times dos quais é membro
     *
     * Usado pelo frontend para popular o dropdown de filtro de setores.
     */
    public static function getTeamsForUser(int $userId, int $accountId, bool $isAdmin = false): array
    {
        $pdo = Database::getConnection();

        if ($isAdmin) {
            // owner/admin enxerga todos os times
            $stmt = $pdo->prepare(
                'SELECT t.id, t.nome, t.cor, t.descricao,
                        (SELECT COUNT(*) FROM team_members tm WHERE tm.team_id = t.id) AS membros_count
                 FROM teams t
                 WHERE t.account_id = ? AND t.deleted_at IS NULL
                 ORDER BY t.nome ASC'
            );
            $stmt->execute([$accountId]);
        } else {
            // usuário comum vê somente os times dos quais faz parte
            $stmt = $pdo->prepare(
                'SELECT t.id, t.nome, t.cor, t.descricao,
                        (SELECT COUNT(*) FROM team_members tm WHERE tm.team_id = t.id) AS membros_count
                 FROM teams t
                 INNER JOIN team_members m ON m.team_id = t.id AND m.user_id = :uid
                 WHERE t.account_id = :acc AND t.deleted_at IS NULL
                 ORDER BY t.nome ASC'
            );
            $stmt->execute(['uid' => $userId, 'acc' => $accountId]);
        }

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['membros_count'] = (int)$row['membros_count'];
        }
        return $rows;
    }


    public static function list(int $accountId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT t.id, t.nome, t.cor, t.descricao, t.created_at' . self::colunasGestor() . ',
                    (SELECT GROUP_CONCAT(tm.user_id ORDER BY tm.user_id SEPARATOR ",")
                     FROM team_members tm WHERE tm.team_id = t.id) AS membro_ids,
                    (SELECT COUNT(*) FROM team_members tm WHERE tm.team_id = t.id) AS membros_count
             FROM teams t
             WHERE t.account_id = ? AND t.deleted_at IS NULL
             ORDER BY t.nome ASC'
        );
        $stmt->execute([$accountId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['membro_ids']    = $row['membro_ids']
                ? array_map('intval', explode(',', $row['membro_ids']))
                : [];
            $row['membros_count'] = (int)$row['membros_count'];
            self::normalizarGestor($row);
        }
        return $rows;
    }

    public static function findById(int $id, int $accountId): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT t.id, t.nome, t.cor, t.descricao, t.created_at, t.updated_at' . self::colunasGestor() . '
             FROM teams t WHERE t.id = ? AND t.account_id = ? AND t.deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([$id, $accountId]);
        $team = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$team) return null;
        self::normalizarGestor($team);

        $mStmt = $pdo->prepare('SELECT user_id FROM team_members WHERE team_id = ?');
        $mStmt->execute([$id]);
        $team['membro_ids'] = array_map('intval', $mStmt->fetchAll(\PDO::FETCH_COLUMN));
        return $team;
    }

    private static function normalizarGestor(array &$row): void
    {
        if (!array_key_exists('gestor_user_id', $row)) return;
        $row['gestor_user_id'] = $row['gestor_user_id'] !== null ? (int)$row['gestor_user_id'] : null;
        if ($row['gestor_user_id'] === null) $row['gestor_nome'] = null;
    }

    public static function create(array $data): int
    {
        $pdo = Database::getConnection();
        $comGestor = self::temColuna('teams', 'gestor_user_id');
        $stmt = $pdo->prepare(
            'INSERT INTO teams (account_id, nome, cor, descricao' . ($comGestor ? ', gestor_user_id' : '') . ', created_at, updated_at)
             VALUES (?, ?, ?, ?' . ($comGestor ? ', ?' : '') . ', NOW(), NOW())'
        );
        $par = [
            $data['account_id'],
            $data['nome'],
            $data['cor']      ?? '#3B82F6',
            $data['descricao'] ?? null,
        ];
        if ($comGestor) $par[] = !empty($data['gestor_user_id']) ? (int)$data['gestor_user_id'] : null;
        $stmt->execute($par);
        return (int)$pdo->lastInsertId();
    }

    public static function update(int $id, array $data): bool
    {
        $pdo = Database::getConnection();
        $allowed = ['nome', 'cor', 'descricao'];
        if (self::temColuna('teams', 'gestor_user_id')) $allowed[] = 'gestor_user_id';
        $fields  = [];
        $params  = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $params[] = $data[$f];
            }
        }
        if (!$fields) return true;
        $params[] = $id;
        return $pdo->prepare('UPDATE teams SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = ?')
                   ->execute($params);
    }

    public static function delete(int $id, int $accountId): bool
    {
        $pdo = Database::getConnection();
        return $pdo->prepare('UPDATE teams SET deleted_at = NOW() WHERE id = ? AND account_id = ?')
                   ->execute([$id, $accountId]);
    }

    public static function setMembers(int $teamId, array $userIds): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM team_members WHERE team_id = ?')->execute([$teamId]);
        if ($userIds) {
            $ins = $pdo->prepare('INSERT IGNORE INTO team_members (team_id, user_id) VALUES (?, ?)');
            foreach ($userIds as $uid) {
                $ins->execute([$teamId, (int)$uid]);
            }
        }
    }

    /**
     * O setor com esse nome na conta, criado se ainda não existe. É como a
     * automação (robô de prospecção, via /api/whatsapp/sdr_etapa.php) marca o
     * lead sem conhecer o id: "Locação" e "locacao" são o mesmo setor (a
     * collation de teams.nome ignora caixa e acento). O GET_LOCK impede dois
     * setores iguais quando duas chamadas chegam no mesmo segundo.
     *
     * @return int|null id do setor; null para nome vazio
     */
    public static function garantirPorNome(int $accountId, string $nome): ?int
    {
        $nome = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($nome))), 0, 100);
        if ($nome === '') return null;

        $pdo   = Database::getConnection();
        $trava = 'team_nome_' . $accountId . '_' . md5(mb_strtolower($nome));
        $pdo->prepare('SELECT GET_LOCK(?, 5)')->execute([$trava]);
        try {
            $st = $pdo->prepare('SELECT id FROM teams WHERE account_id = ? AND nome = ? AND deleted_at IS NULL ORDER BY id LIMIT 1');
            $st->execute([$accountId, $nome]);
            $id = (int)($st->fetchColumn() ?: 0);
            if ($id > 0) return $id;

            $st = $pdo->prepare('SELECT COUNT(*) FROM teams WHERE account_id = ? AND deleted_at IS NULL');
            $st->execute([$accountId]);
            $cor = self::CORES[(int)$st->fetchColumn() % count(self::CORES)];

            $id = self::create(['account_id' => $accountId, 'nome' => $nome, 'cor' => $cor, 'descricao' => null]);
            \App\Master\Account::audit($accountId, 'team.created', [
                'user_id'     => null,
                'entidade'    => 'team',
                'entidade_id' => $id,
                'detalhes'    => ['nome' => $nome, 'cor' => $cor, 'origem' => 'automacao'],
            ]);
            return $id;
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$trava]);
        }
    }

    /**
     * Marca o setor do lead (cards.team_id). Com $soSeVazio, não mexe em card
     * que já tem setor: é o modo da automação, que não desfaz a escolha de uma
     * pessoa. O card e o setor têm de ser da mesma conta. Antes da migration
     * 140 não faz nada.
     *
     * @return bool true quando o card mudou
     */
    public static function definirSetorDoCard(int $accountId, int $cardId, ?int $teamId, bool $soSeVazio = false): bool
    {
        if (!self::temColuna('cards', 'team_id')) return false;
        if ($teamId !== null && !self::findById($teamId, $accountId)) return false;

        $sql = 'UPDATE cards SET team_id = ? WHERE id = ? AND account_id = ? AND NOT (team_id <=> ?)';
        $par = [$teamId, $cardId, $accountId, $teamId];
        if ($soSeVazio) $sql .= ' AND team_id IS NULL';
        $st = Database::getConnection()->prepare($sql);
        $st->execute($par);
        return $st->rowCount() > 0;
    }
}
