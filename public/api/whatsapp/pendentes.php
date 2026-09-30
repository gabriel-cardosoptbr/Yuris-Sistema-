<?php
/**
 * GET/POST /api/whatsapp/pendentes.php  (só conta Fleetiflow)
 *
 * "Aguardando resposta": conversas de lead em que a ÚLTIMA mensagem é do lead e
 * ninguém respondeu há mais de 5 minutos (tempo da Vitória responder). É onde a
 * automação parou e uma pessoa precisa olhar. Cada item diz o porquê:
 *   voce        a conversa está com alguém do time (assumida) e o lead respondeu
 *   qualificado a Vitória qualificou e passou para o especialista
 *   followup    o follow-up foi interrompido: a loja respondeu e a Vitória não seguiu
 *   vitoria     a Vitória parou: resposta automática, menu ou outra IA da loja
 *   sem_ia      a Vitória está desligada no canal
 * Conta por LEAD (card), não por conversa: o @lid e o número do mesmo lead são um só.
 * Etapas encerradas (Bloqueado, Fora do escopo, Perdido, Venda concluída) não entram.
 *
 *   GET                              -> { ok, itens:[{card_id, remote_jid, nome, etapa, tipo, motivo, previa, desde}] }
 *   POST { remote_jid, _csrf }       -> dispensa o item até a próxima mensagem do lead
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\Database;
use App\WhatsAppAgente\SdrFleetiflow;
use App\WhatsAppAgente\WhatsAppChannelAccessService;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$uid  = $_SESSION['user_id']    ?? null;
$csrf = $_SESSION['csrf_token'] ?? '';
if (!$uid) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

$ctx = AccountContext::fromSession();
$pdo = Database::getConnection();

try {
    $in = $_SERVER['REQUEST_METHOD'] === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
    $ch = WhatsAppChannelAccessService::resolveForRequest($pdo, (int)$ctx->getAccountId(), ($in['channel_id'] ?? $_GET['channel_id'] ?? null), 'view');
    $instanceId = (int)$ch['channel_id'];
    $dono = (int)$ch['owner_account_id'];
    if (!SdrFleetiflow::contaUsa($dono)) {
        http_response_code(404); echo json_encode(['error' => 'Indisponível para esta conta']); exit;
    }

    // Dispensa: "vi, não precisa responder" (ex.: menu de robô). Volta sozinho quando o
    // lead mandar mensagem nova, porque vale só até a última mensagem vista.
    $pdo->exec("CREATE TABLE IF NOT EXISTS sdr_pendentes_dispensados (
        instance_id INT NOT NULL,
        card_id     INT NOT NULL,
        ate         DATETIME NOT NULL,
        user_id     INT NULL,
        created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (instance_id, card_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $tok = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['_csrf'] ?? null);
        if (!$tok || !hash_equals((string)$csrf, (string)$tok)) {
            http_response_code(400); echo json_encode(['error' => 'CSRF inválido']); exit;
        }
        $st = $pdo->prepare('SELECT w.linked_card_id FROM whatsapp_chats w JOIN cards c ON c.id = w.linked_card_id AND c.deleted_at IS NULL AND c.account_id = ?
                              WHERE w.instance_id = ? AND w.remote_jid = ? LIMIT 1');
        $st->execute([$dono, $instanceId, (string)($in['remote_jid'] ?? '')]);
        $cardId = (int)($st->fetchColumn() ?: 0);
        if ($cardId <= 0) { http_response_code(404); echo json_encode(['error' => 'Conversa sem card']); exit; }
        $st = $pdo->prepare('SELECT MAX(last_message_at) FROM whatsapp_chats WHERE instance_id = ? AND linked_card_id = ?');
        $st->execute([$instanceId, $cardId]);
        $ate = $st->fetchColumn() ?: date('Y-m-d H:i:s');
        $pdo->prepare('INSERT INTO sdr_pendentes_dispensados (instance_id, card_id, ate, user_id) VALUES (?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE ate = VALUES(ate), user_id = VALUES(user_id)')
            ->execute([$instanceId, $cardId, $ate, (int)$uid]);
        echo json_encode(['ok' => true]); exit;
    }

    // Vitória ligada no canal?
    $st = $pdo->prepare('SELECT enabled FROM agent_configs WHERE whatsapp_instance_id = ? LIMIT 1');
    $st->execute([$instanceId]);
    $iaLigada = (bool)$st->fetchColumn();

    // A conversa mais recente de cada card (número e @lid do mesmo lead contam juntos).
    $st = $pdo->prepare(
        "SELECT w.remote_jid, w.linked_card_id, w.last_message_at, w.last_message_from_me,
                w.last_message_content, w.last_message_type, w.agent_paused,
                c.cliente_nome, pc.nome AS etapa, pc.id AS coluna_id
           FROM whatsapp_chats w
           JOIN cards c ON c.id = w.linked_card_id AND c.deleted_at IS NULL AND c.account_id = ?
           JOIN pipeline_columns pc ON pc.id = c.coluna_id
          WHERE w.instance_id = ? AND w.is_group = 0 AND w.last_message_at IS NOT NULL
       ORDER BY w.last_message_at DESC"
    );
    $st->execute([$dono, $instanceId]);
    $porCard = [];
    $pausado = [];
    foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $cid = (int)$r['linked_card_id'];
        if (!empty($r['agent_paused'])) $pausado[$cid] = true;
        if (!isset($porCard[$cid])) $porCard[$cid] = $r; // a primeira é a mais recente
    }

    $st = $pdo->prepare('SELECT card_id, ate FROM sdr_pendentes_dispensados WHERE instance_id = ?');
    $st->execute([$instanceId]);
    $dispensado = [];
    foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $d) $dispensado[(int)$d['card_id']] = (string)$d['ate'];

    $colunas = SdrFleetiflow::colunasDaConta($pdo, $dono);
    $etapaDe = array_flip($colunas);
    $encerradas = ['bloqueado', 'fora_escopo', 'perdido', 'venda'];
    $agora = $pdo->query('SELECT NOW()')->fetchColumn();
    $limite = date('Y-m-d H:i:s', strtotime((string)$agora) - 300);

    $itens = [];
    foreach ($porCard as $cid => $r) {
        if ((int)$r['last_message_from_me'] === 1) continue;            // a última é nossa
        if ((string)$r['last_message_at'] > $limite) continue;          // a Vitória ainda pode responder
        if (isset($dispensado[$cid]) && $dispensado[$cid] >= (string)$r['last_message_at']) continue;
        $etapa = $etapaDe[(int)$r['coluna_id']] ?? '';
        if (in_array($etapa, $encerradas, true)) continue;

        if (!empty($pausado[$cid]) || in_array($etapa, ['especialista', 'negociacao'], true)) {
            [$tipo, $motivo] = ['voce', 'A conversa está com o time e o lead respondeu.'];
        } elseif ($etapa === 'qualificado') {
            [$tipo, $motivo] = ['qualificado', 'Lead qualificado pela Vitória: assuma a conversa.'];
        } elseif (!$iaLigada) {
            [$tipo, $motivo] = ['sem_ia', 'A Vitória está desligada no canal: ninguém respondeu.'];
        } elseif ($etapa === 'followup') {
            [$tipo, $motivo] = ['followup', 'Follow-up interrompido: a loja respondeu e a Vitória não seguiu.'];
        } else {
            [$tipo, $motivo] = ['vitoria', 'A Vitória parou: resposta automática, menu ou outra IA da loja.'];
        }

        $previa = trim(preg_replace('/\s+/', ' ', (string)($r['last_message_content'] ?? '')));
        if ($previa === '') $previa = '[' . ($r['last_message_type'] ?: 'mensagem') . ']';
        $itens[] = [
            'card_id'    => $cid,
            'remote_jid' => $r['remote_jid'],
            'nome'       => $r['cliente_nome'],
            'etapa'      => $r['etapa'],
            'tipo'       => $tipo,
            'motivo'     => $motivo,
            'previa'     => mb_substr($previa, 0, 140),
            'desde'      => $r['last_message_at'],
            'minutos'    => (int)floor((strtotime((string)$agora) - strtotime((string)$r['last_message_at'])) / 60),
        ];
    }
    // Quem espera há mais tempo primeiro; qualificado e "com o time" antes de robô.
    $peso = ['qualificado' => 0, 'voce' => 1, 'sem_ia' => 2, 'followup' => 3, 'vitoria' => 4];
    usort($itens, fn($a, $b) => [$peso[$a['tipo']], -$a['minutos']] <=> [$peso[$b['tipo']], -$b['minutos']]);

    echo json_encode(['ok' => true, 'itens' => $itens], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('[pendentes] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao listar pendências']);
}
