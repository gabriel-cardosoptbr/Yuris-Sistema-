<?php
/**
 * GET/POST /api/followup.php: a aba Operação › Follow-up (App\FollowUp).
 *
 *   GET                       -> { ok, pode_editar, ligado, cadencias, colunas, proximos, historico, gatilhos, com_quem }
 *   POST (JSON, X-CSRF-Token):
 *     { acao:'salvar', id?, nome, gatilho, com_quem, etapas[], mover_para, inicio_max_dias, horario{dias[],de,ate}, passos[{espera_min,texto}] }
 *     { acao:'ligar', id, ativo:0|1 }
 *     { acao:'excluir', id }
 *     { acao:'ordenar', ids[] }
 *     { acao:'chave', ligado:0|1 }    a chave geral da conta (a mesma do botão "Follow-up" do Chat)
 *
 * Ver quem vê a Prospecção vê (menos o vendedor da edição CRM, que só enxerga os
 * leads dele, e a fila é da conta inteira); mudar é de dono/admin, porque decide o que o
 * sistema manda sozinho para os leads. Tudo pela conta da sessão, nunca do corpo.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\AccountContext;
use App\FollowUp\Cadencia;
use App\FollowUp\Envio;
use App\FollowUp\Fatos;
use App\FollowUp\Regra;
use App\WhatsAppAgente\AutomacaoSdr;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }
$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();
$accountId = (int) $ctx->getAccountId();
$userId = (int) $ctx->getUserId();

$perfilAdmin = strtolower((string) ($_SESSION['user_perfil'] ?? '')) === 'admin';
$perms = (array) ($_SESSION['user_permissions'] ?? []);
if (!$perfilAdmin && !in_array('*', $perms, true) && !in_array('prospeccao', $perms, true)) {
    http_response_code(403); echo json_encode(['error' => 'Sem acesso à Prospecção.']); exit;
}
// O vendedor da edição CRM só vê os leads dele; a fila e o histórico são da conta inteira.
if ($ctx->vendedorCrm()) { http_response_code(403); echo json_encode(['error' => 'O follow-up é configurado pela administração.']); exit; }
$podeEditar = $ctx->isOwnerOrAdmin() || $perfilAdmin;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $agora = time();
        $cads = Cadencia::daConta($accountId);
        $colunas = Cadencia::colunas($accountId);
        $nomeColuna = array_column($colunas, 'nome', 'id');

        $proximos = [];
        $ativas = array_values(array_filter($cads, fn($c) => $c['ativo']));
        if ($ativas) {
            $agendados = Envio::agendados($accountId, $ativas, $agora);
            $est = Envio::estimar($agendados, $agora); // ordem e faixa de saída, com a fila do número
            usort($agendados, fn($a, $b) => [$est[$a['card_id']]['de'] ?? 0, $est[$a['card_id']]['ordem'] ?? 0] <=> [$est[$b['card_id']]['de'] ?? 0, $est[$b['card_id']]['ordem'] ?? 0]);
            foreach ($agendados as $i) {
                $f = $i['fatos'];
                $proximos[] = [
                    'card_id' => $i['card_id'],
                    'lead'    => $f['empresa'] !== '' ? $f['empresa'] : ($f['nome'] !== '' ? $f['nome'] : 'Lead sem nome'),
                    'etapa'   => $nomeColuna[$f['etapa']] ?? '',
                    'jid'     => $f['chat']['remote_jid'],
                    'numero'  => $f['chat']['instance_id'],
                ] + Fatos::resumo($i['cadencia'], $i['situacao'], $agora, $est[$i['card_id']] ?? null, $i['falha'] ?? null);
            }
        }
        $historico = array_map(fn($h) => [
            'id' => (int) $h['id'], 'lead' => (string) $h['lead'], 'cadencia' => (string) ($h['cadencia'] ?? 'Cadência excluída'),
            'passo' => (int) $h['passo'], 'status' => (string) $h['status'], 'erro' => $h['erro'], 'texto' => (string) $h['texto'],
            'card_id' => $h['card_id'] !== null ? (int) $h['card_id'] : null, 'tentativas' => (int) $h['tentativas'],
            'quando' => date('c', (int) strtotime((string) ($h['enviado_em'] ?: $h['created_at']))),
        ], Envio::historico($accountId));

        echo json_encode([
            'ok' => true, 'pode_editar' => $podeEditar, 'ligado' => AutomacaoSdr::estado($accountId)['followup'],
            'cadencias' => $cads, 'colunas' => $colunas, 'proximos' => $proximos, 'historico' => $historico,
            'gatilhos' => Regra::GATILHOS, 'com_quem' => Regra::COM_QUEM, 'agora' => date('c', $agora),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }
    $csrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($csrf === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) { http_response_code(400); echo json_encode(['error' => 'Sessão expirada. Recarregue a página.']); exit; }
    if (!$podeEditar) { http_response_code(403); echo json_encode(['error' => 'Só o dono ou um administrador muda o follow-up.']); exit; }

    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int) ($in['id'] ?? 0);
    switch ((string) ($in['acao'] ?? '')) {
        case 'salvar':
            $r = Cadencia::salvar($accountId, $in, $userId, $id ?: null);
            if (!$r['ok']) http_response_code(422);
            echo json_encode($r, JSON_UNESCAPED_UNICODE);
            break;
        case 'ligar':
            $ok = Cadencia::ligar($id, $accountId, !empty($in['ativo']), $userId);
            if (!$ok) http_response_code(404);
            echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => 'Cadência não encontrada.']);
            break;
        case 'excluir':
            $ok = Cadencia::excluir($id, $accountId, $userId);
            if (!$ok) http_response_code(404);
            echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => 'Cadência não encontrada.']);
            break;
        case 'ordenar':
            Cadencia::ordenar($accountId, (array) ($in['ids'] ?? []));
            echo json_encode(['ok' => true]);
            break;
        case 'chave':
            AutomacaoSdr::definir($accountId, 'followup', !empty($in['ligado']), $userId);
            echo json_encode(['ok' => true, 'ligado' => AutomacaoSdr::estado($accountId)['followup']]);
            break;
        default:
            http_response_code(422);
            echo json_encode(['error' => 'Ação desconhecida.']);
    }
} catch (\Throwable $e) {
    error_log('[api/followup] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno']);
}
