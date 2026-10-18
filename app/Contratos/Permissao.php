<?php
namespace App\Contratos;

use App\Core\AccountContext;
use App\Core\Database;

/**
 * Quem faz o quê nos contratos, conferido no SERVIDOR em cada ação da API.
 *
 *   ver / criar / editar / convidar / baixar   quem abre a Prospecção ou tem a
 *                                              chave 'contratos' (o contrato é do
 *                                              lead e do cliente, como o orçamento)
 *   gerenciar (cancelar, liberar bloqueio,     dono ou administrador da conta, o
 *   ajustar a configuração, reprocessar)       responsável do contrato, ou a chave
 *                                              'contratos.gerenciar'
 *
 * Chave com ponto segue o truque de App\Crm\Permissao: cabe em user_permissions
 * sem migration. Owner e admin passam pelo curinga '*'.
 */
final class Permissao
{
    public const VER = 'contratos';
    public const GERENCIAR = 'contratos.gerenciar';

    public static function pode(string $chave): bool
    {
        if (strtolower((string) ($_SESSION['user_perfil'] ?? '')) === 'admin') return true;
        $perms = $_SESSION['user_permissions'] ?? [];
        if (!$perms && !empty($_SESSION['user_id'])) {
            $st = Database::getConnection()->prepare('SELECT page FROM user_permissions WHERE user_id = ?');
            $st->execute([(int) $_SESSION['user_id']]);
            $perms = $st->fetchAll(\PDO::FETCH_COLUMN);
        }
        return in_array('*', $perms, true) || in_array($chave, $perms, true);
    }

    public static function podeVer(): bool
    {
        return self::pode(self::VER) || self::pode('prospeccao');
    }

    public static function podeGerenciar(AccountContext $ctx, ?array $contrato = null): bool
    {
        if ($ctx->isOwnerOrAdmin() || self::pode(self::GERENCIAR)) return true;
        return $contrato !== null && (int) ($contrato['responsavel_id'] ?? 0) === (int) $ctx->getUserId();
    }
}
