<?php
/**
 * Google Drive dos clientes (ambientes da Inovaize): lê as mudanças do Drive e
 * atualiza a lista das pastas tocadas. Ver App\Drive\Sincronizacao.
 *
 * Roda uma vez e sai. Agendar a cada 5 minutos:
 *   Linux:   * /5 * * * * docker exec yuris_app php /var/www/html/bin/drive_sync.php
 * (sem o espaço entre "*" e "/5"). Sem GOOGLE_DRIVE_CLIENT_ID no .env, sai na hora.
 *
 * DRIVE_LOG=1 imprime o resumo.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Drive\Sincronizacao;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
if ((int) $pdo->query("SELECT GET_LOCK('drive_sync', 0)")->fetchColumn() !== 1) exit(0);

try {
    $r = Sincronizacao::rodar();
    if (getenv('DRIVE_LOG')) echo json_encode($r), PHP_EOL;
} catch (\Throwable $e) {
    error_log('[drive_sync] ' . $e->getMessage());
    exit(1);
} finally {
    $pdo->query("SELECT RELEASE_LOCK('drive_sync')");
}
