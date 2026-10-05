<?php
/**
 * setores_test.php: setor do lead e gestor do setor (migration 140).
 *
 *   - Team::garantirPorNome acha o setor pelo nome sem diferenciar caixa nem
 *     acento, cria quando não existe, e nunca cruza contas;
 *   - o gestor é gravado e devolvido por list/findById;
 *   - Team::definirSetorDoCard respeita o setor já escolhido (modo automação) e
 *     recusa setor de outra conta;
 *   - conversa e card andam juntos: escolher o setor na conversa muda o card, e
 *     a conversa que se liga a um card herda o setor dele (o robô marca o card
 *     antes de a conversa existir).
 *
 * Tudo roda em transação desfeita no fim.
 * Uso: php scripts/tests/setores_test.php
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Usuarios\Team;
use App\WhatsAppAgente\WhatsAppMessage;

$OK = 0;
$FALHAS = [];

function ok(string $msg, bool $cond): void
{
    global $OK, $FALHAS;
    if ($cond) { $OK++; echo "  [ok]   $msg\n"; }
    else { $FALHAS[] = $msg; echo "  [FALHA] $msg\n"; }
}

try {
    $pdo = \App\Core\Database::getConnection();
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pronto = Team::temColuna('cards', 'team_id') && Team::temColuna('teams', 'gestor_user_id');
    // Duas contas com funil e um usuário cada; a primeira com um número de WhatsApp.
    $contas = $pdo->query(
        "SELECT a.id,
                (SELECT MIN(c.id) FROM pipeline_columns c WHERE c.account_id = a.id) AS coluna_id,
                (SELECT MIN(u.id) FROM users u WHERE u.account_id = a.id AND u.deleted_at IS NULL) AS user_id,
                (SELECT MIN(wi.id) FROM whatsapp_instances wi WHERE wi.account_id = a.id) AS instance_id
           FROM accounts a
          WHERE a.deleted_at IS NULL
         HAVING coluna_id IS NOT NULL AND user_id IS NOT NULL
          ORDER BY (instance_id IS NULL), a.id
          LIMIT 2"
    )->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $pdo = null; $pronto = false; $contas = [];
    echo '  [pulado] sem banco: ' . $e->getMessage() . "\n";
}

if (!$pdo || !$pronto || count($contas) < 2 || empty($contas[0]['instance_id'])) {
    echo "  [pulado] precisa do banco, da migration 140 e de duas contas com funil (uma com número de WhatsApp)\n";
    exit(0);
}

[$a, $b] = $contas;
$accA = (int)$a['id'];
$accB = (int)$b['id'];

$pdo->beginTransaction();
try {
    echo "\n== 1. Setor pelo nome ==\n";
    $loc = Team::garantirPorNome($accA, 'Locação Teste 140');
    ok('cria o setor quando não existe', $loc > 0);
    ok('mesmo nome devolve o mesmo setor', Team::garantirPorNome($accA, 'Locação Teste 140') === $loc);
    ok('sem diferença de caixa e acento', Team::garantirPorNome($accA, '  locacao   TESTE 140 ') === $loc);
    ok('nome vazio não cria nada', Team::garantirPorNome($accA, '   ') === null);
    $locB = Team::garantirPorNome($accB, 'Locação Teste 140');
    ok('outra conta ganha o próprio setor', $locB > 0 && $locB !== $loc);
    ok('setor da conta A não aparece na conta B', Team::findById($loc, $accB) === null);
    $setor = Team::findById($loc, $accA);
    ok('setor criado pela automação tem cor da paleta', in_array($setor['cor'], Team::CORES, true));

    echo "\n== 2. Gestor ==\n";
    Team::update($loc, ['gestor_user_id' => (int)$a['user_id']]);
    $setor = Team::findById($loc, $accA);
    ok('gestor gravado e devolvido com o nome', $setor['gestor_user_id'] === (int)$a['user_id'] && $setor['gestor_nome'] !== null);
    $lista = array_values(array_filter(Team::list($accA), fn($t) => (int)$t['id'] === $loc));
    ok('a lista também traz o gestor', ($lista[0]['gestor_user_id'] ?? null) === (int)$a['user_id']);
    Team::update($loc, ['gestor_user_id' => null]);
    $setor = Team::findById($loc, $accA);
    ok('tirar o gestor deixa o setor sem gestor', $setor['gestor_user_id'] === null && $setor['gestor_nome'] === null);

    echo "\n== 3. Setor do card ==\n";
    $card = (int)\App\Prospeccao\Card::create([
        'account_id' => $accA, 'cliente_nome' => 'Teste Setor 140', 'coluna_id' => (int)$a['coluna_id'],
        'telefone_whatsapp' => '5511900001400',
    ]);
    $setorDoCard = function (int $id) use ($pdo): ?int {
        $st = $pdo->prepare('SELECT team_id FROM cards WHERE id = ?');
        $st->execute([$id]);
        $v = $st->fetchColumn();
        return $v === null || $v === false ? null : (int)$v;
    };
    ok('a automação marca card sem setor', Team::definirSetorDoCard($accA, $card, $loc, true) && $setorDoCard($card) === $loc);
    $conc = Team::garantirPorNome($accA, 'Concessionária Teste 140');
    ok('a automação não troca setor já escolhido', !Team::definirSetorDoCard($accA, $card, $conc, true) && $setorDoCard($card) === $loc);
    ok('setor de outra conta é recusado', !Team::definirSetorDoCard($accA, $card, $locB) && $setorDoCard($card) === $loc);
    ok('uma pessoa troca o setor', Team::definirSetorDoCard($accA, $card, $conc) && $setorDoCard($card) === $conc);

    echo "\n== 4. Conversa e card juntos ==\n";
    $inst = (int)$a['instance_id'];
    $jid  = '5511900001400@s.whatsapp.net';
    $pdo->prepare(
        'INSERT INTO whatsapp_chats (account_id, instance_id, remote_jid, contact_name, last_message_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, NOW(), NOW(), NOW())'
    )->execute([$accA, $inst, $jid, 'Teste Setor 140']);
    $setorDaConversa = function () use ($pdo, $inst, $jid): ?int {
        $st = $pdo->prepare('SELECT team_id FROM whatsapp_chats WHERE instance_id = ? AND remote_jid = ?');
        $st->execute([$inst, $jid]);
        $v = $st->fetchColumn();
        return $v === null || $v === false ? null : (int)$v;
    };
    $wa = new WhatsAppMessage();
    $wa->linkChat($inst, $jid, ['linked_card_id' => $card]);
    ok('conversa que se liga ao card herda o setor dele', $setorDaConversa() === $conc);

    $wa->setTeam($inst, $jid, $loc);
    ok('escolher o setor na conversa muda o card', $setorDoCard($card) === $loc);
    $wa->setTeam($inst, $jid, null);
    ok('tirar o setor da conversa tira do card', $setorDoCard($card) === null && $setorDaConversa() === null);

    $pdo->prepare('UPDATE whatsapp_chats SET team_id = ? WHERE instance_id = ? AND remote_jid = ?')->execute([$conc, $inst, $jid]);
    $wa->linkChat($inst, $jid, ['linked_card_id' => $card]);
    ok('card sem setor herda o da conversa ao ligar', $setorDoCard($card) === $conc);

    $pdo->prepare('UPDATE whatsapp_chats SET team_id = ? WHERE instance_id = ? AND remote_jid = ?')->execute([$locB, $inst, $jid]);
    Team::definirSetorDoCard($accA, $card, null);
    $wa->linkChat($inst, $jid, ['linked_card_id' => $card]);
    ok('setor de outra conta na conversa não passa para o card', $setorDoCard($card) === null);
} catch (\Throwable $e) {
    ok('sem exceção: ' . $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    $pdo->rollBack();
}

echo "\n" . $OK . ' ok, ' . count($FALHAS) . " falha(s)\n";
exit($FALHAS ? 1 : 0);
