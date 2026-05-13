<?php
namespace App\Models;

// Garante que date() sempre produza timestamps em UTC, independente do php.ini / fuso do Windows
date_default_timezone_set('UTC');

class Database
{
    private static $pdo;

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
            die('DB Connection failed: ' . $e->getMessage());
        }
    }
}
