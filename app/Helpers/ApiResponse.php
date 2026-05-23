<?php
namespace App\Helpers;

/**
 * ApiResponse — helper centralizado para respostas JSON consistentes.
 *
 * Padrão de resposta (Fase 3 audit — unificação):
 *   sucesso:  {"ok": true,  "data": ...}
 *   erro:     {"ok": false, "error": "msg", "code": "OPCIONAL"}
 *
 * Mantém retrocompat com endpoints legados que usam {"success": true}
 * via ApiResponse::legacySuccess() / legacyError().
 *
 * Uso:
 *   ApiResponse::ok(['id' => 42]);
 *   ApiResponse::error('CSRF inválido', 400);
 *   ApiResponse::unauthorized();
 *   ApiResponse::forbidden('Apenas admin');
 *   ApiResponse::notFound('Processo não encontrado');
 */
final class ApiResponse
{
    /** Resposta de sucesso (200 OK). */
    public static function ok(mixed $data = null, int $httpCode = 200): never
    {
        self::send(['ok' => true, 'data' => $data], $httpCode);
    }

    /** Resposta de erro genérica. */
    public static function error(string $message, int $httpCode = 400, ?string $code = null): never
    {
        $body = ['ok' => false, 'error' => $message];
        if ($code) $body['code'] = $code;
        self::send($body, $httpCode);
    }

    /** Atalhos comuns. */
    public static function unauthorized(string $msg = 'Unauthorized'): never
    {
        self::error($msg, 401, 'UNAUTHORIZED');
    }
    public static function forbidden(string $msg = 'Acesso negado'): never
    {
        self::error($msg, 403, 'FORBIDDEN');
    }
    public static function notFound(string $msg = 'Não encontrado'): never
    {
        self::error($msg, 404, 'NOT_FOUND');
    }
    public static function badRequest(string $msg = 'Parâmetros inválidos'): never
    {
        self::error($msg, 400, 'BAD_REQUEST');
    }
    public static function methodNotAllowed(): never
    {
        self::error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
    }
    public static function serverError(string $msg = 'Erro interno'): never
    {
        self::error($msg, 500, 'SERVER_ERROR');
    }

    /** Para endpoints legados que esperam {success: true} no JS. */
    public static function legacySuccess(array $extra = []): never
    {
        self::send(array_merge(['success' => true], $extra), 200);
    }
    public static function legacyError(string $msg, int $code = 400): never
    {
        self::send(['success' => false, 'error' => $msg], $code);
    }

    // ──────────────────────────────────────────────────────────────────────

    private static function send(array $body, int $httpCode): never
    {
        if (!headers_sent()) {
            http_response_code($httpCode);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        exit;
    }
}
