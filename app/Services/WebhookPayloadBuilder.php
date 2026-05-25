<?php
namespace App\Services;

use App\Helpers\PayloadMasker;
use App\Models\Database;

/**
 * WebhookPayloadBuilder — constroi o envelope v2 dos webhooks Yuris.
 *
 * Shape:
 * {
 *   "event":       "card.created",
 *   "event_id":    "evt_<25 chars>",
 *   "occurred_at": "2026-05-25T14:30:00-03:00",
 *   "tenant":       { "id", "name" },
 *   "organization": { "id", "type", "name", "matriz_id" },
 *   "actor":        { "id", "role", "email" } | null,
 *   "data":         { "id", "type", "attributes": {...} },
 *   "metadata":     { "source": "yuris", "environment", "version": "2.0", "payload_mode" }
 * }
 *
 * Mascaramento aplicado conforme endpoint.payload_mode (minimal|masked|full)
 * sobre o bloco `data.attributes`. Email do actor segue o mesmo modo.
 */
class WebhookPayloadBuilder
{
    private const VERSION = '2.0';

    public static function v2(string $eventCode, array $v1Opts, array $endpoint, ?array $actorOverride = null, ?string $eventIdOverride = null): array
    {
        $mode      = $endpoint['payload_mode'] ?? 'masked';
        $tenantId  = (int)($endpoint['account_id'] ?? 0);
        $tenant    = self::fetchTenantInfo($tenantId);

        $actor = $actorOverride ?? self::fetchActorFromSession();
        if ($actor && isset($actor['email'])) {
            $actor['email'] = $mode === 'full'
                ? $actor['email']
                : PayloadMasker::email($actor['email']);
        }

        $attributes = (array)($v1Opts['data'] ?? []);
        if (!empty($attributes)) {
            $attributes = PayloadMasker::applyMode($attributes, $mode);
        }

        $parts = explode('.', $eventCode);
        return [
            'event'       => $eventCode,
            'event_id'    => $eventIdOverride ?? self::generateEventId(),
            'occurred_at' => date('c'),
            'tenant'      => [
                'id'   => $tenantId,
                'name' => $tenant['nome'] ?? null,
            ],
            'organization' => [
                'id'        => $tenantId,
                'type'      => $tenant['tipo']      ?? 'matriz',
                'name'      => $tenant['nome']      ?? null,
                'matriz_id' => $tenant['matriz_id'] ?? null,
            ],
            'actor' => $actor,
            'data'  => [
                'id'         => $v1Opts['entity_id'] ?? null,
                'type'       => $v1Opts['entity']    ?? ($parts[0] ?? 'unknown'),
                'attributes' => $attributes,
            ],
            'metadata' => [
                'source'       => 'yuris',
                'environment'  => $_ENV['APP_ENV'] ?? 'production',
                'version'      => self::VERSION,
                'payload_mode' => $mode,
            ],
        ];
    }

    /** Identificador unico estavel para correlacionar eventos no destino. */
    public static function generateEventId(): string
    {
        try {
            $bytes = random_bytes(16);
        } catch (\Throwable $e) {
            $bytes = openssl_random_pseudo_bytes(16) ?: pack('Nn', time(), random_int(0, 65535));
        }
        return 'evt_' . substr(strtolower(bin2hex($bytes)), 0, 25);
    }

    private static function fetchTenantInfo(int $accountId): array
    {
        if ($accountId <= 0) return [];
        try {
            $pdo = Database::getConnection();
            $st  = $pdo->prepare('SELECT id, nome, tipo, matriz_id FROM accounts WHERE id = ? LIMIT 1');
            $st->execute([$accountId]);
            return $st->fetch(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function fetchActorFromSession(): ?array
    {
        if (empty($_SESSION['user_id'])) return null;
        try {
            $pdo = Database::getConnection();
            $st  = $pdo->prepare('SELECT id, role, perfil, login AS email FROM users WHERE id = ? LIMIT 1');
            $st->execute([(int)$_SESSION['user_id']]);
            $u = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$u) return null;
            return [
                'id'    => (int)$u['id'],
                'role'  => $u['role'] ?? $u['perfil'] ?? null,
                'email' => $u['email'] ?? null,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }
}
