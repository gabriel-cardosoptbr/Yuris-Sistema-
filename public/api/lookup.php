<?php
/**
 * API: /api/lookup.php
 *
 * Busca unificada por código. Aceita TRÊS formatos diferentes (princípio
 * geral do sistema: "aceite um ou outro"):
 *   GET ?codigo=XXXX
 *     1. accounts.codigo_vinculo  (xxxx-xxxx-xxxx-xxxx) → tipo='conta'
 *        - Pode ser matriz/filial/advogado solo. Reusa o mesmo formato.
 *     2. users.codigo_advogado    (ADV-XXXXXX) → tipo='advogado'
 *        - ID universal do advogado, em qualquer conta.
 *     3. users.codigo_vinculo     (xxxx-xxxx-xxxx-xxxx) → tipo='advogado'
 *        - Código pessoal alternativo do user. Aceito por completude.
 *     - senão 404
 *
 * REGRA DE NEGÓCIO (importante):
 *   Advogado solo pode dar QUALQUER um dos 3 e tudo resolve pra mesma conta.
 *   Matriz/Filial são vinculadas SEMPRE pelo accounts.codigo_vinculo (caso 1)
 *   — endpoint /api/account_vinculos.php é mais restrito por design (não
 *   aceita codigo de user pra evitar vincular conta inteira via user random).
 *
 * Usado pelo modal de "Adicionar vínculo" para descobrir o que o usuário colou.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Core\AccountContext;

session_start();
header('Content-Type: application/json; charset=utf-8');

$ctx = AccountContext::fromSession();  // exige sessão válida

// ─── LGPD P1 (2C.1) — rate limit anti-enumeração ────────────────────────────
// Antes, lookup retornava email/nome/conta de qualquer advogado dado o código
// ADV-XXXXXX (6 chars = 16M combinações = ~24h de brute-force). Atacante
// poderia coletar todos os emails da plataforma.
// Mitigações aplicadas:
//   • Rate limit por sessão: 30 lookups/min (suficiente pra UI legítima)
//   • Resposta minimal: id + tipo + nome ABREVIADO (primeiro nome + inicial)
//     — sem email, sem account_nome, sem account_tipo. Bastante pra a UI
//     confirmar "achei o advogado", sem permitir coleta automatizada.
$now = time();
$bucket = $_SESSION['_lookup_bucket'] ?? ['start' => $now, 'count' => 0];
if ($now - (int)$bucket['start'] > 60) {
    $bucket = ['start' => $now, 'count' => 0];
}
$bucket['count']++;
$_SESSION['_lookup_bucket'] = $bucket;
if ($bucket['count'] > 30) {
    http_response_code(429);
    echo json_encode(['error' => 'Muitas consultas. Aguarde 1 minuto.']);
    exit;
}
// ────────────────────────────────────────────────────────────────────────────

$codigo = trim($_GET['codigo'] ?? '');
if ($codigo === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetro codigo é obrigatório']);
    exit;
}

$pdo = Database::getConnection();

// 1) Tenta como conta (matriz/filial/advogado)
// Aceita active/trial/overdue — mesma whitelist do AuthController (uma conta
// em teste ou inadimplente continua usável; só suspended/cancelled/inactive
// ficam fora). Antes filtrava só 'active' e contas em trial não eram achadas.
$stmt = $pdo->prepare(
    "SELECT id, nome, tipo, plano, status
     FROM accounts
     WHERE codigo_vinculo = :codigo
       AND deleted_at IS NULL
       AND status IN ('active','trial','overdue')
     LIMIT 1"
);
$stmt->execute(['codigo' => $codigo]);
$conta = $stmt->fetch(\PDO::FETCH_ASSOC);
if ($conta) {
    echo json_encode([
        'tipo' => 'conta',
        'data' => [
            'id'     => (int)$conta['id'],
            'nome'   => $conta['nome'],
            'tipo'   => $conta['tipo'],
            'plano'  => $conta['plano'],
            'status' => $conta['status'],
        ],
    ]);
    exit;
}

// 2) Tenta como advogado individual.
//    Aceita 2 campos: codigo_advogado (ADV-XXXXXX, ID universal de advogado)
//    OU codigo_vinculo (uuid pessoal do user). Ambos identificam a mesma pessoa.
//    Match unico: o WHERE inteiro só retorna 1 user (LIMIT 1).
$stmt = $pdo->prepare(
    "SELECT u.id, u.nome, u.login, u.codigo_advogado, u.account_id,
            a.nome AS account_nome, a.tipo AS account_tipo
     FROM users u
     LEFT JOIN accounts a ON a.id = u.account_id
     WHERE (u.codigo_advogado = :codigo OR u.codigo_vinculo = :codigo)
       AND u.deleted_at IS NULL AND u.status = 'active'
     LIMIT 1"
);
$stmt->execute(['codigo' => $codigo]);
$adv = $stmt->fetch(\PDO::FETCH_ASSOC);
if ($adv) {
    // P1 LGPD (2C.1): resposta minimal — só o suficiente pra UI confirmar
    // identificação. NÃO retorna email, account_nome, account_tipo (eram
    // exposições para enumeração).
    // Nome é abreviado: "Silvana Monteiro" → "Silvana M."
    $parts = preg_split('/\s+/', trim((string)$adv['nome']));
    $nomeAbrev = (string)($parts[0] ?? '');
    if (isset($parts[1])) $nomeAbrev .= ' ' . mb_strtoupper(mb_substr($parts[1], 0, 1)) . '.';
    echo json_encode([
        'tipo' => 'advogado',
        'data' => [
            'user_id'         => (int)$adv['id'],
            'nome'            => $nomeAbrev,
            'codigo_advogado' => $adv['codigo_advogado'],
            // account_id é só um ID numérico interno (não é PII como email/nome),
            // então expô-lo não reabre a enumeração mitigada acima. O frontend
            // (buscarDestinoModulo em escritorios.php) precisa dele para escopar
            // o resource_share à conta do advogado (to_account_id + to_user_id).
            'account_id'      => (int)$adv['account_id'],
        ],
    ]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Código não encontrado']);
