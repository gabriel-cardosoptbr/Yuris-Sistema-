<?php
/**
 * Cobranças dos clientes (edição CRM): gera as cobranças que faltam dos valores
 * combinados (mensalidades e anuidades) até o fim do mês seguinte.
 * Ver App\Clientes\CobrancaDoCliente.
 *
 * Roda e sai. Agendar uma vez por dia (a geração é idempotente):
 *   Linux: 10 6 * * * docker exec -u www-data yuris_app php /var/www/html/bin/cobrancas_clientes_worker.php
 *
 * COBRANCAS_LOG=1 imprime o resumo.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../app/bootstrap.php';

use App\Clientes\CobrancaDoCliente;
use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
if ((int) $pdo->query("SELECT GET_LOCK('cobrancas_clientes_worker', 0)")->fetchColumn() !== 1) exit(0);
try {
    $n = CobrancaDoCliente::gerarTodas();
    if (getenv('COBRANCAS_LOG') === '1') echo date('c') . " cobrancas_criadas={$n}" . PHP_EOL;
} catch (\Throwable $e) {
    error_log('[cobrancas_clientes_worker] ' . $e->getMessage());
    exit(1);
} finally {
    $pdo->query("SELECT RELEASE_LOCK('cobrancas_clientes_worker')");
}
