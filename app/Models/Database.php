<?php
namespace App\Models;

// Garante que date() sempre produza timestamps em UTC, independente do php.ini / fuso do Windows
date_default_timezone_set('UTC');

class Database
{
    private static $pdo;

    private static bool $envValidated = false;

    public static function getConnection()
    {
        if (self::$pdo) {
            // testa se a conexão ainda está ativa (MySQL server has gone away)
            try {
                self::$pdo->query('SELECT 1');
            } catch (\PDOException $e) {
                self::$pdo = null; // força reconexão
            }
        }
        if (self::$pdo) return self::$pdo;

        // ─── LGPD P1 (2D.2): valida .env em produção ─────────────────────────
        // Roda 1× por request. Em APP_ENV=production, bloqueia (503) se
        // DB_PASS vazio, CRON_TOKEN inseguro, BILLING_GATEWAY=null. Em dev
        // apenas loga warning.
        if (!self::$envValidated) {
            require_once __DIR__ . '/../Helpers/EnvLoader.php';
            \App\Helpers\EnvLoader::validateProduction();
            self::$envValidated = true;
        }

        $cfg = require __DIR__ . '/../../config/database.php';
        $dsn = "mysql:host={$cfg['host']};dbname={$cfg['dbname']};charset={$cfg['charset']}";
        try {
            self::$pdo = new \PDO($dsn, $cfg['user'], $cfg['pass'], [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_PERSISTENT         => false,
            ]);
            self::$pdo->exec("SET time_zone = '+00:00'");
            return self::$pdo;
        } catch (\PDOException $e) {
            // P1 LGPD (2D.1): NÃO vaza credenciais no erro de conexão
            error_log('[Database] DB Connection failed: ' . $e->getMessage());
            http_response_code(503);
            die(json_encode([
                'ok'    => false,
                'error' => 'Serviço temporariamente indisponível.',
            ]));
        }
    }
}
