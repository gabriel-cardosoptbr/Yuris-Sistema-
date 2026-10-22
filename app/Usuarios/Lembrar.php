<?php
declare(strict_types=1);

namespace App\Usuarios;

use App\Core\Database;

/**
 * Lembrar: o "Lembrar" da tela de login (22/10/2026).
 *
 * A sessão do PHP acaba quando o navegador fecha (cookie sem validade) e some
 * do servidor depois de uns minutos parada. No computador isso era aceitável;
 * no app instalado no celular, não: o iPhone fecha o app e a pessoa tinha de
 * digitar a senha toda vez. Com "Lembrar" marcado, o aparelho volta sozinho por
 * 30 dias.
 *
 * COMO É SEGURO
 *   · o cookie `yuris_lembrar` leva "seletor:validador" (12 e 32 bytes
 *     aleatórios). O banco guarda o seletor e só o HASH do validador: quem
 *     ler a tabela não consegue entrar;
 *   · comparação com hash_equals; seletor certo com validador errado apaga o
 *     token (alguém tentou adivinhar ou copiar um cookie antigo);
 *   · ao voltar, a sessão é montada pelo MESMO código do login
 *     (AuthController::abrirSessao), conferindo de novo usuário ativo e conta
 *     com acesso: usuário desativado ou conta suspensa não volta;
 *   · sair (logout) apaga o token do aparelho; trocar a senha apaga os de
 *     todos os aparelhos daquela pessoa;
 *   · cookie HttpOnly, SameSite=Lax e Secure no HTTPS.
 *
 * O validador não é trocado a cada uso de propósito: o app abre a página e
 * várias chamadas de API ao mesmo tempo, e a troca faria uma delas chegar com o
 * validador velho e derrubar o login. A validade é que anda: 30 dias a partir
 * do último uso.
 *
 * Quem chama a volta é o app/bootstrap.php, só quando o cookie existe.
 */
final class Lembrar
{
    public const COOKIE = 'yuris_lembrar';
    public const DIAS   = 30;

    /** Login com "Lembrar" marcado: cria o token e grava o cookie. */
    public static function criar(int $userId, int $accountId): void
    {
        if ($userId <= 0 || $accountId <= 0) return;
        try {
            $seletor   = bin2hex(random_bytes(12));
            $validador = bin2hex(random_bytes(32));
            $pdo = Database::getConnection();
            $pdo->prepare(
                'INSERT INTO login_lembrar (user_id, account_id, seletor, validador_hash, user_agent, criado_em, expira_em)
                 VALUES (?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ' . self::DIAS . ' DAY))'
            )->execute([$userId, $accountId, $seletor, hash('sha256', $validador), self::agente()]);
            $pdo->prepare('DELETE FROM login_lembrar WHERE user_id = ? AND expira_em < NOW()')->execute([$userId]);
            self::gravarCookie($seletor . ':' . $validador);
        } catch (\Throwable $e) {
            error_log('[Lembrar] criar: ' . $e->getMessage());
        }
    }

    /**
     * Chamado pelo bootstrap em toda requisição que traz o cookie: se a sessão
     * não tem usuário, entra pelo token. Abre a sessão só para olhar e fecha em
     * seguida; a página abre de novo do jeito dela.
     */
    public static function restaurarSePreciso(): void
    {
        $cookie = (string) ($_COOKIE[self::COOKIE] ?? '');
        if ($cookie === '' || PHP_SAPI === 'cli' || headers_sent()) return;
        if (!preg_match('/^([0-9a-f]{24}):([0-9a-f]{64})$/', $cookie, $m)) {
            self::apagarCookie();
            return;
        }
        try {
            $abri = false;
            if (session_status() === PHP_SESSION_NONE) {
                AuthController::ensureSessionStarted();
                $abri = true;
            }
            if (empty($_SESSION['user_id'])) self::restaurar($m[1], $m[2]);
            if ($abri) session_write_close();
        } catch (\Throwable $e) {
            error_log('[Lembrar] restaurar: ' . $e->getMessage());
        }
    }

    /** Logout: apaga o token deste aparelho. */
    public static function revogarAtual(): void
    {
        $cookie = (string) ($_COOKIE[self::COOKIE] ?? '');
        if ($cookie === '') return;
        try {
            [$seletor] = explode(':', $cookie, 2);
            Database::getConnection()->prepare('DELETE FROM login_lembrar WHERE seletor = ?')->execute([$seletor]);
        } catch (\Throwable $e) {
            error_log('[Lembrar] revogar: ' . $e->getMessage());
        }
        self::apagarCookie();
    }

    /** Senha trocada ou usuário desativado: nenhum aparelho volta sozinho. */
    public static function revogarUsuario(int $userId): void
    {
        try {
            Database::getConnection()->prepare('DELETE FROM login_lembrar WHERE user_id = ?')->execute([$userId]);
        } catch (\Throwable $e) {
            error_log('[Lembrar] revogarUsuario: ' . $e->getMessage());
        }
    }

    private static function restaurar(string $seletor, string $validador): void
    {
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT id, user_id, account_id, validador_hash, expira_em < NOW() AS vencido FROM login_lembrar WHERE seletor = ? LIMIT 1');
        $st->execute([$seletor]);
        $tk = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$tk) { self::apagarCookie(); return; }

        $apagar = static function () use ($pdo, $tk): void {
            $pdo->prepare('DELETE FROM login_lembrar WHERE id = ?')->execute([$tk['id']]);
            self::apagarCookie();
        };
        if ((int) $tk['vencido'] === 1
            || !hash_equals((string) $tk['validador_hash'], hash('sha256', $validador))) {
            $apagar();
            return;
        }

        $st = $pdo->prepare('SELECT * FROM users WHERE id = ? AND account_id = ? LIMIT 1');
        $st->execute([$tk['user_id'], $tk['account_id']]);
        $user = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$user || !empty($user['deleted_at']) || (isset($user['status']) && $user['status'] !== 'active')) {
            $apagar();
            return;
        }
        $st = $pdo->prepare('SELECT id, nome, tipo, status, plano FROM accounts WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        $st->execute([$tk['account_id']]);
        $account = $st->fetch(\PDO::FETCH_ASSOC);
        // Mesma regra do login (AuthController::attemptLogin).
        if (!$account || !in_array($account['status'], ['active', 'trial', 'overdue'], true)) {
            $apagar();
            return;
        }

        AuthController::abrirSessao($user, $account);
        $pdo->prepare('UPDATE login_lembrar SET usado_em = NOW(), expira_em = DATE_ADD(NOW(), INTERVAL ' . self::DIAS . ' DAY) WHERE id = ?')
            ->execute([$tk['id']]);
        self::gravarCookie($seletor . ':' . $validador);
    }

    private static function gravarCookie(string $valor): void
    {
        if (headers_sent()) return;
        setcookie(self::COOKIE, $valor, [
            'expires'  => time() + self::DIAS * 86400,
            'path'     => '/',
            'secure'   => self::https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $valor;
    }

    private static function apagarCookie(): void
    {
        unset($_COOKIE[self::COOKIE]);
        if (headers_sent()) return;
        setcookie(self::COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => self::https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function https(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    private static function agente(): ?string
    {
        $ua = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        return $ua === '' ? null : mb_substr($ua, 0, 255);
    }
}
