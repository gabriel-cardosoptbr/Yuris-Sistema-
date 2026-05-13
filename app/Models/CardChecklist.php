<?php
namespace App\Models;

use App\Models\Database;

class CardChecklist
{
    public static function listByCard($card_id)
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id, card_id, titulo AS descricao, concluido AS marcado, `ordem`, created_at, updated_at FROM card_checklist_items WHERE card_id = :card_id ORDER BY `ordem`, created_at, id');
        $stmt->execute(['card_id' => $card_id]);
        return $stmt->fetchAll();
    }

    public static function create($card_id, $descricao, $usuario_id = null)
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('INSERT INTO card_checklist_items (card_id, titulo, concluido, `ordem`, created_at, updated_at) VALUES (:card_id, :titulo, 0, 0, NOW(), NOW())');
        $stmt->execute(['card_id' => $card_id, 'titulo' => $descricao]);
        return $pdo->lastInsertId();
    }

    public static function toggle($id, $marcado)
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE card_checklist_items SET concluido = :concluido, updated_at = NOW() WHERE id = :id');
        return $stmt->execute(['concluido' => $marcado ? 1 : 0, 'id' => $id]);
    }

    public static function delete($id)
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM card_checklist_items WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }

    public static function update($id, $descricao)
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE card_checklist_items SET titulo = :titulo, updated_at = NOW() WHERE id = :id');
        return $stmt->execute(['titulo' => $descricao, 'id' => $id]);
    }

    public static function updateOrders($card_id, array $reorder)
    {
        $pdo = Database::getConnection();
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('UPDATE card_checklist_items SET `ordem` = :ordem, updated_at = NOW() WHERE id = :id AND card_id = :card_id');
            foreach ($reorder as $row) {
                $id = isset($row['id']) ? (int)$row['id'] : 0;
                $ordem = isset($row['ordem']) ? (int)$row['ordem'] : 0;
                if ($id <= 0) continue;
                $stmt->execute([
                    'id' => $id,
                    'card_id' => (int)$card_id,
                    'ordem' => $ordem,
                ]);
            }
            $pdo->commit();
            return true;
        } catch (\Exception $e) {
            $pdo->rollBack();
            return false;
        }
    }
}
