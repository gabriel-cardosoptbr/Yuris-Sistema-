<?php
/**
 * scripts/fleetiflow_funil.php — Deixa o funil de uma conta Fleetiflow igual ao
 * do Kommo (SdrFleetiflow::ETAPAS). Idempotente: rodar de novo não duplica.
 * Colunas do seed padrão são renomeadas com os cards dentro.
 *
 * USO
 *   php scripts/fleetiflow_funil.php --account=116
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Só via CLI.\n"); }

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\WhatsAppAgente\SdrFleetiflow;

$opts = getopt('', ['account:']);
$accountId = (int)($opts['account'] ?? 0);
if ($accountId <= 0) { fwrite(STDERR, "Informe --account=ID\n"); exit(1); }
if (!SdrFleetiflow::contaUsa($accountId)) { fwrite(STDERR, "A conta #$accountId não é Fleetiflow.\n"); exit(1); }

$pdo = Database::getConnection();
$pdo->beginTransaction();
try {
    foreach (SdrFleetiflow::montarFunil($pdo, $accountId) as $linha) echo " - $linha\n";
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "ERRO (nada gravado): " . $e->getMessage() . "\n");
    exit(1);
}
