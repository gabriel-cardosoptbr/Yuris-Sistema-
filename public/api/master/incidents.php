<?php
/**
 * /api/master/incidents.php — Painel Master: incidentes de segurança (LGPD Art. 48).
 *
 * Acesso: super_admin com sessão master_mode.
 *
 * Endpoints:
 *   GET                              → lista (filtros: status, severidade, tipo, q, account_id, abertos)
 *   GET ?id=N                        → detalhe + timeline de eventos + public_report
 *   GET ?counts=1                    → contagens p/ badge (total, abertos, criticos_abertos, notificacao_pendente)
 *
 *   POST                             → cria incidente (body JSON)
 *   PATCH                            → atualiza (body: { id, ...campos })
 *
 *   POST ?action=notify_anpd         → marca notificacao_anpd_em (body: { id, protocolo? })
 *   POST ?action=notify_holders      → marca notificacao_titulares_em (body: { id, canal? })
 *   POST ?action=add_event           → evento manual (body: { id, tipo_evento, descricao?, payload? })
 *
 * Toda mutação é registrada em master_audit_log via MasterAudit::log().
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/Account.php';
require_once __DIR__ . '/../../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../../app/Models/SecurityIncident.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Helpers/ApiResponse.php';
require_once __DIR__ . '/../../../app/Helpers/MasterAudit.php';
require_once __DIR__ . '/../../../app/Helpers/RequestId.php';

use App\Helpers\AccountContext;
use App\Helpers\ApiResponse;
use App\Helpers\MasterAudit;
use App\Models\SecurityIncident;

session_start();
$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();
if (empty($_SESSION['master_mode'])) {
    ApiResponse::forbidden('Acesso somente pelo Painel Master.');
}

$method = $_SERVER['REQUEST_METHOD'];
$userId = $ctx->getUserId();

// ─── GET ─────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    if (!empty($_GET['counts'])) {
        ApiResponse::ok(SecurityIncident::countByStatus());
    }

    if (!empty($_GET['id'])) {
        $id  = (int)$_GET['id'];
        $inc = SecurityIncident::findById($id);
        if (!$inc) ApiResponse::notFound('Incidente não encontrado.');
        ApiResponse::ok([
            'incident'      => $inc,
            'eventos'       => SecurityIncident::listEvents($id),
            'public_report' => SecurityIncident::generatePublicReport($id),
        ]);
    }

    $list = SecurityIncident::listForMaster([
        'status'     => $_GET['status']     ?? null,
        'severidade' => $_GET['severidade'] ?? null,
        'tipo'       => $_GET['tipo']       ?? null,
        'q'          => $_GET['q']          ?? null,
        'account_id' => $_GET['account_id'] ?? null,
        'abertos'    => $_GET['abertos']    ?? null,
    ]);
    ApiResponse::ok($list);
}

// ─── Validações CSRF (mutações) ──────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        ApiResponse::badRequest('CSRF inválido');
    }
}

// ─── POST ?action=notify_anpd ────────────────────────────────────────────────
if ($method === 'POST' && ($_GET['action'] ?? null) === 'notify_anpd') {
    $id        = (int)($input['id'] ?? 0);
    $protocolo = trim((string)($input['protocolo'] ?? ''));
    if (!$id) ApiResponse::badRequest('id obrigatório');
    $inc = SecurityIncident::findById($id);
    if (!$inc) ApiResponse::notFound();
    if (!empty($inc['notificacao_anpd_em'])) {
        ApiResponse::badRequest('ANPD já notificada em ' . $inc['notificacao_anpd_em']);
    }

    SecurityIncident::markNotifiedAnpd($id, $protocolo, $userId);
    MasterAudit::log('incident.notify_anpd', 'incident', $id,
        "Notificação ANPD registrada — incidente #{$id}",
        ['protocolo' => $protocolo ?: null]);
    ApiResponse::ok(['notified' => true, 'when' => date('Y-m-d H:i:s')]);
}

// ─── POST ?action=notify_holders ─────────────────────────────────────────────
if ($method === 'POST' && ($_GET['action'] ?? null) === 'notify_holders') {
    $id    = (int)($input['id'] ?? 0);
    $canal = trim((string)($input['canal'] ?? 'email'));
    $canaisOk = ['email', 'telefone', 'aviso_publico', 'in_app'];
    if (!in_array($canal, $canaisOk, true)) $canal = 'email';
    if (!$id) ApiResponse::badRequest('id obrigatório');
    $inc = SecurityIncident::findById($id);
    if (!$inc) ApiResponse::notFound();
    if (!empty($inc['notificacao_titulares_em'])) {
        ApiResponse::badRequest('Titulares já notificados em ' . $inc['notificacao_titulares_em']);
    }

    SecurityIncident::markNotifiedHolders($id, $canal, $userId);
    MasterAudit::log('incident.notify_holders', 'incident', $id,
        "Notificação aos titulares registrada — incidente #{$id} via {$canal}",
        ['canal' => $canal]);
    ApiResponse::ok(['notified' => true, 'when' => date('Y-m-d H:i:s'), 'canal' => $canal]);
}

// ─── POST ?action=add_event ──────────────────────────────────────────────────
if ($method === 'POST' && ($_GET['action'] ?? null) === 'add_event') {
    $id   = (int)($input['id'] ?? 0);
    $te   = trim((string)($input['tipo_evento'] ?? ''));
    $desc = trim((string)($input['descricao'] ?? ''));
    $payload = is_array($input['payload'] ?? null) ? $input['payload'] : null;

    if (!$id || $te === '') ApiResponse::badRequest('id e tipo_evento obrigatórios');
    if (!SecurityIncident::findById($id)) ApiResponse::notFound();

    SecurityIncident::addEvent($id, $te, $desc ?: null, $userId, $payload);
    MasterAudit::log('incident.event_added', 'incident', $id,
        "Evento '{$te}' adicionado ao incidente #{$id}",
        ['descricao' => $desc, 'payload' => $payload]);
    ApiResponse::ok(['added' => true]);
}

// ─── POST — cria novo incidente ──────────────────────────────────────────────
if ($method === 'POST') {
    try {
        $newId = SecurityIncident::create([
            'account_id'         => isset($input['account_id']) ? (int)$input['account_id'] : null,
            'tipo'               => $input['tipo']               ?? '',
            'severidade'         => $input['severidade']         ?? 'media',
            'titulo'             => $input['titulo']             ?? '',
            'descricao_interna'  => $input['descricao_interna']  ?? null,
            'descricao_publica'  => $input['descricao_publica']  ?? null,
            'dados_afetados'     => $input['dados_afetados']     ?? null,
            'impacto'            => $input['impacto']            ?? null,
            'causa_raiz'         => $input['causa_raiz']         ?? null,
            'medidas_imediatas'  => $input['medidas_imediatas']  ?? null,
            'medidas_corretivas' => $input['medidas_corretivas'] ?? null,
            'detectado_em'       => $input['detectado_em']       ?? null,
            'ocorrido_em'        => $input['ocorrido_em']        ?? null,
            'responsavel_user_id'=> isset($input['responsavel_user_id']) ? (int)$input['responsavel_user_id'] : null,
        ], $userId);
    } catch (\InvalidArgumentException $e) {
        ApiResponse::badRequest($e->getMessage());
    }

    MasterAudit::log('incident.create', 'incident', $newId,
        'Incidente de segurança registrado',
        [
            'tipo'       => $input['tipo']       ?? null,
            'severidade' => $input['severidade'] ?? null,
            'account_id' => $input['account_id'] ?? null,
        ]);

    ApiResponse::ok(['id' => $newId, 'created' => true]);
}

// ─── PATCH — atualiza ─────────────────────────────────────────────────────────
if ($method === 'PATCH') {
    $id = (int)($input['id'] ?? 0);
    if (!$id) ApiResponse::badRequest('id obrigatório');
    if (!SecurityIncident::findById($id)) ApiResponse::notFound();

    $up = $input;
    unset($up['id'], $up['csrf_token']);

    $ok = SecurityIncident::update($id, $up, $userId);
    if ($ok) {
        MasterAudit::log('incident.update', 'incident', $id,
            'Incidente atualizado',
            ['campos' => array_keys($up)]);
    }
    ApiResponse::ok(['updated' => $ok]);
}

ApiResponse::methodNotAllowed();
