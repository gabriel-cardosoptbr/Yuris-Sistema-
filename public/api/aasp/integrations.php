<?php
/**
 * aasp/integrations.php — CRUD de integrações AASP.
 *
 * Endpoints (single-file roteado por método HTTP):
 *
 *   GET    ?                              → lista todas integrações do tenant
 *   GET    ?id=<n>                        → 1 integração detalhada
 *   POST   { _csrf, nome, tipo, chave,
 *            codigos_associados?,
 *            intervalo_minutos?,
 *            advogado_id? }               → cria + audit('created')
 *   PATCH  { _csrf, id, nome?,
 *            status?, intervalo_minutos?,
 *            codigos_associados?,
 *            tipo?, advogado_id? }        → atualiza metadados (NÃO toca chave)
 *   PATCH  { _csrf, id, chave: '...' }    → rotação de chave + audit('rotated')
 *   DELETE { _csrf, id }                  → delete + audit('revoked')
 *
 * Segurança:
 *   • Auth: session válida + role owner|admin (assertOwnerOrAdmin)
 *   • CSRF obrigatório em mutating
 *   • Chave plain nunca aparece em response (apenas chave_masked)
 *   • Multi-tenant: AaspIntegration::* já filtra account_id
 */
ob_start();
@ini_set('display_errors', '0');

require_once __DIR__ . '/../../../app/Core/Database.php';
require_once __DIR__ . '/../../../app/Processos/AaspIntegration.php';
require_once __DIR__ . '/../../../app/Core/AccountContext.php';
require_once __DIR__ . '/../../../app/Core/EnvLoader.php';
require_once __DIR__ . '/../../../app/Core/ErrorReporter.php';
require_once __DIR__ . '/../../../app/Core/Crypto.php';
require_once __DIR__ . '/../../../app/Billing/PlanFeature.php';

use App\Core\AccountContext;
use App\Core\Crypto;
use App\Billing\PlanFeature;
use App\Processos\AaspIntegration;

session_start(['read_and_close' => true]);
$csrfSession = $_SESSION['csrf_token'] ?? '';
$userId      = (int)($_SESSION['user_id'] ?? 0);

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

if ($userId === 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Não autenticado']);
    exit;
}

try {
    $ctx       = AccountContext::fromSession();
    $accountId = $ctx->getAccountId();
    $method    = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    // Módulo por plano (AASP entra a partir do Escritório). Só nas escritas:
    // o GET continua respondendo lista vazia, para a aba não quebrar na tela
    // de quem não tem o recurso.
    if ($method !== 'GET') {
        PlanFeature::assertEnabled($accountId, PlanFeature::F_AASP);
    }

    // ── GET (lista ou item) — sem CSRF, é só leitura
    if ($method === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id > 0) {
            $rec = AaspIntegration::find($id, $accountId);
            if (!$rec) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Integração não encontrada']);
                exit;
            }
            echo json_encode(['ok' => true, 'integration' => $rec]);
            exit;
        }
        $list = AaspIntegration::listForAccount($accountId);
        // Sumário útil pra UI
        $summary = [
            'total'          => count($list),
            'ativas'         => count(array_filter($list, fn($i) => $i['status'] === 'active')),
            'pending'        => count(array_filter($list, fn($i) => $i['status'] === 'pending')),
            'erros'          => count(array_filter($list, fn($i) => $i['status'] === 'error')),
            'crypto_ok'      => Crypto::isConfigured(),
        ];
        echo json_encode([
            'ok'           => true,
            'integrations' => $list,
            'summary'      => $summary,
            'can_manage'   => $ctx->isOwnerOrAdmin(),
        ]);
        exit;
    }

    // ── Mutating: exige CSRF + owner|admin
    $payload = json_decode(file_get_contents('php://input'), true) ?? [];
    if (empty($payload['_csrf']) || !hash_equals($csrfSession, (string)$payload['_csrf'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF inválido']);
        exit;
    }
    if (!$ctx->isOwnerOrAdmin()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Apenas owner ou admin pode gerenciar integrações AASP']);
        exit;
    }

    // ── POST: cria nova integração ───────────────────────────────────────
    if ($method === 'POST') {
        $nome  = trim((string)($payload['nome'] ?? ''));
        $tipo  = ($payload['tipo'] ?? 'associado') === 'empresa' ? 'empresa' : 'associado';
        $chave = trim((string)($payload['chave'] ?? ''));
        $codigos = isset($payload['codigos_associados']) && is_array($payload['codigos_associados'])
            ? array_values(array_unique(array_map('intval', array_filter($payload['codigos_associados']))))
            : [];
        $intervalo = max(30, (int)($payload['intervalo_minutos'] ?? 120));
        $advogadoId = !empty($payload['advogado_id']) ? (int)$payload['advogado_id'] : null;

        if ($nome === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Informe um nome pra integração (ex: "Bruno Carreira Ferreira")']);
            exit;
        }
        if ($chave === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Informe a chave AASP']);
            exit;
        }
        if ($tipo === 'empresa' && empty($codigos)) {
            http_response_code(400);
            echo json_encode([
                'ok'    => false,
                'error' => 'Modo empresa exige pelo menos 1 código de associado (a API AASP rejeita sem).',
            ]);
            exit;
        }
        if (!Crypto::isConfigured()) {
            http_response_code(500);
            echo json_encode([
                'ok'    => false,
                'error' => 'APP_ENCRYPTION_KEY não configurada no servidor. Contate o suporte.',
            ]);
            exit;
        }

        // Se cliente confirmou que testou conexão OK antes (mesmo payload de chave+tipo+códigos),
        // grava direto como 'active' em vez de 'pending'. Server-side trust client-side flag —
        // pior caso: status fica active e o 1º sync vira error rapidinho se a chave não prestar.
        $validated = !empty($payload['validated']);
        try {
            $id = AaspIntegration::create([
                'account_id'        => $accountId,
                'nome'              => $nome,
                'tipo'              => $tipo,
                'codigos_associados'=> $codigos,
                'intervalo_minutos' => $intervalo,
                'advogado_id'       => $advogadoId,
                'created_by'        => $userId,
                'status'            => $validated ? 'active' : 'pending',
            ], $chave);
        } catch (\PDOException $e) {
            // UNIQUE collision em (account_id, nome)
            if ((int)$e->getCode() === 23000) {
                http_response_code(409);
                echo json_encode(['ok' => false, 'error' => 'Já existe uma integração AASP com este nome neste tenant']);
                exit;
            }
            throw $e;
        }

        AaspIntegration::audit(
            $accountId, $id, 'created', $userId,
            "tipo={$tipo} nome=\"{$nome}\" codigos=" . count($codigos)
            . ($validated ? ' (validated=true → active)' : '')
        );

        $rec = AaspIntegration::find($id, $accountId);
        http_response_code(201);
        echo json_encode(['ok' => true, 'integration' => $rec]);
        exit;
    }

    // ── PATCH: atualiza metadados OU rotaciona chave ─────────────────────
    if ($method === 'PATCH') {
        $id = (int)($payload['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'id obrigatório']);
            exit;
        }
        $exists = AaspIntegration::find($id, $accountId);
        if (!$exists) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Integração não encontrada']);
            exit;
        }

        // Rotação de chave (campo chave presente e não-vazio)
        if (!empty($payload['chave'])) {
            $novaChave = trim((string)$payload['chave']);
            if ($novaChave === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Chave nova vazia']);
                exit;
            }
            $validated = !empty($payload['validated']);
            AaspIntegration::rotateChave($id, $accountId, $novaChave, $validated);
            AaspIntegration::audit(
                $accountId, $id, 'rotated', $userId,
                'rotação de chave' . ($validated ? ' (validated=true → active)' : '')
            );
            $rec = AaspIntegration::find($id, $accountId);
            echo json_encode(['ok' => true, 'integration' => $rec, 'rotated' => true]);
            exit;
        }

        // Atualização de metadados
        $update = [];
        foreach (['nome','tipo','status','intervalo_minutos','advogado_id','codigos_associados'] as $col) {
            if (array_key_exists($col, $payload)) $update[$col] = $payload[$col];
        }
        if (isset($update['intervalo_minutos'])) $update['intervalo_minutos'] = max(30, (int)$update['intervalo_minutos']);
        if (isset($update['status']) && !in_array($update['status'], ['active','inactive','error','pending'], true)) {
            unset($update['status']);
        }
        if (empty($update)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Nenhum campo válido pra atualizar']);
            exit;
        }
        AaspIntegration::update($id, $accountId, $update);
        $rec = AaspIntegration::find($id, $accountId);
        echo json_encode(['ok' => true, 'integration' => $rec]);
        exit;
    }

    // ── DELETE: revoga integração ────────────────────────────────────────
    if ($method === 'DELETE') {
        $id = (int)($payload['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'id obrigatório']);
            exit;
        }
        $exists = AaspIntegration::find($id, $accountId);
        if (!$exists) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Integração não encontrada']);
            exit;
        }
        AaspIntegration::delete($id, $accountId);
        AaspIntegration::audit($accountId, $id, 'revoked', $userId, 'integração excluída');
        echo json_encode(['ok' => true, 'deleted' => $id]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método não suportado: ' . $method]);
} catch (\Throwable $e) {
    \App\Core\ErrorReporter::handle($e);
}
