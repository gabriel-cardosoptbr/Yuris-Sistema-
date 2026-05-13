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

    public function listAll(): array
    {
        $stmt = $this->db->query(
            'SELECT * FROM whatsapp_instances ORDER BY created_at DESC'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): array|false
    {
        $stmt = $this->db->prepare('SELECT * FROM whatsapp_instances WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findByName(string $name): array|false
    {
        $stmt = $this->db->prepare('SELECT * FROM whatsapp_instances WHERE instance_name = ?');
        $stmt->execute([$name]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findOrCreate(string $name, string $displayName = ''): array
    {
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
