<?php
/**
 * push/search_processos.php — Autocomplete pra vincular publicação a processo.
 *
 * GET ?q={termo}&limit=10
 *
 * Retorna processos do tenant cujo numero_cnj, numero ou descricao bate com o termo.
 * Multi-tenant: respeita accountIds acessíveis do user (matriz vê filiais).
 */
ob_start();
@ini_set('display_errors', '0');

require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\Database;

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
    $ctx   = AccountContext::fromSession();

    $q     = trim((string)($_GET['q'] ?? ''));
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 10)));

    // q vazio → retorna últimos N processos (modo "lista inicial" pra UI de vincular).
    // q com 2+ chars → busca filtrada (modo autocomplete).

    $pdo = Database::getConnection();

    // Detecta se termo é numero processual (só dígitos/pontos/traços)
    $isNumero = preg_match('/^[0-9\-.]+$/', $q);
    $like     = '%' . $q . '%';
    $numLimpo = preg_replace('/[^0-9]/', '', $q);

    // Allowlist de colunas conhecidas — fallback gracioso se schema mudar
    $cols = $pdo->query("SHOW COLUMNS FROM processos")->fetchAll(\PDO::FETCH_COLUMN);
    $colsSet = array_flip($cols);

    $selectCols = ['p.id', 'p.account_id'];
    // Inclui colunas reais do schema (cliente_nome/parte_contraria/tipo_acao) +
    // legacy (autor/reu/descricao/titulo) pra robustez se schema variar entre instalações.
    foreach (['numero_cnj','numero_processo','numero','cliente_nome','parte_contraria','tipo_acao','descricao','titulo','autor','reu'] as $c) {
        if (isset($colsSet[$c])) $selectCols[] = 'p.' . $c;
    }

    // Multi-tenant: a matriz precisa achar processos das filiais ao vincular
    // uma intimação. Antes filtrava só p.account_id=:acc (achado ALTA #12),
    // o que escondia os processos de filial. Usamos getAccessibleAccountIds
    // ('processos') — mesma fonte canônica de processes.php (account_vinculos +
    // resource shares), respeitando sync_processos por filial.
    $accIds = $ctx->getAccessibleAccountIds('processos');
    if (empty($accIds)) {
        echo json_encode(['ok' => true, 'total' => 0, 'items' => []]);
        exit;
    }
    $accPh  = [];
    $params = [];
    foreach ($accIds as $i => $aid) {
        $k = "acc{$i}";
        $accPh[]      = ":{$k}";
        $params[$k]   = (int)$aid;
    }
    $where  = ['p.account_id IN (' . implode(',', $accPh) . ')'];

    // Filtro opcional: só aplica se q tiver pelo menos 2 chars
    if (mb_strlen($q) >= 2) {
        $orClauses = [];
        if ($isNumero && $numLimpo !== '') {
            foreach (['numero_cnj','numero_processo','numero'] as $c) {
                if (isset($colsSet[$c])) {
                    $orClauses[] = "REPLACE(REPLACE(REPLACE(p.{$c},'-',''),'.',''),'/','') LIKE :nl";
                }
            }
            $params['nl'] = '%' . $numLimpo . '%';
        }
        foreach (['numero_cnj','numero_processo','numero','cliente_nome','parte_contraria','tipo_acao','descricao','titulo','autor','reu'] as $c) {
            if (isset($colsSet[$c])) {
                $orClauses[] = "p.{$c} LIKE :lk";
            }
        }
        if ($orClauses) {
            $where[] = '(' . implode(' OR ', $orClauses) . ')';
            $params['lk'] = $like;
        }
    }

    $sql = 'SELECT ' . implode(', ', $selectCols)
         . ' FROM processos p WHERE ' . implode(' AND ', $where)
         . ' ORDER BY p.id DESC LIMIT ' . $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    // Normaliza pra UI — sempre devolve {id, label, sublabel}
    // label    = número (CNJ ou alternativo)
    // sublabel = "Cliente × Parte Contrária — Tipo Ação" (montado dos campos disponíveis)
    $items = array_map(static function ($r) {
        $num   = $r['numero_cnj'] ?? $r['numero_processo'] ?? $r['numero'] ?? '';
        $cli   = trim((string)($r['cliente_nome']    ?? $r['autor'] ?? ''));
        $parte = trim((string)($r['parte_contraria'] ?? $r['reu']   ?? ''));
        $tipo  = trim((string)($r['tipo_acao']       ?? $r['descricao'] ?? $r['titulo'] ?? ''));

        // Monta partes × partes
        $partes = trim(implode(' × ', array_filter([$cli, $parte])));
        $sub = $partes;
        if ($tipo !== '') {
            $sub = $sub !== '' ? $sub . ' — ' . $tipo : $tipo;
        }

        return [
            'id'       => (int)$r['id'],
            'label'    => $num ?: ('Processo #' . $r['id']),
            'sublabel' => $sub,
        ];
    }, $rows);

    echo json_encode(['ok' => true, 'total' => count($items), 'items' => $items]);

} catch (\Throwable $e) {
    \App\Core\ErrorReporter::handle($e);
}
