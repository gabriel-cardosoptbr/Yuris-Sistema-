<?php
/**
 * /lgpd/acompanhar.php?token=X — Página pública de acompanhamento.
 *
 * Titular consulta status da sua solicitação sem precisar criar conta.
 * O token foi gerado em /lgpd/solicitar.php e enviado ao DPO + retornado
 * ao titular como link único.
 *
 * Visual: usa classes utilitárias do legal_page.php (respondem ao tema).
 */
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/LgpdRequest.php';

use App\Models\LgpdRequest;

$token = trim((string)($_GET['token'] ?? ''));
$valido = (strlen($token) === 64 && ctype_xdigit($token));
$req     = null;
$eventos = [];
if ($valido) {
    $req = LgpdRequest::findByToken($token);
    if ($req) $eventos = LgpdRequest::listEvents((int)$req['id']);
}

$statusLabels = [
    'aberto'             => 'Aberta',
    'em_analise'         => 'Em análise',
    'aguardando_titular' => 'Aguardando você',
    'concluido'          => 'Concluída',
    'rejeitado'          => 'Rejeitada',
    'expirado'           => 'Expirada',
];
$tipoLabels = [
    'confirmacao_existencia'        => 'Confirmação de existência',
    'acesso'                        => 'Acesso aos dados',
    'correcao'                      => 'Correção',
    'anonimizacao'                  => 'Anonimização',
    'bloqueio'                      => 'Bloqueio',
    'eliminacao'                    => 'Eliminação',
    'portabilidade'                 => 'Portabilidade',
    'info_compartilhamento'         => 'Informação sobre compartilhamento',
    'revogacao_consentimento'       => 'Revogação de consentimento',
    'revisao_decisao_automatizada'  => 'Revisão de decisão automatizada',
];

if (!$valido) {
    $corpo = '<div class="legal-alert legal-alert-error">Token inválido. Verifique o link.</div>';
} elseif (!$req) {
    $corpo = '<div class="legal-alert legal-alert-error">Solicitação não encontrada. Verifique o link ou abra uma nova solicitação em '
           . '<a href="/lgpd/solicitar.php">/lgpd/solicitar.php</a>.</div>';
} else {
    $statusLabel = $statusLabels[$req['status']] ?? $req['status'];
    $tipoLabel   = $tipoLabels[$req['tipo']] ?? $req['tipo'];
    // Cor do status: usada inline no pill (dinâmica), funciona em ambos os temas.
    $statusColor = match($req['status']) {
        'concluido'  => '#10b981',
        'rejeitado'  => '#ef4444',
        'expirado'   => '#94a3b8',
        default      => '#f59e0b',
    };

    // Histórico de eventos
    $eventosHtml = '';
    foreach ($eventos as $e) {
        $eventosHtml .= '<div class="legal-event">';
        $eventosHtml .= '<div class="legal-event-title">' . htmlspecialchars($e['evento']) . '</div>';
        if (!empty($e['observacao'])) {
            $eventosHtml .= '<div class="legal-event-obs">' . htmlspecialchars($e['observacao']) . '</div>';
        }
        $eventosHtml .= '<div class="legal-event-date">' . htmlspecialchars($e['created_at']) . '</div>';
        $eventosHtml .= '</div>';
    }
    if (!$eventosHtml) {
        $eventosHtml = '<div class="legal-event"><div class="legal-event-date">Sem eventos registrados ainda.</div></div>';
    }

    // Resposta e motivo de rejeição (se houver)
    $respHtml = '';
    if (!empty($req['resposta'])) {
        $respHtml = '<h3 style="margin-top:24px">Resposta</h3>'
                  . '<div class="legal-alert legal-alert-success" style="white-space:pre-wrap;margin-top:0">'
                  . htmlspecialchars($req['resposta']) . '</div>';
    }
    if (!empty($req['motivo_rejeicao'])) {
        $respHtml .= '<h3 style="margin-top:24px">Motivo da rejeição</h3>'
                   . '<div class="legal-alert legal-alert-error" style="white-space:pre-wrap;margin-top:0">'
                   . htmlspecialchars($req['motivo_rejeicao']) . '</div>';
    }

    // Cabeçalho com identificação + pill de status
    $corpo  = '<div class="legal-header-row">';
    $corpo .=   '<div>';
    $corpo .=     '<div class="legal-header-meta-label">Solicitação #' . (int)$req['id'] . '</div>';
    $corpo .=     '<div class="legal-header-meta-title">' . htmlspecialchars($tipoLabel) . '</div>';
    $corpo .=     '<div class="legal-header-meta-sub">' . htmlspecialchars($req['titular_nome']) . ' &middot; ' . htmlspecialchars($req['titular_email']) . '</div>';
    $corpo .=   '</div>';
    $corpo .=   '<div class="legal-status-pill" style="border-color:' . $statusColor . '66;color:' . $statusColor . '">' . htmlspecialchars($statusLabel) . '</div>';
    $corpo .= '</div>';

    // Datas
    $corpo .= '<div class="legal-meta-grid">';
    $corpo .=   '<div>Recebida: <strong class="legal-meta-value">' . htmlspecialchars($req['recebido_em']) . '</strong></div>';
    $corpo .=   '<div>Prazo: <strong class="legal-meta-value">' . htmlspecialchars($req['prazo_resposta']) . '</strong></div>';
    if ($req['respondido_em']) {
        $corpo .= '<div>Respondida em: <strong class="legal-meta-value">' . htmlspecialchars($req['respondido_em']) . '</strong></div>';
    }
    $corpo .= '</div>';

    // Descrição original do titular (se houver)
    if (!empty($req['descricao'])) {
        $corpo .= '<h3>Sua descrição</h3>';
        $corpo .= '<div class="legal-info-card">' . htmlspecialchars($req['descricao']) . '</div>';
    }

    // Histórico
    $corpo .= '<h3 style="margin-top:24px">Histórico</h3>';
    $corpo .= '<div class="legal-history-box">' . $eventosHtml . '</div>';

    // Resposta/rejeição (se houver)
    $corpo .= $respHtml;
}

$LEGAL_PAGE = [
    'titulo'     => 'Acompanhar Solicitação LGPD',
    'descricao'  => 'Status e histórico da sua solicitação.',
    'versao'     => '',
    'corpo_html' => $corpo,
];
require __DIR__ . '/../includes/legal_page.php';
