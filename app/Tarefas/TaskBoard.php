<?php
namespace App\Tarefas;

use App\Core\Database;

class TaskBoard
{
    /**
     * QUEM ENXERGA E QUEM EDITA UM QUADRO (28/09/2026)
     *
     * Até esta data a regra era uma só: dono ou membro. E não existia nenhuma
     * tela para adicionar membro (a API aceitava, nenhum botão chamava). Medido em
     * produção: ZERO membros em todo o sistema, 14 quadros ativos, 10 deles
     * marcados "Compartilhado". Ou seja, todo quadro só era visível para quem o
     * criou, e "Compartilhado" era só um rótulo.
     *
     * Consequências reais: um administrador criado depois abria Tarefas e via
     * "Nenhum quadro"; e 281 tarefas estavam atribuídas a alguém que não conseguia
     * abrir o quadro onde elas moram.
     *
     * A regra agora, numa função só (`acesso`):
     *
     *   dono do quadro            vê e edita
     *   membro (owner/editor)     vê e edita          membro "leitor": só vê
     *   admin/owner da conta      vê e edita todos os quadros das contas dele
     *   quadro "compartilhado"    toda a equipe da conta vê e edita
     *   quadro "pessoal"          ninguém além dos acima
     *
     * ADMIN E EQUIPE SÓ VALEM COM O ESCOPO DE CONTA. Sem `$accountIds`, o
     * `findById` não filtra por tenant; se "compartilhado" valesse ali, o quadro
     * compartilhado de um escritório ficaria visível para outro. Nesse caso sobra
     * só dono e membro, que é o comportamento antigo.
     *
     * Renomear, trocar o tipo e gerenciar membros é `canManage`, mais restrito que
     * `canEdit`: qualquer pessoa da equipe mexe nas TAREFAS de um quadro
     * compartilhado, mas não transforma o quadro em pessoal nem o apaga.
     */

    /**
     * Lista os boards visíveis para o usuário DENTRO da sua conta (tenant).
     * Aceita um único accountId (legado) OU um array de account_ids
     * (sessão matriz vê boards das filiais vinculadas).
     */
    public static function findForUser(int $userId, int|array $accountIds, bool $isAdmin = false): array
    {
        $ids = is_array($accountIds)
            ? array_values(array_filter(array_map('intval', $accountIds), fn($v) => $v > 0))
            : [(int) $accountIds];
        if (empty($ids)) return [];

        $ph     = [];
        $params = ['uid1' => $userId, 'uid2' => $userId, 'adm' => $isAdmin ? 1 : 0];
        foreach ($ids as $i => $aid) {
            $k          = "tbacc_{$i}";
            $ph[]       = ":{$k}";
            $params[$k] = (int) $aid;
        }
        $inSql = implode(',', $ph);

        $pdo  = Database::getConnection();
        // LEFT JOIN com accounts devolve origem (matriz/filial — Nome) por board
        // → permite o frontend renderizar selo visual no seletor de boards.
        $stmt = $pdo->prepare("
            SELECT DISTINCT b.*,
                   a.nome AS origin_account_nome,
                   a.tipo AS origin_account_tipo,
                   b.account_id AS origin_account_id
            FROM task_boards b
            LEFT JOIN task_board_members m ON m.board_id = b.id AND m.user_id = :uid2
            LEFT JOIN accounts a ON a.id = b.account_id
            WHERE b.ativo = 1
              AND b.account_id IN ({$inSql})
              AND (b.owner_id = :uid1
                   OR m.user_id IS NOT NULL
                   OR b.tipo = 'compartilhado'
                   OR :adm = 1)
            ORDER BY
              CASE WHEN a.tipo = 'matriz' THEN 0 ELSE 1 END,
              a.nome ASC,
              b.ordem, b.id
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Busca board por id.
     * Quando $accountIds é passado, restringe ao tenant (segurança).
     */
    public static function findById(int $id, int|array|null $accountIds = null): array|false
    {
        $pdo = Database::getConnection();
        if ($accountIds === null) {
            $stmt = $pdo->prepare('SELECT * FROM task_boards WHERE id = ? AND ativo = 1');
            $stmt->execute([$id]);
            return $stmt->fetch(\PDO::FETCH_ASSOC);
        }
        $ids = is_array($accountIds)
            ? array_values(array_filter(array_map('intval', $accountIds), fn($v) => $v > 0))
            : [(int)$accountIds];
        if (empty($ids)) return false;
        $ph = []; $params = ['id' => $id];
        foreach ($ids as $i => $aid) { $k = "tbf_{$i}"; $ph[] = ":{$k}"; $params[$k] = (int)$aid; }
        $stmt = $pdo->prepare('SELECT * FROM task_boards WHERE id = :id AND ativo = 1 AND account_id IN (' . implode(',', $ph) . ')');
        $stmt->execute($params);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public static function create(array $data): int
    {
        if (empty($data['account_id'])) {
            throw new \InvalidArgumentException('account_id é obrigatório para criar um board');
        }
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            INSERT INTO task_boards (nome, descricao, tipo, owner_id, account_id, cor, ordem)
            VALUES (:nome, :descricao, :tipo, :owner_id, :account_id, :cor, :ordem)
        ');
        $stmt->execute([
            'nome'       => $data['nome'],
            'descricao'  => $data['descricao'] ?? null,
            'tipo'       => $data['tipo'] ?? 'pessoal',
            'owner_id'   => $data['owner_id'],
            'account_id' => $data['account_id'],
            'cor'        => $data['cor'] ?? '#6366f1',
            'ordem'      => $data['ordem'] ?? 0,
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $pdo = Database::getConnection();
        $fields = [];
        $params = [];
        foreach (['nome','descricao','cor','ordem','tipo'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = :$f";
                $params[$f] = $data[$f];
            }
        }
        if (!$fields) return;
        $params['id'] = $id;
        $pdo->prepare('UPDATE task_boards SET ' . implode(', ', $fields) . ' WHERE id = :id')
            ->execute($params);
    }

    public static function delete(int $id): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('UPDATE task_boards SET ativo = 0 WHERE id = ?')->execute([$id]);
    }

    public static function members(int $boardId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT m.*, u.nome, u.login
            FROM task_board_members m
            JOIN users u ON u.id = m.user_id
            WHERE m.board_id = ?
        ');
        $stmt->execute([$boardId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function addMember(int $boardId, int $userId, string $papel = 'editor'): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('
            INSERT INTO task_board_members (board_id, user_id, papel)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE papel = VALUES(papel)
        ')->execute([$boardId, $userId, $papel]);
    }

    public static function removeMember(int $boardId, int $userId): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM task_board_members WHERE board_id = ? AND user_id = ?')
            ->execute([$boardId, $userId]);
    }

    /**
     * O nível de acesso de um usuário a um quadro, ou null se não tem nenhum.
     *
     * @return 'dono'|'admin'|'editor'|'leitor'|'equipe'|null
     */
    public static function acesso(array $board, int $userId, bool $isAdmin, bool $comEscopo): ?string
    {
        if ((int)$board['owner_id'] === $userId) return 'dono';

        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT papel FROM task_board_members WHERE board_id = ? AND user_id = ?');
        $stmt->execute([(int)$board['id'], $userId]);
        $papel = $stmt->fetchColumn();
        if ($papel !== false) {
            return in_array($papel, ['owner', 'editor'], true) ? 'editor' : 'leitor';
        }

        // Daqui para baixo o acesso vem da CONTA, não de vínculo com o quadro.
        // Só vale quando o quadro já foi buscado dentro das contas do usuário.
        if (!$comEscopo) return null;

        if ($isAdmin) return 'admin';
        if (($board['tipo'] ?? '') === 'compartilhado') return 'equipe';
        return null;
    }

    /**
     * Pode editar o CONTEÚDO do board (tarefas, colunas)?
     *
     * P1 LGPD (2B.3): $accountIds opcional — quando informado, findById restringe
     * ao tenant antes de checar membership. Antes, admin de tenant A podia
     * adicionar-se como member de board de tenant B (IDOR via board_id conhecido).
     */
    public static function canEdit(int $boardId, int $userId, int|array|null $accountIds = null, bool $isAdmin = false): bool
    {
        $board = self::findById($boardId, $accountIds);
        if (!$board) return false;
        $a = self::acesso($board, $userId, $isAdmin, $accountIds !== null);
        return in_array($a, ['dono', 'admin', 'editor', 'equipe'], true);
    }

    public static function canView(int $boardId, int $userId, int|array|null $accountIds = null, bool $isAdmin = false): bool
    {
        $board = self::findById($boardId, $accountIds);
        if (!$board) return false;
        return self::acesso($board, $userId, $isAdmin, $accountIds !== null) !== null;
    }

    /**
     * Pode mexer no QUADRO em si: renomear, trocar tipo, gerenciar membros.
     * A equipe de um quadro compartilhado edita as tarefas, mas não isto.
     */
    public static function canManage(int $boardId, int $userId, int|array|null $accountIds = null, bool $isAdmin = false): bool
    {
        $board = self::findById($boardId, $accountIds);
        if (!$board) return false;
        $a = self::acesso($board, $userId, $isAdmin, $accountIds !== null);
        if (in_array($a, ['dono', 'admin'], true)) return true;
        // membro com papel "owner" também administra
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare("SELECT 1 FROM task_board_members WHERE board_id = ? AND user_id = ? AND papel = 'owner'");
        $stmt->execute([$boardId, $userId]);
        return (bool)$stmt->fetchColumn();
    }
}
