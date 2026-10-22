<?php
/**
 * Migration 167: "Lembrar" no login e avisos no celular (22/10/2026).
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Três tabelas novas, nenhuma alteração nas existentes:
 *
 *   login_lembrar    o "Lembrar" da tela de login. Um aparelho marcado fica
 *                    conectado por 30 dias sem a sessão do PHP (que acaba ao
 *                    fechar o navegador). Guarda só o HASH do validador: quem
 *                    lê o banco não consegue entrar. Ver App\Usuarios\Lembrar.
 *   push_inscricoes  um aparelho (celular ou navegador) que aceitou receber
 *                    avisos: o endereço do serviço de push do navegador e as
 *                    chaves públicas dele. Ver App\Notificacoes\Push.
 *   chaves_sistema   segredo do sistema gerado por ele mesmo; hoje só o par de
 *                    chaves VAPID do push, com a privada cifrada (App\Core\Crypto).
 *
 * IDEMPOTENTE.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_167.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_167.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo 'Migration 167' . ($dryRun ? ' (dry-run)' : '') . PHP_EOL;

$tabelas = [
    'login_lembrar' => "CREATE TABLE login_lembrar (
        id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id         INT NOT NULL,
        account_id      INT NOT NULL,
        seletor         CHAR(24) NOT NULL,
        validador_hash  CHAR(64) NOT NULL,
        user_agent      VARCHAR(255) NULL,
        criado_em       DATETIME NOT NULL,
        usado_em        DATETIME NULL,
        expira_em       DATETIME NOT NULL,
        UNIQUE KEY uk_login_lembrar_seletor (seletor),
        KEY idx_login_lembrar_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'push_inscricoes' => "CREATE TABLE push_inscricoes (
        id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        account_id      INT NOT NULL,
        user_id         INT NOT NULL,
        endpoint        TEXT NOT NULL,
        endpoint_hash   CHAR(64) NOT NULL,
        p256dh          VARCHAR(120) NOT NULL,
        auth            VARCHAR(40) NOT NULL,
        user_agent      VARCHAR(255) NULL,
        criado_em       DATETIME NOT NULL,
        ultimo_envio_em DATETIME NULL,
        falhas          INT NOT NULL DEFAULT 0,
        UNIQUE KEY uk_push_endpoint (endpoint_hash),
        KEY idx_push_user (user_id, account_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'chaves_sistema' => "CREATE TABLE chaves_sistema (
        nome       VARCHAR(64) NOT NULL PRIMARY KEY,
        valor      TEXT NOT NULL,
        criado_em  DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

$existe = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
foreach ($tabelas as $nome => $sql) {
    $existe->execute([$nome]);
    if ((int) $existe->fetchColumn() > 0) {
        echo "  = $nome já existe\n";
        continue;
    }
    echo "  + $nome\n";
    if (!$dryRun) $pdo->exec($sql);
}

echo $dryRun ? "Nada gravado (dry-run).\n" : "Pronto.\n";
