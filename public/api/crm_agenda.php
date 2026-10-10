<?php
/**
 * Agenda da próxima interação com o lead (edição CRM). Ver App\Prospeccao\AgendaDoLead.
 *
 *   GET  ?card_id=N   agendamentos do lead + quadros, equipe e se há WhatsApp para a mensagem
 *   GET  ?hoje=1      as ações do dia da pessoa logada (o aviso ao entrar)
 *   POST {acao:'criar', card_id, tipo, quando, assunto?, observacao?, lembrete_min?,
 *         board_id?, responsavel_id?, mensagem?}
 *   POST {acao:'cancelar'|'concluir'|'reenviar', id}
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Prospeccao\AgendaDoLead;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function agResponder(array $corpo, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    exit;
}

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();
if ($ctx->getProduto() !== 'fleetiflow') agResponder(['ok' => false, 'error' => 'Disponível só na edição CRM.'], 403);

$userId  = (int) $ctx->getUserId();
$isAdmin = $ctx->isOwnerOrAdmin();
$accProsp = $ctx->getAccessibleAccountIds('prospeccao');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!empty($_GET['hoje'])) {
            agResponder(['ok' => true, 'data' => AgendaDoLead::doDia($userId, $ctx->getAccessibleAccountIds('tarefas'))]);
        }
        $card = AgendaDoLead::cardDaConta((int) ($_GET['card_id'] ?? 0), $accProsp);
        if (!$card) agResponder(['ok' => false, 'error' => 'Lead não encontrado.'], 404);
        $acc     = (int) $card['account_id'];
        $quadros = AgendaDoLead::quadros($userId, $acc, $isAdmin);
        $destino = AgendaDoLead::destinoDoWhatsapp($card);
        agResponder(['ok' => true, 'data' => [
            'agendamentos'  => AgendaDoLead::doCard((int) $card['id'], $acc),
            'quadros'       => $quadros,
            'quadro_padrao' => AgendaDoLead::quadroPadrao($quadros),
            'equipe'        => AgendaDoLead::equipe($acc),
            'eu'            => $userId,
            'whatsapp'      => $destino ? ['numero' => $destino['numero']] : null,
            'tipos'         => AgendaDoLead::TIPOS,
        ]]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') agResponder(['ok' => false, 'error' => 'Método não suportado.'], 405);

    $in   = json_decode(file_get_contents('php://input'), true) ?? [];
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['csrf_token'] ?? '');
    if (!$csrf || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) $csrf)) {
        agResponder(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.'], 403);
    }

    $acao = (string) ($in['acao'] ?? '');
    if ($acao === 'criar') {
        $card = AgendaDoLead::cardDaConta((int) ($in['card_id'] ?? 0), $accProsp);
        if (!$card) agResponder(['ok' => false, 'error' => 'Lead não encontrado.'], 404);
        $ctx->assertCanWrite('card', (int) $card['id']);
        try {
            $r = AgendaDoLead::criar($card, $in, $userId, $isAdmin);
        } catch (\InvalidArgumentException $e) {
            agResponder(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        agResponder(['ok' => true, 'data' => $r]);
    }

    if (in_array($acao, ['cancelar', 'concluir', 'reenviar'], true)) {
        // O agendamento é da conta do lead: procura em cada conta acessível.
        $id = (int) ($in['id'] ?? 0);
        $feito = false;
        foreach ($accProsp as $acc) {
            if (!AgendaDoLead::buscar($id, (int) $acc)) continue;
            $feito = match ($acao) {
                'cancelar' => AgendaDoLead::cancelar($id, (int) $acc, $userId),
                'concluir' => AgendaDoLead::concluir($id, (int) $acc, $userId),
                'reenviar' => AgendaDoLead::reenviar($id, (int) $acc),
            };
            break;
        }
        if (!$feito) agResponder(['ok' => false, 'error' => $acao === 'reenviar' ? 'Essa mensagem não pode ser reenviada.' : 'Agendamento não encontrado.'], 404);
        agResponder(['ok' => true]);
    }

    agResponder(['ok' => false, 'error' => 'Ação desconhecida.'], 400);
} catch (\Throwable $e) {
    error_log('[crm_agenda] ' . $e->getMessage());
    agResponder(['ok' => false, 'error' => 'Não foi possível concluir. Tente de novo.'], 500);
}
