<?php
ob_start();
@ini_set('display_errors', '0');

require_once __DIR__ . '/../../../app/Core/Database.php';
require_once __DIR__ . '/../../../app/Master/Account.php';
require_once __DIR__ . '/../../../app/Master/ResourceShare.php';
require_once __DIR__ . '/../../../app/WhatsAppAgente/WhatsAppInstance.php';
require_once __DIR__ . '/../../../app/WhatsAppAgente/EvolutionApiService.php';
require_once __DIR__ . '/../../../app/WhatsAppAgente/WhatsAppChannelAccessService.php';
require_once __DIR__ . '/../../../app/Core/AccountContext.php';

use App\Core\Database;
use App\Core\AccountContext;
// NB (auditoria 2026-06-01, BAIXA #25): removido `use App\Services\EvolutionApiService`.
// A classe EvolutionApiService e GLOBAL (sem namespace) — este import resolvia para um
// FQCN inexistente e nunca era usado (instanciamos via \EvolutionApiService mais abaixo).

session_start(['read_and_close' => true]);
$_csrf = $_SESSION['csrf_token'] ?? '';

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$ctx       = AccountContext::fromSession();
$accountId = $ctx->getAccountId();

$method = $_SERVER['REQUEST_METHOD'];

try {
    $pdo        = Database::getConnection();

    // Contatos do canal = 'view' (deny-by-default). Resolve canal próprio ou, com a
    // flag ligada, o compartilhado (filial herdando o da matriz). instance_id e
    // credenciais (usadas no fetch_pic) saem do backend, do dono do canal.
    $ch         = WhatsAppChannelAccessService::resolveForRequest($pdo, $accountId, ($_GET['channel_id'] ?? null), 'view');
    $cfg        = $ch['cfg'];
    $instName   = $ch['instance_name'];
    $instanceId = (int)$ch['channel_id'];

    if ($method === 'GET') {
        $stmt = $pdo->prepare(
            'SELECT remote_jid, push_name, phone FROM whatsapp_contacts
             WHERE instance_id = ? ORDER BY push_name ASC'
        );
        $stmt->execute([$instanceId]);
        echo json_encode(['ok' => true, 'contacts' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($method === 'POST') {
        $payload = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($payload['_csrf']) || $payload['_csrf'] !== $_csrf) {
            http_response_code(403); echo json_encode(['error' => 'CSRF inválido']); exit;
        }

        $action = trim($payload['action'] ?? '');

        // ── action: fetch_pic ─────────────────────────────────────────────────
        // Busca foto de perfil on-demand via Evolution API e cacheia em whatsapp_chats.
        // Usado por openChat() pra hidratar a foto de conversas 1:1 que ainda não tem.
        if ($action === 'fetch_pic') {
            $jid = trim($payload['jid'] ?? '');
            if (!$jid) { http_response_code(400); echo json_encode(['error' => 'jid obrigatório']); exit; }
            try {
                // NB: a classe EvolutionApiService é GLOBAL (sem namespace). O
                // `use App\Services\EvolutionApiService` do topo deste arquivo está
                // ERRADO e fazia `new EvolutionApiService` resolver pra um FQCN
                // inexistente → "Class not found" → foto nunca vinha. Forçamos o
                // namespace global com a barra inicial.
                $evo = new \EvolutionApiService($cfg);
                // Foto de perfil é "nice-to-have": não pode pendurar a conexão.
                // Sem timeout, a Evolution lenta segurava o request ~20s e, com
                // vários cliques, saturava o pool de conexões do navegador.
                $evo->setTimeout(6);

                // ── GRUPO: a foto vem pelo próprio JID (@g.us) ────────────────────
                // O fetchProfilePictureUrl aceita o group JID como "number" (Baileys).
                // Grupo não tem telefone, então não passa pela resolução de telefone
                // abaixo. Antes, foto de grupo só vinha do "Sincronizar" (fetchAllGroups);
                // agora abre/atualiza on-demand igual 1:1.
                if (str_ends_with($jid, '@g.us')) {
                    $res = $evo->getProfilePicture($instName, $jid);
                    $picUrl = $res['profilePictureUrl'] ?? ($res['profile_pic_url'] ?? null);
                    if ($picUrl) {
                        $pdo->prepare(
                            'UPDATE whatsapp_chats SET profile_pic_url = ?
                             WHERE instance_id = ? AND remote_jid = ?'
                        )->execute([$picUrl, $instanceId, $jid]);
                    }
                    echo json_encode(['ok' => true, 'profile_pic_url' => $picUrl]);
                    exit;
                }
                // ── Resolve o TELEFONE real (dialável) pra buscar a foto ──────────
                // A Evolution só devolve foto pelo telefone (10-13 díg.); o LID
                // ('@lid', 14+ díg.) NÃO resolve. À PROVA DE BALAS (não depende do
                // front mandar certo). Ordem: (1) 'number' do front se válido;
                // (2) dígitos do jid quando @s.whatsapp.net; (3) telefone do LID via
                // group_members; (4) via contacts — mesma resolução do getChatList.
                $isPhone  = static fn($n) => preg_match('/^[0-9]{10,13}$/', (string)$n) === 1;
                $digits   = static fn($v) => preg_replace('/\D/', '', (string)$v);
                $fetchNum = $digits($payload['number'] ?? '');
                if (!$isPhone($fetchNum)) {
                    $fetchNum = '';
                    if (str_contains($jid, '@s.whatsapp.net')) {
                        $cand = $digits(explode('@', $jid)[0]);
                        if ($isPhone($cand)) $fetchNum = $cand;
                    }
                }
                if ($fetchNum === '') {
                    try {
                        // ESCOPADO ao canal resolvido (instance_id) — sem isso, a busca
                        // varria o pool GLOBAL e vazava telefone de canal alheio (Fase 2 E).
                        $rs = $pdo->prepare("SELECT phone FROM whatsapp_group_members WHERE participant_jid COLLATE utf8mb4_unicode_ci = ? AND instance_id = ? AND phone REGEXP '^[0-9]{10,13}\$' LIMIT 1");
                        $rs->execute([$jid, $instanceId]);
                        $cand = $digits($rs->fetchColumn());
                        if ($isPhone($cand)) $fetchNum = $cand;
                    } catch (\Throwable $_) {}
                }
                if ($fetchNum === '') {
                    try {
                        $rs = $pdo->prepare("SELECT phone FROM whatsapp_contacts WHERE remote_jid = ? AND instance_id = ? AND phone REGEXP '^[0-9]{10,13}\$' LIMIT 1");
                        $rs->execute([$jid, $instanceId]);
                        $cand = $digits($rs->fetchColumn());
                        if ($isPhone($cand)) $fetchNum = $cand;
                    } catch (\Throwable $_) {}
                }
                if ($fetchNum === '') {
                    // Sem telefone discável (contato só-LID sem registro) → sem foto
                    echo json_encode(['ok' => true, 'profile_pic_url' => null, 'note' => 'sem telefone para buscar foto']);
                    exit;
                }
                $res = $evo->getProfilePicture($instName, $fetchNum);
                // Evolution v2 retorna { profilePictureUrl: '...' } ou { wuid, profilePictureUrl }
                $picUrl = $res['profilePictureUrl']
                       ?? $res['profile_pic_url']
                       ?? null;

                if ($picUrl) {
                    // Cacheia a foto SO em chat ja existente (UPDATE). Nao cria shell novo:
                    // criar linha so pra foto gerava "fantasma" vazio (@lid sem mensagem).
                    $pdo->prepare(
                        'UPDATE whatsapp_chats SET profile_pic_url = ?
                         WHERE instance_id = ? AND remote_jid = ?'
                    )->execute([$picUrl, $instanceId, $jid]);
                }

                echo json_encode(['ok' => true, 'profile_pic_url' => $picUrl]);
                exit;
            } catch (\Throwable $e) {
                // Loga o motivo real (antes era engolido em silêncio → impossível
                // diagnosticar por que a foto não vinha). Não expõe ao cliente.
                // F4 (auditoria): mascara dígitos do JID no log (LGPD — não guardar telefone cru).
                error_log('[whatsapp fetch_pic] jid=' . preg_replace('/\d{5,}/', '*****', (string)($jid ?? '?'))
                    . ' erro: ' . $e->getMessage()
                    . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
                echo json_encode(['ok' => true, 'profile_pic_url' => null, 'note' => 'sem foto disponível']);
                exit;
            }
        }

        // ── action default: editar push_name custom ───────────────────────────
        $jid  = trim($payload['remote_jid'] ?? '');
        $name = trim($payload['push_name']  ?? '');
        if (!$jid) { http_response_code(400); echo json_encode(['error' => 'remote_jid obrigatório']); exit; }

        // Rename MANUAL: marca is_manual_name=1 pra o webhook (contacts.update, linha ~216)
        // e o sync NUNCA sobrescreverem o nome digitado pelo usuario. Espelha em
        // whatsapp_chats.contact_name, que e a 1a camada do display_name da lista quando
        // is_manual_name=1 (getChatList). Se o usuario LIMPAR o nome, volta a resolucao
        // automatica (is_manual_name=0) e nao grava nome vazio por cima do existente.
        $manual = ($name !== '') ? 1 : 0;
        $pdo->prepare(
            'UPDATE whatsapp_contacts SET push_name = ?, is_manual_name = ? WHERE instance_id = ? AND remote_jid = ?'
        )->execute([$name, $manual, $instanceId, $jid]);
        if ($name !== '') {
            $pdo->prepare(
                'UPDATE whatsapp_chats SET contact_name = ?, is_manual_name = 1 WHERE instance_id = ? AND remote_jid = ?'
            )->execute([$name, $instanceId, $jid]);
        } else {
            $pdo->prepare(
                'UPDATE whatsapp_chats SET is_manual_name = 0 WHERE instance_id = ? AND remote_jid = ?'
            )->execute([$instanceId, $jid]);
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(405); echo json_encode(['error' => 'Method not allowed']);

} catch (Throwable $e) {
    require_once __DIR__ . '/../../../app/Core/ErrorReporter.php';
    \App\Core\ErrorReporter::handle($e);  // P1 LGPD (2D.1)
}
