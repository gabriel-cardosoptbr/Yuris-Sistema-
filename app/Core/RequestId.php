<?php
namespace App\Core;

/**
 * RequestId — ID único de 12 hex (~48 bits) por request HTTP.
 *
 * Permite correlacionar múltiplas inserções nas tabelas de audit que
 * pertencem ao mesmo request. Ex:
 *   • account_audit_log (entidade=conta)
 *   • processo_history (entidade=processo daquela conta)
 *   • master_audit_log (super_admin que fez a ação)
 *   - todos terão o mesmo request_id → trilha forense única.
 *
 * É lazy: gera UMA vez na primeira chamada e mantém na variável estática.
 * Não usa sessão (queremos id por request HTTP, não por sessão).
 *
 * USO:
 *   $cid = RequestId::get();
 *   $pdo->prepare('INSERT INTO audit (request_id, ...) VALUES (?, ...)')
 *       ->execute([$cid, ...]);
 */
final class RequestId
{
    private static ?string $id = null;

    public static function get(): string
    {
        if (self::$id === null) {
            // 12 hex = 6 bytes = 2^48 = 281 trilhões — suficiente pra HTTP requests
            self::$id = bin2hex(random_bytes(6));
        }
        return self::$id;
    }

    /** Reset (uso interno em testes). */
    public static function reset(): void
    {
        self::$id = null;
    }
}
