<?php
/**
 * whatsapp_captacao_test.php — mensagem apagada e captação automática de lead.
 *
 * Cobre as duas correções de 10/09/2026, vindas do relato de campo da conta 83:
 *
 *   1. "apago a mensagem no celular e não some da plataforma"
 *      O webhook não tratava `messages.delete`: o evento chegava e caía no
 *      `default` do switch, descartado em silêncio.
 *
 *   2. "esse negócio de vincular está confundindo totalmente elas, elas esperam
 *      algo automático"
 *      Agora a primeira mensagem de uma pessoa nova vira card na prospecção JÁ
 *      vinculado à conversa, quando a conta liga a opção.
 *
 * ESCREVE NO BANCO. Não rode em produção.
 *
 * Uso local: C:\xampp\php\php.exe scripts/tests/whatsapp_captacao_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Prospeccao\CaptacaoAutomatica as CA;
use App\WhatsAppAgente\WhatsAppMessage;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

$FAILS = 0; $PASSES = 0;
function pass(string $m): void { global $PASSES; $PASSES++; echo "  [PASS] $m\n"; }
function fail(string $m): void { global $FAILS;  $FAILS++;  echo "  [FAIL] $m\n"; }
function secao(string $t): void { echo "\n== $t ==\n"; }
function ok(bool $c, string $m): void { $c ? pass($m) : fail($m); }

$INST = (int) $pdo->query('SELECT id FROM whatsapp_instances ORDER BY id LIMIT 1')->fetchColumn();
if (!$INST) { echo "SKIP: nenhuma instância de WhatsApp neste banco.\n"; exit(0); }
$ACC = (int) $pdo->query("SELECT account_id FROM whatsapp_instances WHERE id = $INST")->fetchColumn();
echo "Cenário: instância $INST, conta $ACC\n";

$PREFIXO = 'TESTE-WA';

/*
 * Limpeza defensiva. `whatsapp_chats` tem UNIQUE (instance_id, remote_jid): uma
 * rodada que morresse no meio deixaria o chat lá, o INSERT seguinte cairia no
 * ON DUPLICATE e as asserções passariam a medir o lixo da rodada anterior.
 * É a mesma lição do JID fixo da Fase 1.
 */
$pdo->prepare("DELETE FROM whatsapp_messages WHERE remote_jid LIKE '$PREFIXO%'")->execute();
$pdo->prepare("DELETE FROM whatsapp_chats    WHERE remote_jid LIKE '$PREFIXO%'")->execute();
$pdo->prepare("DELETE FROM cards WHERE cliente_nome LIKE '$PREFIXO%'")->execute();

$criados = ['cards' => [], 'jids' => []];
$m = new WhatsAppMessage();

/** Cria um chat de teste e devolve o jid. */
function chatDeTeste(\PDO $pdo, int $inst, int $acc, string $jid, ?string $preview = null): string
{
    global $criados;
    $pdo->prepare(
        'INSERT INTO whatsapp_chats (instance_id, account_id, remote_jid, contact_name,
                                     last_message_content, last_message_at, created_at, updated_at)
         VALUES (?,?,?,?,?,NOW(),NOW(),NOW())
         ON DUPLICATE KEY UPDATE updated_at = NOW()'
    )->execute([$inst, $acc, $jid, 'Teste', $preview]);
    $criados['jids'][] = $jid;
    return $jid;
}

/* ===================================================================== */
secao('Mensagem apagada no celular some da plataforma');
/* ===================================================================== */

$jid   = $PREFIXO . '-DEL-' . bin2hex(random_bytes(4)) . '@s.whatsapp.net';
$wamid = $PREFIXO . '-' . bin2hex(random_bytes(6));
$texto = 'mensagem que vai ser apagada';

chatDeTeste($pdo, $INST, $ACC, $jid, $texto);
$pdo->prepare(
    'INSERT INTO whatsapp_messages (account_id, instance_id, wamid, remote_jid, message_type,
                                    message_content, direction, status, created_at)
     VALUES (?,?,?,?,?,?,?,?,NOW())'
)->execute([$ACC, $INST, $wamid, $jid, 'text', $texto, 'inbound', 'delivered']);

ok((int) $pdo->query("SELECT is_deleted FROM whatsapp_messages WHERE wamid = '$wamid'")->fetchColumn() === 0,
   'a mensagem começa não-apagada');

ok($m->markDeletedByWamid($INST, $wamid) === true, 'o evento marca a mensagem na primeira vez');
ok((int) $pdo->query("SELECT is_deleted FROM whatsapp_messages WHERE wamid = '$wamid'")->fetchColumn() === 1,
   'a mensagem fica marcada como apagada');

// Sem este UPDATE, a mensagem sumiria de dentro da conversa e continuaria
// estampada na lista, que é onde ela mais incomoda.
ok($pdo->query("SELECT last_message_content FROM whatsapp_chats WHERE remote_jid = '$jid'")->fetchColumn() === 'Mensagem apagada',
   'o PREVIEW da lista de conversas vira "Mensagem apagada"');

ok($m->markDeletedByWamid($INST, $wamid) === false, 'evento repetido é inerte');
ok($m->markDeletedByWamid($INST, $PREFIXO . '-INEXISTENTE') === false, 'wamid desconhecido devolve false');
ok($m->markDeletedByWamid($INST + 9999, $wamid) === false,
   'outra instância NÃO apaga a mensagem (escopo por instance_id)');
ok((int) $pdo->query("SELECT COUNT(*) FROM whatsapp_messages WHERE wamid = '$wamid'")->fetchColumn() === 1,
   'a linha continua no banco: é marca, não DELETE');

// Apagar mensagem antiga não pode reescrever um preview que é de outra.
$w2 = $PREFIXO . '-' . bin2hex(random_bytes(6));
$pdo->prepare(
    'INSERT INTO whatsapp_messages (account_id, instance_id, wamid, remote_jid, message_type,
                                    message_content, direction, status, created_at)
     VALUES (?,?,?,?,?,?,?,?,NOW())'
)->execute([$ACC, $INST, $w2, $jid, 'text', 'uma antiga qualquer', 'inbound', 'delivered']);
$pdo->prepare("UPDATE whatsapp_chats SET last_message_content = 'mensagem mais nova' WHERE remote_jid = ?")
    ->execute([$jid]);
$m->markDeletedByWamid($INST, $w2);
ok($pdo->query("SELECT last_message_content FROM whatsapp_chats WHERE remote_jid = '$jid'")->fetchColumn() === 'mensagem mais nova',
   'apagar mensagem ANTIGA não mexe no preview, que é de outra');

/* ===================================================================== */
secao('Captação automática: quem escreve vira card, já vinculado');
/* ===================================================================== */

$colunas = (int) $pdo->query("SELECT COUNT(*) FROM pipeline_columns WHERE account_id = $ACC")->fetchColumn();
if ($colunas === 0) {
    echo "  [SKIP] a conta $ACC não tem coluna no funil: a captação não pode ser testada\n";
} else {
    CA::definir($ACC, false);
    $j1 = chatDeTeste($pdo, $INST, $ACC, $PREFIXO . '-OFF-5511' . random_int(900000000, 999999999) . '@s.whatsapp.net');
    ok(CA::daMensagem($ACC, $INST, $j1, 'Fulano') === null, 'com a opção DESLIGADA não cria card');

    CA::definir($ACC, true);
    ok(CA::ligada($ACC) === true, 'definir/ligada persistem a opção na conta');

    $tel2 = '5511' . random_int(900000000, 999999999);
    $j2 = chatDeTeste($pdo, $INST, $ACC, $tel2 . '@s.whatsapp.net');
    $id2 = CA::daMensagem($ACC, $INST, $j2, $PREFIXO . ' Maria Lead');
    $criados['cards'][] = $id2;
    ok($id2 > 0, 'ligada: cria o card na primeira mensagem');

    $c = $pdo->query('SELECT cliente_nome, telefone_whatsapp, coluna_id FROM cards WHERE id = ' . (int) $id2)
             ->fetch(\PDO::FETCH_ASSOC);
    ok($c['cliente_nome'] === $PREFIXO . ' Maria Lead', 'o card nasce com o NOME que o WhatsApp mandou');
    ok(strlen((string) $c['telefone_whatsapp']) >= 10, 'e com o TELEFONE');
    ok(!empty($c['coluna_id']), 'e dentro de uma coluna do funil (card sem coluna some da tela)');

    $vinc = $pdo->prepare('SELECT linked_card_id FROM whatsapp_chats WHERE remote_jid = ? AND instance_id = ?');
    $vinc->execute([$j2, $INST]);
    ok((int) $vinc->fetchColumn() === (int) $id2,
       'a CONVERSA já fica vinculada ao card: é isso que dispensa o "Vincular"');

    ok(CA::daMensagem($ACC, $INST, $j2, 'Maria de novo') === null,
       'segunda mensagem da mesma pessoa NÃO cria outro card');

    // A trava que mais importa: o mesmo número gravado em outro formato.
    $j3 = chatDeTeste($pdo, $INST, $ACC, substr($tel2, 2) . '@s.whatsapp.net');
    ok(CA::daMensagem($ACC, $INST, $j3, 'Maria sem 55') === null,
       'mesmo número em OUTRO formato (sem o 55) não duplica o card');

    $jg = chatDeTeste($pdo, $INST, $ACC, $PREFIXO . '-grupo-' . bin2hex(random_bytes(4)) . '@g.us');
    ok(CA::daMensagem($ACC, $INST, $jg, 'Grupo Qualquer') === null, 'grupo NÃO vira card');

    $j4 = chatDeTeste($pdo, $INST, $ACC, '5511' . random_int(900000000, 999999999) . '@s.whatsapp.net');
    $pdo->prepare('UPDATE whatsapp_chats SET linked_processo_id = 1 WHERE remote_jid = ? AND instance_id = ?')
        ->execute([$j4, $INST]);
    ok(CA::daMensagem($ACC, $INST, $j4, 'Ja Vinculado') === null,
       'conversa JÁ vinculada a processo não vira card novo');

    // @lid é o identificador de privacidade: sem telefone o card não serviria
    // para ligar de volta, então não nasce.
    $jl = chatDeTeste($pdo, $INST, $ACC, '199384756382910@lid');
    ok(CA::daMensagem($ACC, $INST, $jl, 'Lid Sem Numero') === null, '@lid sem telefone discável NÃO vira card');

    $tel5 = '5511' . random_int(900000000, 999999999);
    $j5   = chatDeTeste($pdo, $INST, $ACC, $tel5 . '@s.whatsapp.net');
    $id5  = CA::daMensagem($ACC, $INST, $j5, null);
    $criados['cards'][] = $id5;
    $n5 = (string) $pdo->query('SELECT cliente_nome FROM cards WHERE id = ' . (int) $id5)->fetchColumn();
    ok($id5 > 0 && str_contains($n5, '('), "sem pushName o card usa o telefone legível ($n5), não \"Contato\"");

    $h = $pdo->query("SELECT usuario_id FROM card_history
                       WHERE card_id = " . (int) $id2 . " AND acao = 'captado_whatsapp'")
             ->fetch(\PDO::FETCH_ASSOC);
    ok($h !== false && $h['usuario_id'] === null,
       'o histórico registra captado_whatsapp SEM usuário: foi o sistema, não uma pessoa');

    ok(CA::telefoneLegivel('5511984358434') === '(11) 98435-8434', 'telefoneLegivel formata o número de verdade');

    CA::definir($ACC, false);
}

/* ===================================================================== */
/* limpeza                                                                */
/* ===================================================================== */

echo "\n== limpeza ==\n";
foreach (array_filter($criados['cards']) as $id) {
    try { $pdo->prepare('DELETE FROM cards WHERE id = ?')->execute([$id]); } catch (\Throwable $e) {}
}
foreach (array_unique($criados['jids']) as $j) {
    try {
        $pdo->prepare('DELETE FROM whatsapp_messages WHERE remote_jid = ? AND instance_id = ?')->execute([$j, $INST]);
        $pdo->prepare('DELETE FROM whatsapp_chats    WHERE remote_jid = ? AND instance_id = ?')->execute([$j, $INST]);
    } catch (\Throwable $e) {}
}
try {
    $pdo->prepare('DELETE FROM whatsapp_settings WHERE account_id = ? AND config_key = ?')
        ->execute([$ACC, CA::CHAVE]);
} catch (\Throwable $e) {}

$sobrou = (int) $pdo->query("SELECT COUNT(*) FROM cards WHERE cliente_nome LIKE '$PREFIXO%'")->fetchColumn();
ok($sobrou === 0, 'nenhum card de teste ficou no banco');
echo "  histórico de auditoria permanece (imutável por trigger), órfão e invisível\n";

printf("\n%s  %d PASS, %d FAIL\n", $FAILS === 0 ? 'OK' : 'FALHOU', $PASSES, $FAILS);
exit($FAILS === 0 ? 0 : 1);
