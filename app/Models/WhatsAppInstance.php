<?php
require_once __DIR__ . '/Database.php';

use App\Models\Database;

class WhatsAppInstance
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Lista instâncias visíveis para o tenant.
     * @param int[]|null $accountIds quando informado, escopa por account_id IN (...)
     */
    public function listAll(?array $accountIds = null): array
    {
        if ($accountIds === null) {
            $stmt = $this->db->query('SELECT * FROM whatsapp_instances ORDER BY created_at DESC');
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $ids = array_values(array_filter(array_map('intval', $accountIds), fn($v) => $v > 0));
        if (empty($ids)) return [];
        $ph = []; $params = [];
        foreach ($ids as $i => $aid) { $k = "wacc_{$i}"; $ph[] = ":{$k}"; $params[$k] = (int)$aid; }
        $sql = 'SELECT * FROM whatsapp_instances WHERE account_id IN (' . implode(',', $ph) . ') ORDER BY created_at DESC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id, ?array $accountIds = null): array|false
    {
        if ($accountIds === null) {
            $stmt = $this->db->prepare('SELECT * FROM whatsapp_instances WHERE id = ?');
            $stmt->execute([$id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }
        $ids = array_values(array_filter(array_map('intval', $accountIds), fn($v) => $v > 0));
        if (empty($ids)) return false;
        $ph = []; $params = ['id' => $id];
        foreach ($ids as $i => $aid) { $k = "wfa_{$i}"; $ph[] = ":{$k}"; $params[$k] = (int)$aid; }
        $stmt = $this->db->prepare('SELECT * FROM whatsapp_instances WHERE id = :id AND account_id IN (' . implode(',', $ph) . ')');
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findByName(string $name, ?array $accountIds = null): array|false
    {
        if ($accountIds === null) {
            $stmt = $this->db->prepare('SELECT * FROM whatsapp_instances WHERE instance_name = ?');
            $stmt->execute([$name]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }
        $ids = array_values(array_filter(array_map('intval', $accountIds), fn($v) => $v > 0));
        if (empty($ids)) return false;
        $ph = []; $params = ['name' => $name];
        foreach ($ids as $i => $aid) { $k = "wfb_{$i}"; $ph[] = ":{$k}"; $params[$k] = (int)$aid; }
        $stmt = $this->db->prepare('SELECT * FROM whatsapp_instances WHERE instance_name = :name AND account_id IN (' . implode(',', $ph) . ')');
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Idempotente: se a instância com $name existir DENTRO do tenant, retorna; senão cria.
     * @param int|null $accountId obrigatório em ambientes multi-tenant (NULL apenas para legado/single-tenant)
     */
    public function findOrCreate(string $name, string $displayName = '', ?int $accountId = null): array
    {
        if ($accountId !== null) {
            $row = $this->findByName($name, [$accountId]);
            if ($row) return $row;
            $stmt = $this->db->prepare(
                'INSERT INTO whatsapp_instances (account_id, instance_name, display_name, status)
                 VALUES (?, ?, ?, "close")'
            );
            $stmt->execute([$accountId, $name, $displayName ?: $name]);
            return $this->find((int)$this->db->lastInsertId());
        }
        // Caminho legado: comportamento original (sem tenant)
        $row = $this->findByName($name);
        if ($row) return $row;
        $stmt = $this->db->prepare(
            'INSERT INTO whatsapp_instances (instance_name, display_name, status)
             VALUES (?, ?, "close")'
        );
        $stmt->execute([$name, $displayName ?: $name]);
        return $this->find((int)$this->db->lastInsertId());
    }

    public function updateStatus(int $id, string $status, array $extra = []): bool
    {
        $allowed = ['open', 'close', 'connecting', 'qr'];
        if (!in_array($status, $allowed, true)) return false;

        $fields = ['status = ?'];
        $values = [$status];

        foreach (['phone', 'profile_name', 'profile_pic_url'] as $col) {
            if (array_key_exists($col, $extra)) {
                $fields[] = "$col = ?";
                $values[] = $extra[$col];
            }
        }

        $values[] = $id;
        $sql = 'UPDATE whatsapp_instances SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($values);
    }

    public function updateQrCode(int $id, string $qrBase64): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE whatsapp_instances SET qr_code_base64 = ?, status = "qr" WHERE id = ?'
        );
        return $stmt->execute([$qrBase64, $id]);
    }

    public function clearQrCode(int $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE whatsapp_instances SET qr_code_base64 = NULL WHERE id = ?'
        );
        return $stmt->execute([$id]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM whatsapp_instances WHERE id = ?');
        return $stmt->execute([$id]);
    }

    /** Ler/salvar configuração global da Evolution API */
    public function getSettings(): array
    {
        $stmt = $this->db->query('SELECT config_key, config_value FROM whatsapp_settings');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $out[$r['config_key']] = $r['config_value'];
        }
        return $out;
    }

    public function saveSetting(string $key, string $value): bool
    {
        $stmt = $this->db->prepare(
            'INSERT INTO whatsapp_settings (config_key, config_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
        );
        return $stmt->execute([$key, $value]);
    }
}
