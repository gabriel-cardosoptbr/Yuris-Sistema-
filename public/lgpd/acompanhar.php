<?php
/**
 * /lgpd/acompanhar.php?token=X — Página pública de acompanhamento.
 *
 * Titular consulta status da sua solicitação sem precisar criar conta.
 * O token foi gerado em /lgpd/solicitar.php e enviado ao DPO + retornado
 * ao titular como link único.
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
    $corpo = '<div style="padding:16px;background:rgba(239,68,68,.13);border:1px solid rgba(239,68,68,.30);color:#fca5a5;border-radius:8px">Token inválido. Verifique o link.</div>';
} elseif (!$req) {
    $corpo = '<div style="padding:16px;background:rgba(239,68,68,.13);border:1px solid rgba(239,68,68,.30);color:#fca5a5;border-radius:8px">Solicitação não encontrada. Verifique o link ou abra uma nova solicitação em <a href="/sistema_vendas/public/lgpd/solicitar.php" style="color:#7eb8f7">/lgpd/solicitar.php</a>.</div>';
} else {
    $statusLabel = $statusLabels[$req['status']] ?? $req['status'];
    $tipoLabel   = $tipoLabels[$req['tipo']] ?? $req['tipo'];
    $statusColor = match($req['status']) {
        'concluido'  => '#10b981',
        'rejeitado'  => '#ef4444',
        'expirado'   => '#94a3b8',
        default      => '#f59e0b',
    };

    $eventosHtml = '';
    foreach ($eventos as $e) {
        $eventosHtml .= '<div style="padding:10px 0;border-bottom:1px solid rgba(96,165,250,.10)">';
        $eventosHtml .= '<div style="font-size:.85rem;color:#fff;font-weight:600">' . htmlspecialchars($e['evento']) . '</div>';
        if (!empty($e['observacao'])) {
            $eventosHtml .= '<div style="font-size:.82rem;color:#cbd5e1;margin-top:3px">' . htmlspecialchars($e['observacao']) . '</div>';
        }
        $eventosHtml .= '<div style="font-size:.74rem;color:#94a3b8;margin-top:3px">' . htmlspecialchars($e['created_at']) . '</div>';
        $eventosHtml .= '</div>';
    }
    if (!$eventosHtml) $eventosHtml = '<div style="padding:10px 0;color:#94a3b8;font-size:.85rem">Sem eventos registrados ainda.</div>';

    $respHtml = '';
    if (!empty($req['resposta'])) {
        $respHtml = '<h3 style="margin-top:24px">Resposta</h3>'
                  . '<div style="background:rgba(16,185,129,.07);border:1px solid rgba(16,185,129,.25);padding:14px;border-radius:8px;white-space:pre-wrap">'
                  . htmlspecialchars($req['resposta']) . '</div>';
    }
    if (!empty($req['motivo_rejeicao'])) {
        $respHtml .= '<h3 style="margin-top:24px;color:#fca5a5">Motivo da rejeição</h3>'
                   . '<div style="background:rgba(239,68,68,.07);border:1px solid rgba(239,68,68,.25);padding:14px;border-radius:8px;white-space:pre-wrap">'
                   . htmlspecialchars($req['motivo_rejeicao']) . '</div>';
    }

    $corpo = '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;margin-bottom:18px">
      <div>
        <div style="font-size:.78rem;color:#94a3b8">Solicitação #' . (int)$req['id'] . '</div>
        <div style="font-size:1.1rem;color:#fff;font-weight:600;margin-top:3px">' . htmlspecialchars($tipoLabel) . '</div>
        <div style="font-size:.85rem;color:#cbd5e1;margin-top:5px">' . htmlspecialchars($req['titular_nome']) . ' &middot; ' . htmlspecialchars($req['titular_email']) . '</div>
      </div>
      <div style="padding:6px 14px;border-radius:999px;background:rgba(255,255,255,.05);border:1px solid ' . $statusColor . '40;color:' . $statusColor . ';font-weight:600;font-size:.82rem">' . htmlspecialchars($statusLabel) . '</div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;font-size:.82rem;color:#94a3b8;margin-bottom:20px">
      <div>Recebida: <strong style="color:#cbd5e1">' . htmlspecialchars($req['recebido_em']) . '</strong></div>
      <div>Prazo: <strong style="color:#cbd5e1">' . htmlspecialchars($req['prazo_resposta']) . '</strong></div>
      ' . ($req['respondido_em'] ? '<div>Respondida em: <strong style="color:#cbd5e1">' . htmlspecialchars($req['respondido_em']) . '</strong></div>' : '') . '
    </div>
    ' . (!empty($req['descricao']) ? '<h3>Sua descrição</h3><div style="background:rgba(96,165,250,.05);padding:12px;border-radius:8px;white-space:pre-wrap;color:#cbd5e1">' . htmlspecialchars($req['descricao']) . '</div>' : '') . '
    <h3 style="margin-top:24px">Histórico</h3>
    <div style="background:rgba(8,12,24,.4);border:1px solid rgba(96,165,250,.10);padding:6px 14px;border-radius:8px">' . $eventosHtml . '</div>
    ' . $respHtml;
}

$LEGAL_PAGE = [
    'titulo'     => 'Acompanhar Solicitação LGPD',
    'descricao'  => 'Status e histórico da sua solicitação.',
    'versao'     => '',
    'corpo_html' => $corpo,
];
require __DIR__ . '/../includes/legal_page.php';
