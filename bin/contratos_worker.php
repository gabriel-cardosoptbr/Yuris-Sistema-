<?php
/**
 * Contratos (edição CRM): expira contratos com prazo vencido e refaz a geração
 * dos documentos finais que falhou. Ver App\Contratos\Contrato e Finalizacao.
 *
 * Roda um lote e sai. Agendar a cada minuto:
 *   Linux:   * * * * * docker exec yuris_app php /var/www/html/bin/contratos_worker.php
 *
 * CONTRATOS_LOG=1 imprime o resumo.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../app/bootstrap.php';

use App\Contratos\Contrato;
use App\Contratos\Finalizacao;
use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

if ((int) $pdo->query("SELECT GET_LOCK('contratos_worker', 0)")->fetchColumn() !== 1) exit(0);

try {
    $expirados = Contrato::expirarVencidos();
    $res = ['ok' => 0, 'erro' => 0, 'outros' => 0];
    foreach (Finalizacao::pendentes() as $id) {
        $r = Finalizacao::processar($id);
        $res[$r === 'ok' ? 'ok' : ($r === 'erro' ? 'erro' : 'outros')]++;
    }
    // Sessões e códigos vencidos há mais de 7 dias não servem para nada: só a sessão (sem evidência) sai.
    $pdo->prepare('DELETE FROM crm_contrato_sessoes WHERE expira_em < ? AND canal_confirmado_em IS NULL')
        ->execute([gmdate('Y-m-d H:i:s', time() - 7 * 86400)]);
    if (getenv('CONTRATOS_LOG') === '1') {
        echo date('c') . " expirados={$expirados} finalizados={$res['ok']} falhas={$res['erro']}" . PHP_EOL;
    }
} catch (\Throwable $e) {
    error_log('[contratos_worker] ' . $e->getMessage());
    exit(1);
} finally {
    $pdo->query("SELECT RELEASE_LOCK('contratos_worker')");
}
