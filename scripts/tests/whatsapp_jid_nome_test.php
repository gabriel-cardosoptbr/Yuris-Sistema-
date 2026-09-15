<?php
/**
 * whatsapp_jid_nome_test.php — a conversa continua sendo UMA, e com o nome de
 * quem está do outro lado.
 *
 * ---------------------------------------------------------------------------
 * O QUE ACONTECEU EM 15/09/2026, E POR QUE ESTE TESTE EXISTE
 * ---------------------------------------------------------------------------
 * A Evolution subiu para 2.3.7 e passou a entregar o telefone real em
 * `key.remoteJid`, no lugar do `@lid`. No canal da conta 83 a virada tem hora:
 * as 23 mensagens 1:1 das 17h vieram como `@lid` e as 7 das 18h vieram como
 * telefone.
 *
 * O problema é que o payload novo não manda mais o par junto (`remoteJidAlt`
 * passou a repetir o próprio telefone). Quem já conversava pelo `@lid` virou,
 * para o sistema, uma pessoa nova: conversa nova, vazia, com o histórico preso
 * na antiga. Seis pessoas nas primeiras horas.
 *
 * Os dois casos que este arquivo trava:
 *
 *  1. RESOLUÇÃO: `resolvePhoneJid` tem que consultar a tabela de identidade, que
 *     é a única parte do sistema que ainda sabe qual telefone é qual `@lid`. E
 *     tem que continuar recusando um "telefone" que é só o número interno do
 *     LID, porque um número que não disca é pior que nenhum.
 *
 *  2. NOME: `pushName` é o nome de QUEM MANDOU. Na mensagem que nós enviamos,
 *     isso é o dono do número. O Sincronizar pegava a primeira mensagem que
 *     encontrasse, e 21 conversas passaram a exibir "Advogada Maria Fernanda" no
 *     lugar do nome do cliente.
 *
 * ESCREVE NO BANCO (linhas próprias, com marca, apagadas no fim). Não rode em
 * produção.
 *
 * Uso local: C:\xampp\php\php.exe scripts/tests/whatsapp_jid_nome_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\WhatsAppAgente\WhatsAppMessage;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

$FAILS = 0; $PASSES = 0;
function pass(string $m): void { global $PASSES; $PASSES++; echo "  [PASS] $m\n"; }
function fail(string $m): void { global $FAILS;  $FAILS++;  echo "  [FAIL] $m\n"; }
function secao(string $t): void { echo "\n== $t ==\n"; }
function ok(bool $c, string $m): void { $c ? pass($m) : fail($m); }
function eq($esp, $obt, string $m): void {
    if ($esp === $obt) { pass($m); return; }
    fail($m . ' (esperado ' . var_export($esp, true) . ', obtido ' . var_export($obt, true) . ')');
}

$st = $pdo->query('SELECT id, account_id FROM whatsapp_instances ORDER BY id LIMIT 1');
$inst = $st->fetch(\PDO::FETCH_ASSOC);
if (!$inst) { echo "SKIP: nenhuma instância de WhatsApp cadastrada.\n"; exit(0); }
$INST = (int) $inst['id'];
$ACC  = (int) $inst['account_id'];
echo "Cenário: instância $INST da conta $ACC\n";

/*
 * Endereços com marca própria: 5599 não é DDD que exista e o LID começa com
 * 7777, então a limpeza do fim apaga por padrão sem risco de levar dado real.
 */
$FONE       = '5599900020002';
$JID_FONE   = $FONE . '@s.whatsapp.net';
$LID        = '7777000200020@lid';
$LID_ORFAO  = '7777000300030@lid';   // nunca vai ter telefone conhecido
$LID_FALSO  = '5599900040004@lid';   // dígitos que PARECEM telefone, mas são do LID

function limpar(PDO $pdo, int $inst, array $jids): void {
    foreach ($jids as $j) {
        $pdo->prepare('DELETE FROM whatsapp_identidades WHERE instance_id=? AND (lid=? OR jid=?)')->execute([$inst, $j, $j]);
        $pdo->prepare('DELETE FROM whatsapp_contacts    WHERE instance_id=? AND remote_jid=?')->execute([$inst, $j]);
        $pdo->prepare('DELETE FROM whatsapp_group_members WHERE instance_id=? AND participant_jid=?')->execute([$inst, $j]);
    }
}
limpar($pdo, $INST, [$LID, $LID_ORFAO, $LID_FALSO, $JID_FONE]);

/* ===================================================================== */
secao('1. resolvePhoneJid: a identidade é consultada');
/* ===================================================================== */

/*
 * Só a identidade sabe o par. Nem `whatsapp_contacts`, nem `whatsapp_group_members`
 * recebem nada aqui de propósito: se o teste passar, foi pela fonte nova.
 */
$pdo->prepare(
    'INSERT INTO whatsapp_identidades (account_id, instance_id, phone, jid, lid, nome, nome_origem, nome_peso)
     VALUES (?,?,?,?,?,?,?,?)'
)->execute([$ACC, $INST, $FONE, $JID_FONE, $LID, 'Contato de Teste', 'teste', 100]);

eq(
    $JID_FONE,
    WhatsAppMessage::resolvePhoneJid($pdo, $INST, $LID),
    'o @lid vira o JID de telefone usando SÓ a tabela de identidade'
);

eq(
    $LID_ORFAO,
    WhatsAppMessage::resolvePhoneJid($pdo, $INST, $LID_ORFAO),
    '@lid sem telefone conhecido volta intacto (não inventa número)'
);

eq(
    $JID_FONE,
    WhatsAppMessage::resolvePhoneJid($pdo, $INST, $JID_FONE),
    'JID de telefone passa direto, sem consulta'
);

eq(
    null,
    WhatsAppMessage::resolvePhoneJid($pdo, $INST, null),
    'null continua null'
);

/* Isolamento por canal: o mesmo @lid em OUTRA instância não pode resolver. */
eq(
    $LID,
    WhatsAppMessage::resolvePhoneJid($pdo, $INST + 100000, $LID),
    'a identidade de um canal não resolve endereço de outro canal'
);

/* ===================================================================== */
secao('2. a trava do número que não disca');
/* ===================================================================== */

/*
 * O caso que já mordeu uma vez: gravar como "telefone" os próprios dígitos do
 * LID. Aqui o LID tem 13 dígitos, que passam em qualquer validação de tamanho.
 * Se a trava cair, o sistema devolve um número que ninguém atende.
 */
$digitosFalsos = '5599900040004';
$pdo->prepare(
    'INSERT INTO whatsapp_identidades (account_id, instance_id, phone, jid, lid, nome, nome_origem, nome_peso)
     VALUES (?,?,?,?,?,?,?,?)'
)->execute([$ACC, $INST, $digitosFalsos, null, $LID_FALSO, 'Falso', 'teste', 10]);

eq(
    $LID_FALSO,
    WhatsAppMessage::resolvePhoneJid($pdo, $INST, $LID_FALSO),
    'um "telefone" igual aos dígitos do próprio LID é recusado'
);

/* ===================================================================== */
secao('3. nomeDeContatoNaMensagem: pushName é de quem MANDOU');
/* ===================================================================== */

$recebida = ['key' => ['remoteJid' => $JID_FONE, 'fromMe' => false], 'pushName' => 'Iago Lima'];
$enviada  = ['key' => ['remoteJid' => $JID_FONE, 'fromMe' => true],  'pushName' => 'Advogada Maria Fernanda'];

eq('Iago Lima', WhatsAppMessage::nomeDeContatoNaMensagem($recebida), 'mensagem recebida entrega o nome do contato');
eq(null,        WhatsAppMessage::nomeDeContatoNaMensagem($enviada),  'mensagem ENVIADA não entrega nome (é o nome do dono do número)');

eq(null, WhatsAppMessage::nomeDeContatoNaMensagem(
    ['key' => ['fromMe' => false], 'pushName' => '5511999999999']
), '"nome" que é só número é recusado');

eq(null, WhatsAppMessage::nomeDeContatoNaMensagem(
    ['key' => ['fromMe' => false], 'pushName' => 'Você']
), 'auto-nome "Você" é recusado');

eq(null, WhatsAppMessage::nomeDeContatoNaMensagem(
    ['key' => ['fromMe' => false], 'pushName' => '   ']
), 'pushName em branco é recusado');

eq(null, WhatsAppMessage::nomeDeContatoNaMensagem(
    ['key' => ['remoteJid' => $JID_FONE]]
), 'mensagem sem pushName é recusada');

/* fromMe ausente é mensagem recebida: o Baileys omite o campo quando é false. */
eq('Fulano', WhatsAppMessage::nomeDeContatoNaMensagem(
    ['key' => ['remoteJid' => $JID_FONE], 'pushName' => 'Fulano']
), 'fromMe ausente conta como recebida');

/* ===================================================================== */
secao('4. a regra da lista de conversas do Sincronizar');
/* ===================================================================== */

/*
 * Reproduz o laço do sync.php: várias mensagens da MESMA conversa, a nossa
 * primeiro. Era exatamente esta ordem que rotulava a conversa com o nome da
 * advogada, porque o código parava na primeira que encontrasse.
 */
$lote = [
    ['key' => ['remoteJid' => $JID_FONE, 'fromMe' => true],  'pushName' => 'Advogada Maria Fernanda'],
    ['key' => ['remoteJid' => $JID_FONE, 'fromMe' => true],  'pushName' => 'Advogada Maria Fernanda'],
    ['key' => ['remoteJid' => $JID_FONE, 'fromMe' => false], 'pushName' => 'Fernanda dos Santos'],
];
$mapa = [];
foreach ($lote as $r) {
    $jid = $r['key']['remoteJid'] ?? null;
    if (!$jid) continue;
    if (!isset($mapa[$jid])) $mapa[$jid] = ['pushName' => null];
    if ($mapa[$jid]['pushName'] === null) {
        $mapa[$jid]['pushName'] = WhatsAppMessage::nomeDeContatoNaMensagem($r);
    }
}
eq('Fernanda dos Santos', $mapa[$JID_FONE]['pushName'],
   'a conversa recebe o nome do CLIENTE mesmo quando nossas mensagens vêm primeiro');

$soNossas = [['key' => ['remoteJid' => $JID_FONE, 'fromMe' => true], 'pushName' => 'Advogada Maria Fernanda']];
$m2 = ['pushName' => null];
foreach ($soNossas as $r) {
    if ($m2['pushName'] === null) $m2['pushName'] = WhatsAppMessage::nomeDeContatoNaMensagem($r);
}
eq(null, $m2['pushName'],
   'conversa só com mensagem nossa fica SEM nome (melhor vazio que o nome errado)');

/* ===================================================================== */
secao('5. as regras estão no código, não só neste teste');
/* ===================================================================== */

/*
 * Os casos acima montam o laço do sync aqui dentro. Isso prova a REGRA, mas não
 * prova que o sync.php a usa: se alguém reverter o arquivo, os testes acima
 * continuam verdes. As asserções seguintes olham o código de verdade.
 */
$sync = (string) file_get_contents(__DIR__ . '/../../public/api/whatsapp/sync.php');

ok(
    (bool) preg_match('/\$jidMap\[\$jid\]\[.pushName.\]\s*=\s*WhatsAppMessage::nomeDeContatoNaMensagem\(/', $sync),
    'sync.php deriva o nome da conversa por nomeDeContatoNaMensagem()'
);
ok(
    !preg_match('/\[.pushName.\s*=>\s*\$r\[.pushName.\]/', $sync),
    'sync.php não pega mais o pushName cru da primeira mensagem'
);
ok(
    (bool) preg_match('/contact_name\s*=\s*IF\(COALESCE\(is_manual_name,0\)\s*=\s*0/', $sync),
    'o INSERT de conversas do sync.php respeita a renomeação manual'
);
ok(
    !preg_match('/\$groupMap\[\$jid\]\[.name.\]\s*\?\?\s*\$info\[.pushName.\]/', $sync),
    'grupo não herda mais o nome de um participante'
);

$msg = (string) file_get_contents(__DIR__ . '/../../app/WhatsAppAgente/WhatsAppMessage.php');
ok(
    (bool) preg_match('/FROM whatsapp_identidades\s*\n\s*WHERE instance_id = \? AND lid = \?/', $msg),
    'resolvePhoneJid consulta whatsapp_identidades'
);
ok(
    (bool) preg_match('/!==\s*\$digitosLid/', $msg),
    'resolvePhoneJid compara o telefone com os dígitos do LID antes de aceitar'
);

$dedupe = (string) file_get_contents(__DIR__ . '/../../scripts/whatsapp_dedupe_lid.php');
ok(
    (bool) preg_match('/WhatsAppMessage::resolvePhoneJid\(\$pdo, \$inst, \$lidJid\)/', $dedupe),
    'o script de fusão usa a MESMA resolução do runtime'
);
ok(
    (bool) preg_match('/\[B2\]/', $dedupe),
    'o script de fusão tem o passo que limpa o nome do dono do número'
);

/* ===================================================================== */
secao('limpeza');
/* ===================================================================== */

limpar($pdo, $INST, [$LID, $LID_ORFAO, $LID_FALSO, $JID_FONE]);
$sobrou = 0;
foreach ([$LID, $LID_ORFAO, $LID_FALSO, $JID_FONE] as $j) {
    $s = $pdo->prepare('SELECT COUNT(*) FROM whatsapp_identidades WHERE instance_id=? AND (lid=? OR jid=?)');
    $s->execute([$INST, $j, $j]);
    $sobrou += (int) $s->fetchColumn();
}
eq(0, $sobrou, 'nenhuma linha de teste sobrou no banco');

echo "\n== RESULTADO ==\n";
echo "  passou: $PASSES\n  falhou: $FAILS\n";
exit($FAILS > 0 ? 1 : 0);
