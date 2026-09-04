<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\Database;
use App\WhatsAppAgente\EvolutionApiService;
use App\WhatsAppAgente\WhatsAppChannelAccessService;
use App\WhatsAppAgente\WhatsAppInstance;

session_start(['read_and_close' => true]);
$_uid  = $_SESSION['user_id']    ?? null;
$_csrf = $_SESSION['csrf_token'] ?? '';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store'); // resposta carrega estado de conexão do tenant
if (!$_uid) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

$ctx        = AccountContext::fromSession();
$accountId  = $ctx->getAccountId();
$pdo        = Database::getConnection();

// ─── Autorização em DUAS camadas (Fase 2 — canais compartilhados) ─────────────
//  • Camada de PAPEL (intra-conta): gerir a conexão (conectar/QR/restart/logout/
//    webhook/criar) continua sendo só owner/admin. Ortogonal ao canal.
//  • Camada de CANAL (cross-tenant): WhatsAppChannelAccessService resolve o canal
//    no backend e autoriza por grant. 'manage' é EXCLUSIVO do dono — uma conta com
//    acesso compartilhado (filial herdando o canal da matriz) nunca conecta/exclui.
//  A instância/credenciais são resolvidas SEMPRE no backend, a partir do DONO do
//  canal (nunca do front). 'status' é leitura (view); a chave nunca é devolvida.
// ──────────────────────────────────────────────────────────────────────────────
$canManage  = $ctx->isOwnerOrAdmin();
$denyManage = static function () {
    http_response_code(403);
    echo json_encode(['error' => 'Apenas owner/admin pode gerenciar a conexão WhatsApp']);
    exit;
};

$model  = new WhatsAppInstance();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── GET ────────────────────────────────────────────────────────────────────
if ($method === 'GET') {

    if ($action === 'status') {
        // Leitura do estado de conexão (banner) — exige 'view' no canal.
        $ch   = WhatsAppChannelAccessService::resolveForRequest($pdo, $accountId, $_GET['channel_id'] ?? null, 'view');
        $cfg  = $ch['cfg'];
        $name = $ch['instance_name'];
        $row  = $ch['instance_row'];
        $evo  = new EvolutionApiService($cfg);

        // Consulta estado real na Evolution API
        $state = $evo->getConnectionState($name);
        $status = 'close';
        if (!empty($state['instance']['state'])) {
            $status = strtolower($state['instance']['state']);
        } elseif (!empty($state['state'])) {
            $status = strtolower($state['state']);
        }

        // Sincroniza status no DB — tenta vários campos possíveis para o número
        $extra = [];
        if (!empty($state['instance']['profileName'])) $extra['profile_name'] = $state['instance']['profileName'];

        // wuid, ownerJid e number são os campos usados por diferentes versões da Evolution API
        $phoneRaw = $state['instance']['wuid']
                 ?? $state['instance']['ownerJid']
                 ?? $state['instance']['number']
                 ?? null;

        // Se não veio no connectionState, busca via fetchInstances
        if (!$phoneRaw) {
            $instances = $evo->fetchInstances();
            foreach ((array)$instances as $inst) {
                $iName = $inst['name'] ?? ($inst['instance']['instanceName'] ?? null);
                if ($iName === $name) {
                    $phoneRaw = $inst['ownerJid']
                             ?? $inst['instance']['ownerJid']
                             ?? $inst['wuid']
                             ?? $inst['instance']['wuid']
                             ?? null;
                    if (!$phoneRaw && !empty($inst['instance']['profileName'])) {
                        $extra['profile_name'] = $extra['profile_name'] ?? $inst['instance']['profileName'];
                    }
                    break;
                }
            }
        }

        if ($phoneRaw) {
            // Normaliza: remove sufixo @s.whatsapp.net e caracteres não-numéricos
            $extra['phone'] = preg_replace('/[^0-9+]/', '', explode('@', $phoneRaw)[0]);
        }

        $model->updateStatus((int)$ch['channel_id'], $status, $extra);

        // Remove campos grandes/sensíveis da resposta de status.
        unset($row['qr_code_base64'], $row['evolution_token'], $row['webhook_url']);
        echo json_encode([
            'ok'       => true,
            'status'   => $status,
            'instance' => array_merge($row, $extra, ['status' => $status]),
        ]);
        exit;
    }

    // Daqui pra baixo (qr / list / GET default) é gestão/leitura de config: exige owner/admin.
    if (!$canManage) { $denyManage(); }

    if ($action === 'qr') {
        // Conectar/QR é AÇÃO DE GESTÃO do canal → 'manage' (exclusivo do dono).
        $ch   = WhatsAppChannelAccessService::resolveForRequest($pdo, $accountId, $_GET['channel_id'] ?? null, 'manage');
        $cfg  = $ch['cfg'];
        $name = $ch['instance_name'];

        // ── Canal sem credencial da Evolution: falhar FALANDO ───────────────
        // Sem base_url/api_key, o EvolutionApiService cai nos defaults dele
        // (http://localhost:8080, chave vazia), a chamada morre e o QR volta
        // string vazia. Antes disto o endpoint respondia {ok:true, qr:""}: a
        // tela recebia "sucesso", não desenhava nada e o usuário ficava
        // olhando um espaço em branco sem saber que faltava configurar o canal.
        // Erro de configuração tem que dizer o que configurar.
        $faltando = [];
        if (trim((string)($cfg['evolution_base_url'] ?? '')) === '') $faltando[] = 'URL da Evolution';
        if (trim((string)($cfg['evolution_api_key']  ?? '')) === '') $faltando[] = 'chave de API';
        if ($faltando) {
            http_response_code(409);
            echo json_encode([
                'ok'            => false,
                'error'         => 'Canal do WhatsApp ainda não configurado para esta conta: falta '
                                 . implode(' e ', $faltando) . '. '
                                 . 'A conexão é provisionada no Painel Master, em Configurações de WhatsApp.',
                'code'          => 'canal_nao_configurado',
                'faltando'      => $faltando,
                'instance_name' => $name,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $evo  = new EvolutionApiService($cfg);
        $res  = $evo->connectInstance($name);

        $qr = $res['base64'] ?? ($res['qrcode']['base64'] ?? ($res['code'] ?? ''));

        // Evolution respondeu, mas sem QR. Os dois motivos normais são: já está
        // conectado (não há o que escanear) ou a instância não existe lá. Em
        // qualquer caso, devolver {ok:true, qr:""} deixa a tela muda.
        if (!$qr) {
            $estado = '';
            try {
                $st = $evo->getConnectionState($name);
                $estado = (string)($st['instance']['state'] ?? $st['state'] ?? '');
            } catch (\Throwable $e) { /* estado é só para a mensagem */ }

            if ($estado === 'open') {
                echo json_encode([
                    'ok'       => true,
                    'qr'       => '',
                    'conectado' => true,
                    'mensagem' => 'Este número já está conectado, não há QR para escanear.',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            http_response_code(502);
            echo json_encode([
                'ok'            => false,
                'error'         => 'A Evolution respondeu sem QR Code'
                                 . ($estado !== '' ? " (estado do canal: {$estado})" : '')
                                 . '. Tente novamente; se persistir, o canal precisa ser reprovisionado no Painel Master.',
                'code'          => 'qr_vazio',
                'estado'        => $estado,
                'instance_name' => $name,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Garante prefixo data URI
        if (!str_starts_with($qr, 'data:')) {
            $qr = 'data:image/png;base64,' . $qr;
        }
        $model->updateQrCode((int)$ch['channel_id'], $qr);

        echo json_encode(['ok' => true, 'qr' => $qr]);
        exit;
    }

    if ($action === 'list') {
        // Lista APENAS os canais visíveis pra conta (dono + compartilhados se a flag
        // estiver ligada). Nunca expõe canal não vinculado, token, webhook ou QR.
        $viewable = WhatsAppChannelAccessService::viewableChannelIds($pdo, $accountId);
        $out = [];
        foreach ($viewable as $cid) {
            $r = $model->find((int)$cid);
            if (!$r) continue;
            // Projeção MÍNIMA (whitelist) — nunca o row cru. Evita vazar colunas
            // sensíveis atuais ou futuras (token/webhook/qr/credenciais) a um tenant
            // com acesso compartilhado. Só metadados de exibição.
            $out[] = [
                'id'            => (int)$r['id'],
                'instance_name' => $r['instance_name'] ?? '',
                'display_name'  => $r['display_name'] ?: ($r['instance_name'] ?? ''),
                'status'        => $r['status'] ?? 'close',
                'phone'         => $r['phone'] ?? null,
                'is_own'        => ((int)($r['account_id'] ?? 0) === $accountId),
            ];
        }
        echo json_encode(['ok' => true, 'instances' => $out]);
        exit;
    }

    // Default: retorna a instância do canal da conta com estado atual (NUNCA credenciais).
    $ch  = WhatsAppChannelAccessService::resolveForRequest($pdo, $accountId, $_GET['channel_id'] ?? null, 'view');
    $row = $ch['instance_row'];
    unset($row['evolution_token'], $row['webhook_url'], $row['qr_code_base64']);
    echo json_encode(['ok' => true, 'instance' => $row]);
    exit;
}

// ── POST ───────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true) ?? [];
    if (empty($payload['_csrf']) || $payload['_csrf'] !== $_csrf) {
        http_response_code(403); echo json_encode(['error' => 'CSRF inválido']); exit;
    }

    // Todas as mutações abaixo exigem owner/admin (papel) E 'manage' no canal.
    if (!$canManage) { $denyManage(); }

    $action = $payload['action'] ?? '';

    // conectar/criar/restart/logout/webhook são GESTÃO do canal → 'manage'.
    // 'manage' é exclusivo do dono: conta com canal compartilhado é negada aqui.
    $ch   = WhatsAppChannelAccessService::resolveForRequest($pdo, $accountId, $payload['channel_id'] ?? null, 'manage');
    $cfg  = $ch['cfg'];
    $name = $ch['instance_name'];      // resolvido no backend — não confiamos no front
    $ownerId = (int)$ch['owner_account_id'];
    $evo  = new EvolutionApiService($cfg);

    if ($action === 'create') {
        // Recria a instância do PRÓPRIO canal na Evolution. O nome é o resolvido no
        // backend (ignora qualquer 'name' do front — anti-tampering).
        $webhookUrl = $payload['webhook_url'] ?? '';
        $res        = $evo->createInstance($name, $webhookUrl);

        if (!empty($res['_error'])) {
            echo json_encode(['ok' => false, 'error' => $res['_error']]); exit;
        }

        // Salva QR code se retornado na criação
        $qr = $res['qrcode']['base64'] ?? ($res['base64'] ?? '');
        if ($qr) {
            if (!str_starts_with($qr, 'data:')) $qr = 'data:image/png;base64,' . $qr;
            $model->updateQrCode((int)$ch['channel_id'], $qr);
        }

        $row = $model->find((int)$ch['channel_id']);
        if (is_array($row)) unset($row['evolution_token'], $row['webhook_url'], $row['qr_code_base64']);
        echo json_encode(['ok' => true, 'instance' => $row, 'qr' => $qr]);
        exit;
    }

    if ($action === 'connect') {
        $res = $evo->connectInstance($name);

        $qr = $res['base64'] ?? ($res['qrcode']['base64'] ?? '');
        if (!$qr && !empty($res['code'])) $qr = $res['code'];
        if ($qr && !str_starts_with($qr, 'data:')) $qr = 'data:image/png;base64,' . $qr;
        if ($qr) $model->updateQrCode((int)$ch['channel_id'], $qr);

        echo json_encode(['ok' => true, 'qr' => $qr]);
        exit;
    }

    if ($action === 'restart') {
        $res = $evo->restartInstance($name);
        // Verifica se voltou com QR (sessão expirou)
        $qr = $res['base64'] ?? ($res['qrcode']['base64'] ?? '');
        if ($qr && !str_starts_with($qr, 'data:')) $qr = 'data:image/png;base64,' . $qr;
        echo json_encode(['ok' => true, 'qr' => $qr ?: null]);
        exit;
    }

    if ($action === 'logout') {
        $evo->logoutInstance($name);
        $model->updateStatus((int)$ch['channel_id'], 'close');
        $model->clearQrCode((int)$ch['channel_id']);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'set_webhook') {
        $url  = trim($payload['url'] ?? '');
        if (!$url) { echo json_encode(['ok' => false, 'error' => 'URL obrigatória']); exit; }

        // Anexa ?token=<apikey do DONO> à URL enviada à Evolution. Garante que o
        // webhook.php identifique o tenant mesmo quando a Evolution não repassa
        // headers customizados (a query string SEMPRE é enviada). Não duplica se o
        // usuário já tiver colocado token. A URL "limpa" (sem token) é a que fica
        // salva/exibida na tela — o token é detalhe interno da integração.
        $tenantKey = (string)($cfg['evolution_api_key'] ?? '');
        $urlFinal  = $url;
        if ($tenantKey !== '' && !preg_match('/[?&]token=/i', $url)) {
            $urlFinal .= (strpos($url, '?') === false ? '?' : '&') . 'token=' . urlencode($tenantKey);
        }

        $evo->setWebhook($name, $urlFinal);
        $model->saveSetting($ownerId, 'webhook_url', $url);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Ação desconhecida']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
