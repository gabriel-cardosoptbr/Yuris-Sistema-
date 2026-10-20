<?php
namespace App\Drive;

use App\Core\Crypto;
use App\Core\Database;

/**
 * A conta Google autorizada de cada conta do sistema (20/10/2026). Uma só por
 * conta: a conta corporativa da Inovaize, conectada por um administrador pelo
 * OAuth do Google (escopo Drive). O refresh token fica CIFRADO
 * (App\Core\Crypto, APP_ENCRYPTION_KEY); o token de acesso é renovado sozinho.
 *
 * Com GOOGLE_DRIVE_DOMINIO no .env, só e-mail daquele domínio é aceito: a
 * pasta dos clientes nunca fica ligada a uma conta pessoal por engano.
 */
final class Conexao
{
    public static function redirecionamento(string $host): string
    {
        $env = trim(\App\Core\EnvLoader::get('GOOGLE_DRIVE_REDIRECT_URI', ''));
        return $env !== '' ? $env : 'https://' . $host . '/api/drive_oauth.php';
    }

    /** Endereço de autorização do Google. O `state` vai na sessão e volta conferido. */
    public static function urlAutorizacao(string $state, string $host): string
    {
        $q = ['client_id' => Api::clientId(), 'redirect_uri' => self::redirecionamento($host), 'response_type' => 'code', 'scope' => Api::ESCOPO,
              'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $state];
        if (Api::dominio() !== '') $q['hd'] = Api::dominio();
        return Api::AUTH . '?' . http_build_query($q);
    }

    /** Recebe o código do Google, confere o domínio e grava a conexão. */
    public static function concluir(int $accountId, int $userId, string $codigo, string $host): array
    {
        $t = Api::token(['code' => $codigo, 'client_id' => Api::clientId(), 'client_secret' => Api::clientSecret(),
                         'redirect_uri' => self::redirecionamento($host), 'grant_type' => 'authorization_code']);
        if (empty($t['refresh_token'])) throw new DriveErro('O Google não devolveu a autorização permanente. Remova o acesso do Yuris na conta Google e conecte de novo.', 400);
        $sobre = Api::pedir((string) $t['access_token'], 'GET', '/about', ['fields' => 'user(emailAddress,displayName)']);
        $email = strtolower((string) ($sobre['user']['emailAddress'] ?? ''));
        if ($email === '') throw new DriveErro('Não foi possível ler o e-mail da conta Google.', 400);
        $dom = Api::dominio();
        if ($dom !== '' && !str_ends_with($email, '@' . $dom)) {
            throw new DriveErro('Use a conta corporativa (@' . $dom . '). A conta ' . $email . ' não é aceita.', 403, 'dominio');
        }
        $agora = date('Y-m-d H:i:s');
        Database::getConnection()->prepare(
            'INSERT INTO drive_contas (account_id, email, refresh_token, access_token, expira_em, conectado_por, conectado_em, page_token, ultimo_erro, erro_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL)
             ON DUPLICATE KEY UPDATE email = VALUES(email), refresh_token = VALUES(refresh_token), access_token = VALUES(access_token),
               expira_em = VALUES(expira_em), conectado_por = VALUES(conectado_por), conectado_em = VALUES(conectado_em), page_token = NULL, ultimo_erro = NULL, erro_em = NULL'
        )->execute([$accountId, $email, Crypto::encrypt((string) $t['refresh_token']), Crypto::encrypt((string) $t['access_token']),
            date('Y-m-d H:i:s', time() + (int) ($t['expires_in'] ?? 3600) - 60), $userId ?: null, $agora]);
        // Toda pasta volta a ser lida com a conta nova.
        Database::getConnection()->prepare('UPDATE drive_pastas_cliente SET precisa_sync = 1 WHERE account_id = ?')->execute([$accountId]);
        return self::estado($accountId);
    }

    public static function linha(int $accountId): ?array
    {
        $st = Database::getConnection()->prepare('SELECT * FROM drive_contas WHERE account_id = ?');
        $st->execute([$accountId]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** O que a tela mostra: configurado no servidor, conectado, com que e-mail, última sincronização e erro. */
    public static function estado(int $accountId): array
    {
        $l = self::linha($accountId);
        return [
            'configurado' => Api::configurado(), 'conectado' => (bool) $l, 'email' => $l['email'] ?? null,
            'conectado_em' => $l['conectado_em'] ?? null, 'ultima_sync' => $l['ultima_sync'] ?? null,
            'erro' => $l['ultimo_erro'] ?? null, 'erro_em' => $l['erro_em'] ?? null, 'dominio' => Api::dominio() ?: null,
        ];
    }

    /** Token de acesso válido; renova pelo refresh token quando vence. */
    public static function acesso(int $accountId): string
    {
        $l = self::linha($accountId);
        if (!$l) throw new DriveErro('O Google Drive não está conectado nesta conta.', 0, 'desconectado');
        if (!empty($l['access_token']) && $l['expira_em'] && strtotime($l['expira_em']) > time()) {
            return Crypto::decrypt((string) $l['access_token']);
        }
        try {
            $t = Api::token(['refresh_token' => Crypto::decrypt((string) $l['refresh_token']), 'client_id' => Api::clientId(),
                             'client_secret' => Api::clientSecret(), 'grant_type' => 'refresh_token']);
        } catch (DriveErro $e) {
            self::registrarErro($accountId, $e->getMessage());
            throw $e;
        }
        Database::getConnection()->prepare('UPDATE drive_contas SET access_token = ?, expira_em = ? WHERE account_id = ?')
            ->execute([Crypto::encrypt((string) $t['access_token']), date('Y-m-d H:i:s', time() + (int) ($t['expires_in'] ?? 3600) - 60), $accountId]);
        return (string) $t['access_token'];
    }

    public static function registrarErro(int $accountId, ?string $erro): void
    {
        Database::getConnection()->prepare('UPDATE drive_contas SET ultimo_erro = ?, erro_em = ? WHERE account_id = ?')
            ->execute([$erro !== null ? mb_substr($erro, 0, 500) : null, $erro !== null ? date('Y-m-d H:i:s') : null, $accountId]);
    }

    /** Desliga: apaga o token. As pastas dos clientes continuam registradas. */
    public static function desconectar(int $accountId): void
    {
        $l = self::linha($accountId);
        if ($l) {
            // Revoga no Google também (melhor esforço: falhar aqui não impede desligar).
            try { Api::revogar(Crypto::decrypt((string) $l['refresh_token'])); } catch (\Throwable $e) { /* segue */ }
        }
        Database::getConnection()->prepare('DELETE FROM drive_contas WHERE account_id = ?')->execute([$accountId]);
    }
}
