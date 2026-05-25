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

require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/Account.php';
require_once __DIR__ . '/../../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Helpers/ErrorReporter.php';

use App\Helpers\AccountContext;
use App\Models\Database;

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
    $accountId = $ctx->getAccountId();

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
    foreach (['numero_cnj','numero_processo','numero','descricao','titulo','autor','reu'] as $c) {
        if (isset($colsSet[$c])) $selectCols[] = 'p.' . $c;
    }

    $where  = ['p.account_id = :acc'];
    $params = ['acc' => $accountId];

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
        foreach (['numero_cnj','numero_processo','numero','descricao','titulo','autor','reu'] as $c) {
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
    $items = array_map(static function ($r) {
        $num = $r['numero_cnj'] ?? $r['numero_processo'] ?? $r['numero'] ?? '';
        $desc = $r['descricao'] ?? $r['titulo'] ?? '';
        $extra = trim(implode(' × ', array_filter([$r['autor'] ?? '', $r['reu'] ?? ''])));
        return [
            'id'       => (int)$r['id'],
            'label'    => $num ?: ('Processo #' . $r['id']),
            'sublabel' => trim($desc . ($extra ? ' (' . $extra . ')' : '')) ?: '',
        ];
    }, $rows);

    echo json_encode(['ok' => true, 'total' => count($items), 'items' => $items]);

} catch (\Throwable $e) {
    \App\Helpers\ErrorReporter::handle($e);
}
