<?php
require_once __DIR__ . '/../../app/Models/Database.php';

use App\Models\Database;

session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pdo = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];

// normalize incoming meta values (accept strings like "1.234,56" or "1234.56")
function parse_meta_value($v)
{
    if ($v === null) return 0.0;
    if (is_numeric($v)) return (float)$v;
    $s = trim((string)$v);
    if ($s === '') return 0.0;
    $hasDot = strpos($s, '.') !== false;
    $hasComma = strpos($s, ',') !== false;
    if ($hasDot && $hasComma) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } elseif ($hasComma && !$hasDot) {
        $s = str_replace(',', '.', $s);
    }
    $s = str_replace(' ', '', $s);
    return (float)$s;
}

try {
    if ($method === 'GET') {
        // Return a single isolated meta (no period) when no referencia_mes provided.
        // If referencia_mes is provided, preserve prior behavior (return list for that month).
        $q = isset($_GET['referencia_mes']) ? trim($_GET['referencia_mes']) : null;
        $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        if ($q) {
            // try to return the current user's goal first (if any)
            $stmt = $pdo->prepare('SELECT * FROM goals WHERE referencia_mes = :r AND user_id = :uid LIMIT 1');
            $stmt->execute(['r' => $q, 'uid' => $uid]);
            $row = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($row && count($row)) {
                echo json_encode($row, JSON_UNESCAPED_UNICODE);
                exit;
            }
            // fallback: return the most recent/global goal for the month
            $stmt = $pdo->prepare('SELECT * FROM goals WHERE referencia_mes = :r ORDER BY id DESC LIMIT 1');
            $stmt->execute(['r' => $q]);
            $row = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($row, JSON_UNESCAPED_UNICODE);
            exit;
        } else {
            // Retorna o registro mais recente do usuário (qualquer tipo/período)
            $stmt = $pdo->prepare("SELECT * FROM goals WHERE user_id = :uid ORDER BY updated_at DESC, id DESC LIMIT 1");
            $stmt->execute(['uid' => $uid]);
            $row = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($row && count($row)) {
                echo json_encode($row, JSON_UNESCAPED_UNICODE);
                exit;
            }
            // fallback global
            $stmt = $pdo->prepare("SELECT * FROM goals ORDER BY updated_at DESC, id DESC LIMIT 1");
            $stmt->execute();
            $row = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($row, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // read JSON body for POST/PUT
    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    if ($method === 'POST') {
        $valor = parse_meta_value($input['valor_meta'] ?? null);
        $uid = (int)($_SESSION['user_id'] ?? 0);

        // Upsert: atualiza o registro mais recente do usuário ou cria novo
        $stmt = $pdo->prepare("SELECT id FROM goals WHERE user_id = :uid ORDER BY updated_at DESC, id DESC LIMIT 1");
        $stmt->execute(['uid' => $uid]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing && isset($existing['id'])) {
            $gid = (int)$existing['id'];
            $pdo->prepare('UPDATE goals SET valor_meta = :val, tipo_meta = :tipo, referencia_mes = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
                ->execute(['val' => $valor, 'id' => $gid, 'tipo' => 'single']);
        } else {
            $pdo->prepare('INSERT INTO goals (user_id, referencia_mes, valor_meta, tipo_meta) VALUES (:uid, NULL, :val, :tipo)')
                ->execute(['uid' => $uid, 'val' => $valor, 'tipo' => 'single']);
            $gid = (int)$pdo->lastInsertId();
        }
        $row = $pdo->prepare('SELECT * FROM goals WHERE id = :id');
        $row->execute(['id' => $gid]);
        echo json_encode(['ok' => true, 'id' => $gid, 'goal' => $row->fetch(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;

        // dead code kept for reference — month-specific goals not used by this widget
        $ref = null;
        if (!preg_match('/^\d{4}-\d{2}$/', $ref)) {
            http_response_code(400);
            echo json_encode(['error' => 'referencia_mes must be YYYY-MM']);
            exit;
        }
        // prefer updating a goal owned by the current user if exists
        $stmt = $pdo->prepare('SELECT id FROM goals WHERE referencia_mes = :ref AND user_id = :uid LIMIT 1');
        $stmt->execute(['ref' => $ref, 'uid' => $uid]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing && isset($existing['id'])) {
            $gid = (int)$existing['id'];
            $ust = $pdo->prepare('UPDATE goals SET valor_meta = :val, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $ust->execute(['val' => $valor, 'id' => $gid]);
            $stmt = $pdo->prepare('SELECT * FROM goals WHERE id = :id');
            $stmt->execute(['id' => $gid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'id' => $gid, 'goal' => $row], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // fallback: if any goal exists for the month (global), update it
        $stmt = $pdo->prepare('SELECT id FROM goals WHERE referencia_mes = :ref LIMIT 1');
        $stmt->execute(['ref' => $ref]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing && isset($existing['id'])) {
            $gid = (int)$existing['id'];
            $ust = $pdo->prepare('UPDATE goals SET valor_meta = :val, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $ust->execute(['val' => $valor, 'id' => $gid]);
            $stmt = $pdo->prepare('SELECT * FROM goals WHERE id = :id');
            $stmt->execute(['id' => $gid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'id' => $gid, 'goal' => $row], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $stmt = $pdo->prepare('INSERT INTO goals (user_id, referencia_mes, valor_meta, tipo_meta) VALUES (:uid, :ref, :val, :tipo)');
        $stmt->execute(['uid' => $_SESSION['user_id'], 'ref' => $ref, 'val' => $valor, 'tipo' => $input['tipo_meta'] ?? 'global']);
        $newId = $pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT * FROM goals WHERE id = :id');
        $stmt->execute(['id' => $newId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'id' => $newId, 'goal' => $row], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'PUT') {
        $valor = parse_meta_value($input['valor_meta'] ?? null);
        $uid   = (int)($_SESSION['user_id'] ?? 0);
        // Upsert igual ao POST: atualiza mais recente ou cria
        $stmt = $pdo->prepare("SELECT id FROM goals WHERE user_id = :uid ORDER BY updated_at DESC, id DESC LIMIT 1");
        $stmt->execute(['uid' => $uid]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing && isset($existing['id'])) {
            $gid = (int)$existing['id'];
            $pdo->prepare('UPDATE goals SET valor_meta = :val, tipo_meta = :tipo, referencia_mes = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
                ->execute(['val' => $valor, 'id' => $gid, 'tipo' => 'single']);
        } else {
            $pdo->prepare('INSERT INTO goals (user_id, referencia_mes, valor_meta, tipo_meta) VALUES (:uid, NULL, :val, :tipo)')
                ->execute(['uid' => $uid, 'val' => $valor, 'tipo' => 'single']);
            $gid = (int)$pdo->lastInsertId();
        }
        $row = $pdo->prepare('SELECT * FROM goals WHERE id = :id');
        $row->execute(['id' => $gid]);
        echo json_encode(['ok' => true, 'goal' => $row->fetch(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'DELETE') {
        // expects ?id=NN
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$id) { http_response_code(400); echo json_encode(['error'=>'id required']); exit; }
        $stmt = $pdo->prepare('DELETE FROM goals WHERE id = :id');
        $stmt->execute(['id' => $id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'message' => $e->getMessage()]);
}
