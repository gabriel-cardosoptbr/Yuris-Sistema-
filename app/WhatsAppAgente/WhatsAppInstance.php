<?php
namespace App\WhatsAppAgente;

use App\Core\Database;
use PDO;

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

    /**
     * Retorna a primeira instância WhatsApp de um tenant (geralmente só tem 1).
     * Usado por endpoints que não querem ter que descobrir o instance_name —
     * pegam direto a instância do AccountContext.
     *
     * @return array|false Linha da whatsapp_instances ou false se não houver.
     */
    public function getByAccountId(int $accountId): array|false
    {
        if ($accountId <= 0) return false;
        $stmt = $this->db->prepare(
            'SELECT * FROM whatsapp_instances
             WHERE account_id = ?
             ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$accountId]);
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
     *
     * P0 LGPD (1.8) — accountId agora é OBRIGATÓRIO. O caminho legado sem
     * accountId foi removido porque era a raiz do bug C-03 (instâncias órfãs
     * criadas pelo webhook inbound + sequestro cross-tenant).
     *
     * Schema: UNIQUE composto (account_id, instance_name) garante isolamento.
     *
     * @param int $accountId Tenant dono da instância.
     */
    public function findOrCreate(string $name, string $displayName = '', int $accountId = 0): array
    {
        if ($accountId <= 0) {
            throw new \InvalidArgumentException(
                'WhatsAppInstance::findOrCreate exige accountId válido (LGPD P0). ' .
                'Endpoints chamadores devem passar AccountContext::fromSession()->getAccountId().'
            );
        }
        $row = $this->findByName($name, [$accountId]);
        if ($row) return $row;
        $stmt = $this->db->prepare(
            'INSERT INTO whatsapp_instances (account_id, instance_name, display_name, status)
             VALUES (?, ?, ?, "close")'
        );
        $stmt->execute([$accountId, $name, $displayName ?: $name]);
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

    /**
     * Ler configurações da Evolution API — agora PER-TENANT (LGPD P0 1.8).
     *
     * @param int $accountId Tenant cuja configuração quer ler.
     */
    public function getSettings(int $accountId): array
    {
        if ($accountId <= 0) {
            throw new \InvalidArgumentException('WhatsAppInstance::getSettings exige accountId válido (LGPD P0).');
        }
        $stmt = $this->db->prepare('SELECT config_key, config_value FROM whatsapp_settings WHERE account_id = ?');
        $stmt->execute([$accountId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $out[$r['config_key']] = $r['config_value'];
        }
        return $out;
    }

    /**
     * Salvar configuração — agora PER-TENANT.
     * UNIQUE composto (account_id, config_key) garante idempotência por tenant.
     */
    public function saveSetting(int $accountId, string $key, string $value): bool
    {
        if ($accountId <= 0) {
            throw new \InvalidArgumentException('WhatsAppInstance::saveSetting exige accountId válido (LGPD P0).');
        }
        $stmt = $this->db->prepare(
            'INSERT INTO whatsapp_settings (account_id, config_key, config_value)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
        );
        return $stmt->execute([$accountId, $key, $value]);
    }

    /**
     * Resolve o tenant a partir da apikey enviada no webhook inbound da Evolution.
     * Retorna o account_id da config que bate exatamente (timing-safe via hash_equals
     * é responsabilidade do caller — aqui só fazemos lookup).
     *
     * Usado por public/api/whatsapp/webhook.php pra identificar de qual tenant
     * vem cada evento da Evolution sem session.
     *
     * @return int|null account_id ou null se não houver match.
     */
    public function findAccountByApiKey(string $apiKey): ?int
    {
        if ($apiKey === '') return null;
        $ids = $this->accountsUsingApiKey($apiKey);
        if (!$ids) return null;

        // Blindagem contra "divergência de conta": a MESMA evolution_api_key
        // configurada em mais de um tenant torna a identificação ambígua — o
        // webhook entregava na primeira que o índice devolvesse (não-determinístico)
        // e o usuário do OUTRO tenant "não recebia". Agora escolhemos sempre a
        // mesma conta (menor account_id = determinístico) e registramos o conflito
        // no log do servidor pra diagnóstico. A correção definitiva é limpar a
        // duplicata: cada tenant deve ter sua própria apikey/instância.
        if (count($ids) > 1) {
            error_log(
                '[WhatsApp] CONFLITO de conta: evolution_api_key compartilhada por '
                . count($ids) . ' tenants (account_id=' . implode(',', $ids) . '). '
                . 'Webhook usando ' . $ids[0] . '. Limpe a duplicata em whatsapp_settings '
                . '(cada conta deve ter apikey/instância própria).'
            );
        }
        return $ids[0];
    }

    /**
     * Retorna TODOS os account_id que usam a mesma evolution_api_key, em ordem
     * determinística (menor primeiro). Vazio se nenhum. Usado por
     * findAccountByApiKey (blindagem) e por diagnóstico de conflito de conta.
     *
     * @return int[]
     */
    public function accountsUsingApiKey(string $apiKey): array
    {
        if ($apiKey === '') return [];
        $stmt = $this->db->prepare(
            "SELECT account_id FROM whatsapp_settings
             WHERE config_key = 'evolution_api_key' AND config_value = ?
             ORDER BY account_id ASC"
        );
        $stmt->execute([$apiKey]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * account_id(s) que JÁ usam esta evolution_api_key, EXCETO $exceptAccountId.
     * Vazio = sem conflito. Usado pela tela de configuração (config.php) para
     * bloquear que duas contas compartilhem a mesma chave/instância — raiz do
     * "não recebe": com a key repetida o webhook (findAccountByApiKey) fica
     * ambíguo e só UMA conta recebe. Regra de negócio: uma instância por
     * conta/número. O número como dado/contato pode aparecer em várias contas —
     * o que se bloqueia aqui é a INSTÂNCIA (chave de roteamento) duplicada.
     *
     * @return int[]
     */
    public function apiKeyConflict(string $apiKey, int $exceptAccountId): array
    {
        if (trim($apiKey) === '') return [];
        return array_values(array_filter(
            $this->accountsUsingApiKey(trim($apiKey)),
            fn($id) => $id !== $exceptAccountId
        ));
    }

    /**
     * 4B (cursor de eventos, Onda 4): incrementa events_seq e carimba last_event_at
     * da instancia. Todo caminho que ESCREVE mensagem/chat chama isto (webhook, send,
     * sync/discover quando gravam algo novo). O chat.js faz poll barato desse seq (1
     * SELECT por PK em poll.php) e so refaz as queries pesadas quando ele muda.
     *
     * Best-effort: NUNCA derruba o fluxo chamador. Se a coluna nao existir (migration
     * 107 nao aplicada) ou o banco falhar, o front simplesmente cai no polling antigo.
     */
    public function bumpEvents(int $id): void
    {
        if ($id <= 0) return;
        try {
            $this->db->prepare(
                'UPDATE whatsapp_instances SET events_seq = events_seq + 1, last_event_at = NOW() WHERE id = ?'
            )->execute([$id]);
        } catch (\Throwable $_) { /* 107 ausente ou DB indisponivel: silencioso */ }
    }
}
