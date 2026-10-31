<?php
/**
 * GET /api/whatsapp/sdr_vendedor.php — o n8n pergunta quem assina a abertura.
 *
 * Usado pelo disparo da Inovaize (31/10/2026, pedido do dono: "esse campo a gente
 * sempre vai puxar do vendedor"). A Roleta (sdr_roleta.php) só atende a conta do
 * token global da prospecção (a Fleetiflow); este endpoint serve as outras contas
 * da edição CRM, uma por token próprio, e só LÊ.
 *
 *   Cabeçalho: X-Inovaize-Token  (whatsapp_settings `sdr_token` da conta)
 *   Query:     instancia = nome da instância na Evolution (opcional; sem ela vale a
 *              primeira instância da conta)
 *   200 { ok:true, user_id, atendente, vendedor }
 *        vendedor = primeiro nome do cadastro (VendedorDoLead), vai no lugar de {vendedor}
 *        atendente = nome completo do cadastro
 *   401 token   404 sem vendedor definido para o número
 *
 * Quem é o vendedor: o dono das conversas NOVAS do número (whatsapp_instances.
 * dono_novas_user_id, DonoDaConversa); sem ele, o responsável do número; sem ele, o
 * especialista padrão da conta (configuracoes.sdr.especialista_padrao). Trocou o
 * dono no CRM, a próxima abertura já sai com o nome novo.
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Database;
use App\WhatsAppAgente\VendedorDoLead;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

$recebido = (string)($_SERVER['HTTP_X_INOVAIZE_TOKEN'] ?? '');
if ($recebido === '') { http_response_code(401); echo json_encode(['error' => 'Token inválido']); exit; }

try {
    $pdo = Database::getConnection();
    $conta = 0;
    foreach ($pdo->query("SELECT account_id, config_value FROM whatsapp_settings WHERE config_key = 'sdr_token'")->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        if ((string)$r['config_value'] !== '' && hash_equals((string)$r['config_value'], $recebido)) { $conta = (int)$r['account_id']; break; }
    }
    if ($conta <= 0) { http_response_code(401); echo json_encode(['error' => 'Token inválido']); exit; }

    $instancia = trim((string)($_GET['instancia'] ?? ''));
    $sql = 'SELECT dono_novas_user_id, responsavel_user_id FROM whatsapp_instances WHERE account_id = ?'
         . ($instancia !== '' ? ' AND instance_name = ?' : '') . ' ORDER BY id LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute($instancia !== '' ? [$conta, $instancia] : [$conta]);
    $inst = $st->fetch(\PDO::FETCH_ASSOC) ?: [];

    $uid = (int)($inst['dono_novas_user_id'] ?? 0) ?: (int)($inst['responsavel_user_id'] ?? 0);
    if ($uid <= 0) {
        $st = $pdo->prepare('SELECT configuracoes FROM accounts WHERE id = ? LIMIT 1');
        $st->execute([$conta]);
        $cfg = json_decode((string)$st->fetchColumn(), true);
        $uid = (int)($cfg['sdr']['especialista_padrao'] ?? 0);
    }

    $v = $uid > 0 ? VendedorDoLead::doUsuario($conta, $uid) : null;
    if ($v === null || $v['vendedor'] === '') { http_response_code(404); echo json_encode(['error' => 'Sem vendedor definido para este número']); exit; }

    echo json_encode(['ok' => true, 'user_id' => $v['user_id'], 'atendente' => $v['nome'], 'vendedor' => $v['vendedor']], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('[sdr_vendedor] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno']);
}
