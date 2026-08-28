<?php
/**
 * /api/master/ai_agent_config.php — Painel Master: EDITAR a config do agente de QUALQUER
 * conta (central de controle). Espelha os campos do /api/agent_settings.php (a tela da
 * conta), mas sem restricao de escopo: o super-admin edita o agente de qualquer canal.
 * Config chaveada por whatsapp_instance_id (1 agente por canal).
 *
 * Acesso: super_admin em sessao master_mode; escrita exige nivel != viewer + CSRF. Auditado.
 *
 *   GET  ?instance_id=ID  -> { ok, data:{ ...campos..., channel, catalog, areas } }
 *   POST { whatsapp_instance_id, ...campos..., areas:[...] } -> salva tudo
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\ApiResponse;
use App\Master\MasterAudit;
use App\Core\Crypto;
use App\Usuarios\TotpHelper;

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();
if (empty($_SESSION['master_mode'])) ApiResponse::forbidden('Acesso somente pelo Painel Master.');

$pdo    = \App\Core\Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];

function _mask(?string $p): string {
    if (!$p) return '';
    return strlen($p) <= 8 ? str_repeat('*', max(0, strlen($p) - 2)) . substr($p, -2)
                           : str_repeat('*', strlen($p) - 4) . substr($p, -4);
}
function _dec(?string $enc): ?string {
    if ($enc === null || $enc === '') return null;
    try { return Crypto::decrypt($enc); }
    catch (\Throwable $_) { try { return TotpHelper::decryptSecret($enc); } catch (\Throwable $_e) { return null; } }
}
function _jdec($v): array {
    if (is_array($v)) return $v;
    if (is_string($v) && $v !== '') { $d = json_decode($v, true); return is_array($d) ? $d : []; }
    return [];
}
function _channel(\PDO $pdo, int $iid): ?array {
    $st = $pdo->prepare("SELECT i.id, i.account_id, i.instance_name, i.display_name, i.phone, i.status,
                                a.nome AS account_nome, a.tipo AS account_tipo
                           FROM whatsapp_instances i JOIN accounts a ON a.id = i.account_id
                          WHERE i.id = ? LIMIT 1");
    $st->execute([$iid]);
    return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
}
function _cfg(\PDO $pdo, int $iid): ?array {
    $st = $pdo->prepare('SELECT * FROM agent_configs WHERE whatsapp_instance_id = ? LIMIT 1');
    $st->execute([$iid]);
    return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
}
function _catalog(\PDO $pdo): array {
    try { return $pdo->query("SELECT code, name FROM ai_area_catalog WHERE active = 1 ORDER BY sort ASC, name ASC")->fetchAll(\PDO::FETCH_ASSOC) ?: []; }
    catch (\Throwable $_) { return []; }
}
function _areas(\PDO $pdo, int $agentId): array {
    if ($agentId <= 0) return [];
    try {
        $st = $pdo->prepare("SELECT code, name, enabled, priority FROM ai_intake_areas WHERE agent_id = ? ORDER BY priority DESC, name ASC");
        $st->execute([$agentId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $_) { return []; }
}
function _payload(?array $cfg, ?array $inst): array {
    $masked = ($cfg && !empty($cfg['api_key_enc'])) ? _mask(_dec($cfg['api_key_enc'])) : '';
    return [
        'name'               => $cfg['name'] ?? '',
        'enabled'            => (int)($cfg['enabled'] ?? 0),
        'provider'           => $cfg['provider'] ?? 'openai',
        'model'              => $cfg['model'] ?? 'gpt-4o-mini',
        'api_key_masked'     => $masked,
        'prompt'             => $cfg['prompt'] ?? '',
        'max_questions'      => (int)($cfg['max_questions'] ?? 6),
        'office_name'        => $cfg['office_name'] ?? '',
        'office_description' => $cfg['office_description'] ?? '',
        'initial_message'    => $cfg['initial_message'] ?? '',
        'closing_message'    => $cfg['closing_message'] ?? '',
        'urgency_message'    => $cfg['urgency_message'] ?? '',
        'handoff_message'    => $cfg['handoff_message'] ?? '',
        'office_information'  => _jdec($cfg['office_information_json'] ?? null),
        'behavior'           => _jdec($cfg['behavior_json'] ?? null),
        'handoff_config'     => _jdec($cfg['handoff_config_json'] ?? null),
        'usage_limits'       => _jdec($cfg['usage_limits_json'] ?? null),
        'channel'            => $inst ? [
            'instance_id' => (int)$inst['id'], 'instance_name' => $inst['instance_name'],
            'display_name' => $inst['display_name'] ?: $inst['instance_name'], 'phone' => $inst['phone'],
            'status' => $inst['status'] ?: 'close', 'connected' => ($inst['status'] === 'open'),
            'account_nome' => $inst['account_nome'], 'account_tipo' => $inst['account_tipo'],
        ] : null,
    ];
}

// ─── GET ───────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $iid = (int)($_GET['instance_id'] ?? 0);
    if ($iid <= 0) ApiResponse::badRequest('instance_id obrigatório.');
    $inst = _channel($pdo, $iid);
    if (!$inst) ApiResponse::badRequest('Canal não encontrado.');
    $cfg = _cfg($pdo, $iid);
    $p = _payload($cfg, $inst);
    $p['catalog'] = _catalog($pdo);
    $p['areas']   = $cfg ? _areas($pdo, (int)$cfg['id']) : [];
    ApiResponse::ok($p);
}

// ─── POST ──────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $in   = json_decode(file_get_contents('php://input'), true) ?? [];
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['csrf_token'] ?? ($in['_csrf'] ?? null));
    if (!$csrf || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$csrf)) {
        http_response_code(400); echo json_encode(['ok' => false, 'error' => 'CSRF inválido']); exit;
    }
    if ($ctx->getSuperAdminLevel() === 'viewer') ApiResponse::forbidden('Seu nível (viewer) não permite editar o agente.');

    $iid = (int)($in['whatsapp_instance_id'] ?? 0);
    if ($iid <= 0) ApiResponse::badRequest('whatsapp_instance_id obrigatório.');
    $inst = _channel($pdo, $iid);
    if (!$inst) ApiResponse::badRequest('Canal não encontrado.');

    $S = fn($k) => isset($in[$k]) ? trim((string)$in[$k]) : null;        // string trim
    $T = fn($k) => isset($in[$k]) ? (string)$in[$k] : null;              // texto cru
    $J = fn($k) => (isset($in[$k]) && is_array($in[$k])) ? json_encode($in[$k], JSON_UNESCAPED_UNICODE) : null;

    $name     = $S('name');
    $enabled  = isset($in['enabled']) ? (int)((bool)$in['enabled']) : null;
    $provider = $S('provider'); if ($provider !== null) $provider = strtolower($provider) ?: 'openai';
    $model    = $S('model');
    $prompt   = $T('prompt');
    $maxQ     = isset($in['max_questions']) ? max(3, min(8, (int)$in['max_questions'])) : null;
    $oName    = $S('office_name');
    $oDesc    = $T('office_description');
    $imsg     = $T('initial_message');
    $cmsg     = $T('closing_message');
    $umsg     = $T('urgency_message');
    $hmsg     = $T('handoff_message');
    $oInfo    = $J('office_information');
    $behav    = $J('behavior');
    $hCfg     = $J('handoff_config');
    $uLim     = $J('usage_limits');
    $status   = ($enabled === null) ? null : ($enabled === 1 ? 'active' : 'inactive');
    $apiKey   = isset($in['api_key']) ? trim((string)$in['api_key']) : '';   // override por canal (blank = mantem)

    // Validacao leve dos blocos JSON (se o admin colou texto invalido, avisa).
    foreach (['office_information','behavior','handoff_config','usage_limits'] as $jk) {
        if (isset($in[$jk]) && !is_array($in[$jk])) ApiResponse::badRequest("Campo JSON \"$jk\" inválido (esperado objeto).");
    }

    $apiKeyEnc = null; $touchKey = false;
    if ($apiKey !== '') {
        try { $apiKeyEnc = Crypto::encrypt($apiKey); $touchKey = true; }
        catch (\Throwable $e) { http_response_code(503); echo json_encode(['ok'=>false,'error'=>'APP_ENCRYPTION_KEY ausente — não dá pra cifrar a chave.']); exit; }
    }

    $ownerAcc = (int)$inst['account_id'];
    $branchId = ($inst['account_tipo'] === 'filial') ? $ownerAcc : null;
    $existing = _cfg($pdo, $iid);

    if (!$existing) {
        $pdo->prepare(
            'INSERT INTO agent_configs
              (account_id, branch_id, whatsapp_instance_id, name, enabled, status, provider, model, api_key_enc, prompt,
               max_questions, office_name, office_description, office_information_json, behavior_json, handoff_config_json,
               usage_limits_json, initial_message, closing_message, urgency_message, handoff_message, updated_by)
             VALUES (:acc,:br,:iid,:name,:en,:st,:pr,:model,:key,:prm,:mq,:on,:od,:oi,:bh,:hc,:ul,:im,:cm,:um,:hm,:ub)'
        )->execute([
            'acc'=>$ownerAcc,'br'=>$branchId,'iid'=>$iid,'name'=>$name ?? '','en'=>$enabled ?? 0,'st'=>$status ?? 'inactive',
            'pr'=>$provider ?? 'openai','model'=>$model ?? 'gpt-4o-mini','key'=>$apiKeyEnc,'prm'=>$prompt,'mq'=>$maxQ ?? 6,
            'on'=>$oName,'od'=>$oDesc,'oi'=>$oInfo,'bh'=>$behav,'hc'=>$hCfg,'ul'=>$uLim,'im'=>$imsg,'cm'=>$cmsg,'um'=>$umsg,'hm'=>$hmsg,
            'ub'=>(int)($_SESSION['user_id'] ?? 0),
        ]);
    } else {
        $f = ['account_id = :acc', 'branch_id = :br', 'updated_by = :ub'];
        $p = ['iid'=>$iid, 'acc'=>$ownerAcc, 'br'=>$branchId, 'ub'=>(int)($_SESSION['user_id'] ?? 0)];
        $set = function(string $col, string $key, $val) use (&$f, &$p) { if ($val !== null) { $f[] = "$col = :$key"; $p[$key] = $val; } };
        $set('name','name',$name); $set('enabled','en',$enabled); $set('status','st',$status);
        $set('provider','pr',$provider); $set('model','model',$model);
        if ($touchKey) { $f[] = 'api_key_enc = :key'; $p['key'] = $apiKeyEnc; }
        $set('prompt','prm',$prompt); $set('max_questions','mq',$maxQ);
        $set('office_name','on',$oName); $set('office_description','od',$oDesc);
        $set('office_information_json','oi',$oInfo); $set('behavior_json','bh',$behav);
        $set('handoff_config_json','hc',$hCfg); $set('usage_limits_json','ul',$uLim);
        $set('initial_message','im',$imsg); $set('closing_message','cm',$cmsg);
        $set('urgency_message','um',$umsg); $set('handoff_message','hm',$hmsg);
        $pdo->prepare('UPDATE agent_configs SET ' . implode(', ', $f) . ' WHERE whatsapp_instance_id = :iid')->execute($p);
    }

    $cfg = _cfg($pdo, $iid);
    $agentId = (int)($cfg['id'] ?? 0);

    // Areas de triagem (catalogo).
    if ($agentId > 0 && isset($in['areas']) && is_array($in['areas'])) {
        $catMap = [];
        foreach (_catalog($pdo) as $a) $catMap[$a['code']] = $a['name'];
        $ups = $pdo->prepare(
            'INSERT INTO ai_intake_areas (account_id, agent_id, code, name, enabled, priority)
             VALUES (:acc,:ag,:code,:name,:en,:pr)
             ON DUPLICATE KEY UPDATE enabled=VALUES(enabled), priority=VALUES(priority), name=VALUES(name)'
        );
        foreach ($in['areas'] as $a) {
            $code = trim((string)($a['code'] ?? ''));
            if ($code === '' || !isset($catMap[$code])) continue;
            $ups->execute(['acc'=>$ownerAcc,'ag'=>$agentId,'code'=>$code,'name'=>$catMap[$code],
                           'en'=>(int)!empty($a['enabled']),'pr'=>(int)($a['priority'] ?? 0)]);
        }
    }

    MasterAudit::log('ai_agent.config', 'agent_config', $iid,
        'Editou o agente da conta "' . $inst['account_nome'] . '" (canal ' . $inst['instance_name'] . ')',
        ['enabled'=>$enabled, 'provider'=>$provider, 'model'=>$model, 'prompt_changed'=>$prompt !== null, 'api_key_changed'=>$touchKey]);

    $p = _payload($cfg, $inst);
    $p['catalog'] = _catalog($pdo);
    $p['areas']   = $agentId ? _areas($pdo, $agentId) : [];
    ApiResponse::ok($p);
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
