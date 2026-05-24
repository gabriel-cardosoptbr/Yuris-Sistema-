<?php
/**
 * /api/master/lgpd_finding_review.php — revisão individual de finding.
 *
 * Permite ao DPO marcar, para cada `lgpd_request_finding`:
 *   - se INCLUI no export entregue ao titular
 *   - se PODE EXCLUIR/ANONIMIZAR (true) ou se DEVE RETER (false)
 *   - motivo curto da retenção (resumo — justificativa formal completa
 *     fica em lgpd_request_retention_justifications)
 *
 * Trigger SQL permite UPDATE APENAS nestes campos (revisado_em,
 * revisado_por_user_id, incluido_no_export, pode_excluir, motivo_retencao).
 * Tentar mudar qualquer outro campo dispara SIGNAL 45000.
 *
 * PATCH body: { csrf_token, finding_id, incluido_no_export?, pode_excluir?, motivo_retencao? }
 *   - pelo menos UM campo de decisão (incluido_no_export OU pode_excluir) deve vir
 *
 * Acesso: super_admin com master_mode.
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/Account.php';
require_once __DIR__ . '/../../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../../app/Models/LgpdRequest.php';
require_once __DIR__ . '/../../../app/Models/LgpdRequestFinding.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Helpers/ApiResponse.php';
require_once __DIR__ . '/../../../app/Helpers/MasterAudit.php';

use App\Helpers\AccountContext;
use App\Helpers\ApiResponse;
use App\Helpers\MasterAudit;
use App\Models\LgpdRequest;
use App\Models\LgpdRequestFinding;

session_start();
$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();
if (empty($_SESSION['master_mode'])) {
    ApiResponse::forbidden('Acesso somente pelo Painel Master.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
    ApiResponse::methodNotAllowed();
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$csrf  = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
    ApiResponse::badRequest('CSRF inválido');
}

$findingId = (int)($input['finding_id'] ?? 0);
if (!$findingId) ApiResponse::badRequest('finding_id obrigatório');

// Carrega finding para audit e validação de propriedade
$pdo = \App\Models\Database::getConnection();
$st = $pdo->prepare("SELECT request_id, entidade, entidade_id, campo, modulo
                     FROM lgpd_request_findings WHERE id = ?");
$st->execute([$findingId]);
$f = $st->fetch(\PDO::FETCH_ASSOC);
if (!$f) ApiResponse::notFound('Finding não encontrado.');

// Pelo menos um campo de decisão precisa vir
$hasIncluir = array_key_exists('incluido_no_export', $input);
$hasPodeExc = array_key_exists('pode_excluir',       $input);
$hasMotivo  = array_key_exists('motivo_retencao',    $input);

if (!$hasIncluir && !$hasPodeExc && !$hasMotivo) {
    ApiResponse::badRequest('Pelo menos incluido_no_export, pode_excluir ou motivo_retencao deve ser informado.');
}

$incluir = $hasIncluir ? (bool)$input['incluido_no_export'] : null;
$podeExc = $hasPodeExc ? (bool)$input['pode_excluir']       : null;
$motivo  = $hasMotivo  ? trim((string)$input['motivo_retencao']) : null;

// Regra de negócio: se DPO marca pode_excluir=false, deve dar motivo
if ($podeExc === false && ($motivo === null || $motivo === '')) {
    ApiResponse::badRequest('Ao marcar pode_excluir=false, é obrigatório informar motivo_retencao (resumo) ou registrar justificativa completa via lgpd_anonymize.php?action=register_retention.');
}

$userId = $ctx->getUserId();
try {
    $ok = LgpdRequestFinding::review($findingId, $userId, $incluir, $podeExc, $motivo);
} catch (\Throwable $e) {
    error_log('[lgpd_finding_review] ' . $e->getMessage());
    ApiResponse::serverError('Falha ao revisar finding: ' . $e->getMessage());
}

if (!$ok) ApiResponse::serverError('UPDATE falhou (possivelmente bloqueado pelo trigger de imutabilidade).');

// Registra evento na timeline da solicitação + master_audit_log
$detalhes = "{$f['modulo']}/{$f['entidade']}#{$f['entidade_id']}/{$f['campo']}";
$decisao  = [];
if ($incluir !== null)  $decisao[] = 'no_export=' . ($incluir ? '1' : '0');
if ($podeExc !== null)  $decisao[] = 'pode_excluir=' . ($podeExc ? '1' : '0');
if ($motivo !== null)   $decisao[] = 'motivo="' . substr($motivo, 0, 50) . '"';

LgpdRequest::addEvent((int)$f['request_id'], 'finding_revisado',
    "Finding #$findingId revisado ($detalhes): " . implode(', ', $decisao),
    $userId);

MasterAudit::log('lgpd.finding_review', 'lgpd_request_finding', $findingId,
    "Finding revisado pelo DPO",
    ['request_id' => (int)$f['request_id'], 'finding' => $detalhes,
     'incluir' => $incluir, 'pode_excluir' => $podeExc, 'motivo' => $motivo]);

ApiResponse::ok([
    'updated' => true,
    'finding_id' => $findingId,
    'incluido_no_export' => $incluir,
    'pode_excluir'       => $podeExc,
]);
