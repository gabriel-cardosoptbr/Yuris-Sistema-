<?php
namespace App\Contratos;

use App\Core\Database;
use App\Master\Marca;

/**
 * O convite: o link individual de UM participante para UMA versão do contrato.
 *
 * - O token tem 256 bits e só o SHA-256 dele fica no banco. Por isso o link só
 *   aparece na hora em que é gerado: "copiar de novo" gera outro e revoga o anterior.
 * - O token vai no FRAGMENTO do endereço (https://.../assinar#t=...). Navegador não
 *   manda fragmento ao servidor: não aparece em log do nginx nem do Apache, não vaza
 *   no Referer, e a prévia de link do WhatsApp (que só faz GET) não chega a vê-lo.
 *   A página troca o token por uma sessão curta num POST e apaga o fragmento.
 * - Validade, revogação (novo link, nova versão, recusa, cancelamento, expiração) e
 *   uso único (assinou, o convite morre).
 */
final class Convite
{
    /**
     * @return array{id:int, uuid:string, token:string, expira_em:string}
     */
    public static function emitir(int $accountId, int $contratoId, int $participanteId, int $versaoId, ?int $userId, int $validadeDias, ?string $prazoContrato): array
    {
        $pdo = Database::getConnection();
        self::revogarDoParticipante($participanteId, 'novo_convite');
        $token = Cripto::token();
        $uuid = Cripto::uuid();
        $expira = Tempo::daquiA(max(1, $validadeDias) * 86400);
        if ($prazoContrato !== null && $prazoContrato !== '' && $prazoContrato < $expira) $expira = $prazoContrato;
        $pdo->prepare(
            'INSERT INTO crm_contrato_convites (uuid, account_id, contrato_id, participante_id, versao_id, token_hash, criado_em, expira_em, criado_por)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$uuid, $accountId, $contratoId, $participanteId, $versaoId, Cripto::hashToken($token), Tempo::agora(), $expira, $userId]);
        return ['id' => (int) $pdo->lastInsertId(), 'uuid' => $uuid, 'token' => $token, 'expira_em' => $expira];
    }

    public static function revogarDoParticipante(int $participanteId, string $motivo): int
    {
        $st = Database::getConnection()->prepare(
            'UPDATE crm_contrato_convites SET revogado_em = ?, revogado_motivo = ?
              WHERE participante_id = ? AND revogado_em IS NULL AND usado_em IS NULL'
        );
        $st->execute([Tempo::agora(), $motivo, $participanteId]);
        return $st->rowCount();
    }

    public static function revogarDoContrato(int $contratoId, string $motivo): int
    {
        $st = Database::getConnection()->prepare(
            'UPDATE crm_contrato_convites SET revogado_em = ?, revogado_motivo = ?
              WHERE contrato_id = ? AND revogado_em IS NULL AND usado_em IS NULL'
        );
        $st->execute([Tempo::agora(), $motivo, $contratoId]);
        return $st->rowCount();
    }

    /** Convite vivo pelo token (sem consumir nada). */
    public static function peloToken(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) return null;
        $st = Database::getConnection()->prepare('SELECT * FROM crm_contrato_convites WHERE token_hash = ?');
        $st->execute([Cripto::hashToken($token)]);
        $c = $st->fetch(\PDO::FETCH_ASSOC);
        return $c ?: null;
    }

    /** Endereço público da página de assinatura da conta: domínio da marca, ou o do pedido, ou APP_URL. */
    public static function enderecoBase(int $accountId): string
    {
        $st = Database::getConnection()->prepare('SELECT * FROM accounts WHERE id = ?');
        $st->execute([$accountId]);
        $acc = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
        $marca = $acc ? Marca::daConta($acc) : [];
        if (!empty($marca['dominio'])) return 'https://' . $marca['dominio'];
        $host = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '');
        if ($host !== '' && preg_match('/^[a-z0-9.-]+(:\d+)?$/i', $host)) {
            $https = (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || (($_SERVER['HTTPS'] ?? '') === 'on');
            $local = str_starts_with($host, 'localhost') || str_starts_with($host, '127.0.0.1');
            return ($https || !$local ? 'https://' : 'http://') . $host;
        }
        return rtrim(\App\Core\EnvLoader::get('APP_URL', 'https://crm.fleetiflow.com.br'), '/');
    }

    public static function link(int $accountId, string $token): string
    {
        return self::enderecoBase($accountId) . '/assinar#t=' . $token;
    }
}
