<?php
/**
 * push/list.php — Lista publicações persistidas + status do usuário.
 *
 * GET ?lida=0&favorita=1&com_prazo=0&data_inicio=...&data_fim=...&tribunal=...&q=...
 *
 * Inclui também o cache_hoje (push_today_cache) marcado com flag _from_cache=true.
 * Frontend renderiza ambos numa única lista cronológica.
 */
ob_start();
@ini_set('display_errors', '0');

require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\Database;
use App\Processos\PushEvent;
use App\Processos\PushTodayCache;
use App\Processos\PushEventUserStatus;

session_start(['read_and_close' => true]);

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'GET only']);
    exit;
}

try {
    $ctx       = AccountContext::fromSession();
    $ctx->assertModuloJuridicoDisponivel();
    $accountId = $ctx->getAccountId();
    $userId    = (int)$_SESSION['user_id'];
    $pdo       = Database::getConnection();

    // Allowlist de filtros
    $fLida     = isset($_GET['lida'])      ? (int)$_GET['lida']     : null; // 0|1|null
    $fFav      = isset($_GET['favorita'])  ? (int)$_GET['favorita'] : null;
    $fPrazo    = isset($_GET['com_prazo']) ? (int)$_GET['com_prazo']: null;
    $fDataIni  = isset($_GET['data_inicio']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['data_inicio']) ? $_GET['data_inicio'] : null;
    $fDataFim  = isset($_GET['data_fim'])    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['data_fim'])    ? $_GET['data_fim']    : null;
    $fTrib     = isset($_GET['tribunal'])  ? strtoupper(preg_replace('/[^A-Z0-9-]/i', '', $_GET['tribunal'])) : '';
    $fProc     = isset($_GET['processo'])  ? preg_replace('/[^0-9]/', '', $_GET['processo']) : '';
    // source_id: filtra por provider (djen/aasp/datajud/...). Vazio = todos.
    $fSrc      = isset($_GET['source_id']) ? preg_replace('/[^a-z0-9_]/', '', strtolower((string)$_GET['source_id'])) : '';
    $fTrat     = in_array($_GET['tratamento'] ?? '', PushEvent::TRATAMENTOS, true) ? $_GET['tratamento'] : '';
    $limit     = max(1, min(500, (int)($_GET['limit'] ?? 100)));
    $incCache  = (int)($_GET['include_cache'] ?? 1) === 1;
    // Item só do cache do dia ainda não foi tratado por ninguém: é pendente.
    if ($fTrat !== '' && $fTrat !== 'pendente') $incCache = false;

    // ── Listar push_events persistidos ────────────────────────────────────────
    $where = ['e.account_id = :acc', 'e.status = "ativo"'];
    $bind  = ['acc' => $accountId, 'uid' => $userId];

    if ($fLida !== null)  { $where[] = 'COALESCE(s.lida, 0) = :lida';        $bind['lida']  = $fLida; }
    if ($fFav !== null)   { $where[] = 'COALESCE(s.favorita, 0) = :fav';     $bind['fav']   = $fFav; }
    if ($fPrazo !== null) { $where[] = 'COALESCE(s.com_prazo, 0) = :prz';    $bind['prz']   = $fPrazo; }
    if ($fDataIni)        { $where[] = 'e.data_disponibilizacao >= :di';     $bind['di']    = $fDataIni; }
    if ($fDataFim)        { $where[] = 'e.data_disponibilizacao <= :df';     $bind['df']    = $fDataFim; }
    if ($fTrib !== '')    { $where[] = 'e.tribunal = :trib';                 $bind['trib']  = $fTrib; }
    if ($fProc !== '')    { $where[] = 'e.numero_processo = :np';            $bind['np']    = $fProc; }
    if ($fSrc !== '')     { $where[] = 'e.source_id = :src';                 $bind['src']   = $fSrc; }
    if ($fTrat !== '')    { $where[] = 'e.tratamento_status = :trat';        $bind['trat']  = $fTrat; }

    $sql = 'SELECT e.id, e.account_id, e.source_id, e.tribunal,
                   e.data_disponibilizacao, e.data_publicacao,
                   e.numero_processo, e.numero_processo_mascara,
                   e.orgao, e.tipo_comunicacao, e.meio, e.meio_completo,
                   e.classe_nome, e.titulo, e.resumo, e.conteudo, e.url_origem,
                   e.hash_externo, e.hash_conteudo,
                   e.created_at, e.updated_at,
                   e.processo_id,
                   e.tratamento_status, e.tratamento_nota, e.tratamento_nome, e.tratamento_em,
                   -- payload_original ENTRA aqui, e e apagado logo abaixo, antes
                   -- de sair na resposta. Sem ele, listPhpExtractAdvogados e
                   -- listPhpExtractPartes devolviam lista vazia para TODO evento
                   -- persistido, e as linhas "Parte(s):" e "Adv:" so apareciam
                   -- nos itens do cache do dia, que carregam o payload por outro
                   -- caminho. Efeito na tela: a publicacao aparecia formatada
                   -- enquanto era cache e PERDIA a formatacao ao virar
                   -- permanente, que e o contrario do esperado.
                   e.payload_original,
                   COALESCE(s.lida, 0)      AS lida,
                   s.lida_em,
                   COALESCE(s.favorita, 0)  AS favorita,
                   COALESCE(s.com_prazo, 0) AS com_prazo,
                   s.prazo_data,
                   s.comentario
            FROM push_events e
            LEFT JOIN push_event_user_status s
              ON s.event_id = e.id AND s.user_id = :uid
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY e.data_disponibilizacao DESC, e.id DESC
            LIMIT ' . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($bind);
    $eventos = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    // ── Cache do dia (não persistido) ─────────────────────────────────────────
    $cache = [];
    if ($incCache) {
        $opts = [];
        if ($fTrib !== '')    $opts['tribunal']              = $fTrib;
        if ($fDataIni)        $opts['data_disponibilizacao'] = $fDataIni;
        if ($fSrc !== '')     $opts['source_id']             = $fSrc;
        $rawCache = PushTodayCache::listForAccount($accountId, $opts);

        // Excluir do cache os que já foram promovidos a push_events
        $eventHashes = array_flip(array_column($eventos, 'hash_conteudo'));
        foreach ($rawCache as $c) {
            if (isset($eventHashes[$c['hash_conteudo']])) continue;
            // Filtros lida/favorita não se aplicam ao cache (não tem status)
            if ($fLida === 1 || $fFav === 1 || $fPrazo === 1) continue;
            $c['_from_cache']   = true;
            $c['id']            = null;
            $c['lida']          = 0;
            $c['favorita']      = 0;
            $c['com_prazo']     = 0;
            $cache[] = $c;
        }
    }

    // Combina e ordena (events + cache) por data desc
    $combined = array_merge($eventos, $cache);
    usort($combined, static function ($a, $b) {
        $da = $a['data_disponibilizacao'] ?? '1970-01-01';
        $db = $b['data_disponibilizacao'] ?? '1970-01-01';
        return strcmp($db, $da);
    });

    // KPI: não-lidas pro user
    $naoLidas = PushEventUserStatus::countNaoLidas($userId, $accountId);

    // Enriquecer cada item com lista de advogados + partes extraídas do payload_original
    // (pra UI mostrar "Adv: X, Y, Z" e "Parte(s): A.R.S. C.F.S.O...").
    // payload_original NÃO vai pro frontend — apagado depois.
    foreach ($combined as &$it) {
        $it['advogados'] = listPhpExtractAdvogados($it);
        $it['partes']    = listPhpExtractPartes($it);
        unset($it['payload_original']);  // LGPD: não envia raw
    }
    unset($it);

    echo json_encode([
        'ok'        => true,
        'total'     => count($combined),
        'eventos'   => count($eventos),
        'cache'     => count($cache),
        'nao_lidas' => $naoLidas,
        'pendentes' => PushEvent::contarPendentes($accountId),
        'items'     => $combined,
    ]);

} catch (\Throwable $e) {
    \App\Core\ErrorReporter::handle($e);
}

/**
 * Extrai lista de advogados [{nome, oab, uf}] do payload_original.
 * Cobre AASP (texto inline "NOME OAB UF-NNNN") e DJEN (array destinatarioadvogados).
 * Retorna [] se nada encontrado.
 */
function listPhpExtractAdvogados(array $it): array
{
    // A regra mora em App\Processos\PublicacaoEstrutura, que a home nova também usa.
    return \App\Processos\PublicacaoEstrutura::advogadosDoPayload($it['payload_original'] ?? null);
}

/**
 * Extrai lista de partes ["NOME 1", "NOME 2", ...] do payload_original.
 * DJEN: array 'destinatarios' com objetos {nome, polo}.
 * AASP: regex "Parte(s): X" no textoPublicacao até o próximo label.
 */
function listPhpExtractPartes(array $it): array
{
    return \App\Processos\PublicacaoEstrutura::partesDoPayload($it['payload_original'] ?? null);
}
