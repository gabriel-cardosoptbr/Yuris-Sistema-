<?php
/**
 * /api/master/ai_area_questions.php — Painel Master: BANCO GLOBAL de perguntas por area.
 *
 * O "segundo mundo" do motor de pre-atendimento: alem das perguntas genericas, cada area
 * classificada tem perguntas especificas de triagem. Sao DETERMINISTICAS (NAO entram no prompt
 * mestre, NAO custam tokens) e 100% editaveis aqui. O super-admin ve/edita tudo no banco master.
 *
 * Acesso: super_admin em sessao master_mode; escrita exige nivel != viewer + CSRF. Auditado.
 *
 *   GET                              -> { ok, data:{ areas:[ {code,name,active,questions:[...]} ], summary:{...} } }
 *   POST action=add     {area_code, question_text, help_text?, question_key?}
 *   POST action=update  {id, question_text?, help_text?, active?, sort?}
 *   POST action=toggle  {id}
 *   POST action=delete  {id}
 *   POST action=reorder {area_code, ids:[...]}
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\ApiResponse;
use App\Master\MasterAudit;

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();
if (empty($_SESSION['master_mode'])) ApiResponse::forbidden('Acesso somente pelo Painel Master.');

$pdo    = \App\Core\Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];

/** Catalogo de areas + perguntas agrupadas + resumo. */
function _payload(\PDO $pdo): array {
    // Areas do catalogo global (mesma fonte que classifica os leads).
    $areas = [];
    foreach ($pdo->query("SELECT code, name, active FROM ai_area_catalog ORDER BY sort ASC, name ASC") as $a) {
        $areas[$a['code']] = [
            'code'      => $a['code'],
            'name'      => $a['name'],
            'active'    => (int)$a['active'],
            'questions' => [],
            'total'     => 0,
            'ativas'    => 0,
        ];
    }
    $totQ = 0; $totAtivas = 0;
    $rows = $pdo->query("SELECT id, area_code, question_key, question_text, help_text, sort, active
                           FROM ai_area_questions ORDER BY area_code ASC, sort ASC, id ASC")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
        $code = $r['area_code'];
        if (!isset($areas[$code])) {
            // Area sem catalogo (defensivo): cria um grupo "orfao" para nao sumir do Master.
            $areas[$code] = ['code'=>$code,'name'=>$code,'active'=>0,'questions'=>[],'total'=>0,'ativas'=>0];
        }
        $areas[$code]['questions'][] = [
            'id'            => (int)$r['id'],
            'question_key'  => $r['question_key'],
            'question_text' => $r['question_text'],
            'help_text'     => $r['help_text'],
            'sort'          => (int)$r['sort'],
            'active'        => (int)$r['active'],
        ];
        $areas[$code]['total']++;
        $totQ++;
        if ((int)$r['active'] === 1) { $areas[$code]['ativas']++; $totAtivas++; }
    }
    $list = array_values($areas);
    $comPerguntas = count(array_filter($list, fn($x) => $x['total'] > 0));
    return [
        'areas'   => $list,
        'summary' => [
            'areas_total'        => count($list),
            'areas_com_pergunta' => $comPerguntas,
            'perguntas_total'    => $totQ,
            'perguntas_ativas'   => $totAtivas,
        ],
    ];
}

/** Gera question_key estavel (snake_case, sem acento, <=40), unico na area. */
function _slugKey(\PDO $pdo, string $areaCode, string $text, ?string $preferred = null): string {
    $base = $preferred !== null && $preferred !== '' ? $preferred : $text;
    $map = ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','ê'=>'e','è'=>'e','ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ò'=>'o','ö'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n',
            'Á'=>'a','À'=>'a','Â'=>'a','Ã'=>'a','É'=>'e','Ê'=>'e','Í'=>'i','Ó'=>'o','Ô'=>'o','Õ'=>'o','Ú'=>'u','Ç'=>'c'];
    $s = strtr($base, $map);
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    $s = trim((string)$s, '_');
    if ($s === '') $s = 'pergunta';
    $s = substr($s, 0, 36);
    $s = rtrim($s, '_');
    // garante unicidade na area (uk_area_qkey)
    $key = $s; $i = 1;
    $st = $pdo->prepare("SELECT 1 FROM ai_area_questions WHERE area_code = ? AND question_key = ? LIMIT 1");
    while (true) {
        $st->execute([$areaCode, $key]);
        if (!$st->fetchColumn()) break;
        $i++;
        $key = substr($s, 0, 36) . '_' . $i;
    }
    return $key;
}

if ($method === 'GET') {
    ApiResponse::ok(_payload($pdo));
}

if ($method === 'POST') {
    $in   = json_decode(file_get_contents('php://input'), true) ?? [];
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['csrf_token'] ?? ($in['_csrf'] ?? null));
    if (!$csrf || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$csrf)) {
        http_response_code(400); echo json_encode(['ok' => false, 'error' => 'CSRF inválido']); exit;
    }
    if ($ctx->getSuperAdminLevel() === 'viewer') ApiResponse::forbidden('Seu nível (viewer) não permite editar as perguntas.');

    $action = $in['action'] ?? '';

    if ($action === 'add') {
        $areaCode = trim((string)($in['area_code'] ?? ''));
        if ($areaCode === '') ApiResponse::badRequest('Área é obrigatória.');
        // valida contra o catalogo de areas (evita area inexistente)
        $chk = $pdo->prepare("SELECT 1 FROM ai_area_catalog WHERE code = ? LIMIT 1");
        $chk->execute([$areaCode]);
        if (!$chk->fetchColumn()) ApiResponse::badRequest('Área inexistente no catálogo.');

        $text = trim((string)($in['question_text'] ?? ''));
        if ($text === '') ApiResponse::badRequest('O texto da pergunta é obrigatório.');
        if (mb_strlen($text) > 500) ApiResponse::badRequest('A pergunta é muito longa (máx. 500 caracteres).');
        if (strpos($text, '—') !== false) ApiResponse::badRequest('Não use travessão (—). Prefira vírgula ou dois-pontos.');
        $help = trim((string)($in['help_text'] ?? ''));
        if (mb_strlen($help) > 255) $help = mb_substr($help, 0, 255);

        $key  = _slugKey($pdo, $areaCode, $text, isset($in['question_key']) ? trim((string)$in['question_key']) : null);
        $sort = (int)$pdo->query("SELECT COALESCE(MAX(sort),-1)+1 FROM ai_area_questions WHERE area_code = " . $pdo->quote($areaCode))->fetchColumn();

        $pdo->prepare("INSERT INTO ai_area_questions (area_code, question_key, question_text, help_text, sort, active)
                       VALUES (?,?,?,?,?,1)")
            ->execute([$areaCode, $key, $text, ($help !== '' ? $help : null), $sort]);
        MasterAudit::log('ai_area_question.add', 'ai_area_question', (int)$pdo->lastInsertId(), "Adicionou pergunta na área {$areaCode}", ['area'=>$areaCode,'key'=>$key]);
        ApiResponse::ok(_payload($pdo));
    }

    if ($action === 'update') {
        $id = (int)($in['id'] ?? 0);
        if ($id <= 0) ApiResponse::badRequest('id obrigatório.');
        $cur = $pdo->prepare("SELECT * FROM ai_area_questions WHERE id = ? LIMIT 1"); $cur->execute([$id]);
        $row = $cur->fetch(\PDO::FETCH_ASSOC);
        if (!$row) ApiResponse::badRequest('Pergunta não encontrada.');

        $text = isset($in['question_text']) ? trim((string)$in['question_text']) : (string)$row['question_text'];
        if ($text === '') ApiResponse::badRequest('O texto da pergunta é obrigatório.');
        if (mb_strlen($text) > 500) ApiResponse::badRequest('A pergunta é muito longa (máx. 500 caracteres).');
        if (strpos($text, '—') !== false) ApiResponse::badRequest('Não use travessão (—). Prefira vírgula ou dois-pontos.');
        $help = array_key_exists('help_text', $in) ? trim((string)$in['help_text']) : (string)($row['help_text'] ?? '');
        if (mb_strlen($help) > 255) $help = mb_substr($help, 0, 255);
        $sort   = isset($in['sort'])   ? (int)$in['sort']            : (int)$row['sort'];
        $active = isset($in['active']) ? (int)((bool)$in['active'])  : (int)$row['active'];

        $pdo->prepare("UPDATE ai_area_questions SET question_text=?, help_text=?, sort=?, active=? WHERE id=?")
            ->execute([$text, ($help !== '' ? $help : null), $sort, $active, $id]);
        MasterAudit::log('ai_area_question.update', 'ai_area_question', $id, "Editou pergunta da área {$row['area_code']}", ['active'=>$active]);
        ApiResponse::ok(_payload($pdo));
    }

    if ($action === 'toggle') {
        $id = (int)($in['id'] ?? 0);
        if ($id <= 0) ApiResponse::badRequest('id obrigatório.');
        $pdo->prepare("UPDATE ai_area_questions SET active = IF(active=1,0,1) WHERE id = ?")->execute([$id]);
        MasterAudit::log('ai_area_question.toggle', 'ai_area_question', $id, 'Alternou ativo/inativo da pergunta', []);
        ApiResponse::ok(_payload($pdo));
    }

    if ($action === 'delete') {
        $id = (int)($in['id'] ?? 0);
        if ($id <= 0) ApiResponse::badRequest('id obrigatório.');
        $cur = $pdo->prepare("SELECT area_code, question_key FROM ai_area_questions WHERE id = ? LIMIT 1"); $cur->execute([$id]);
        $row = $cur->fetch(\PDO::FETCH_ASSOC);
        $pdo->prepare("DELETE FROM ai_area_questions WHERE id = ?")->execute([$id]);
        MasterAudit::log('ai_area_question.delete', 'ai_area_question', $id, 'Removeu pergunta ' . ($row ? $row['area_code'].'/'.$row['question_key'] : "#$id"), []);
        ApiResponse::ok(_payload($pdo));
    }

    if ($action === 'reorder') {
        $areaCode = trim((string)($in['area_code'] ?? ''));
        $ids = $in['ids'] ?? [];
        if ($areaCode === '' || !is_array($ids) || !$ids) ApiResponse::badRequest('area_code e ids são obrigatórios.');
        $upd = $pdo->prepare("UPDATE ai_area_questions SET sort = ? WHERE id = ? AND area_code = ?");
        $i = 0;
        foreach ($ids as $qid) { $upd->execute([$i++, (int)$qid, $areaCode]); }
        MasterAudit::log('ai_area_question.reorder', 'ai_area_question', 0, "Reordenou perguntas da área {$areaCode}", ['n'=>count($ids)]);
        ApiResponse::ok(_payload($pdo));
    }

    ApiResponse::badRequest('Ação inválida.');
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
