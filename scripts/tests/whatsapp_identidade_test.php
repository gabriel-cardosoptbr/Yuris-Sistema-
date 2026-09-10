<?php
/**
 * whatsapp_identidade_test.php — a identidade consolidada do contato.
 *
 * O que está sob teste é a resposta ao relato de campo de 10/09/2026: "só
 * aparece Contato, não tem como identificar o número". A causa medida foi que o
 * WhatsApp endereça por `@lid`, que não é o telefone, e a Evolution não guarda o
 * vínculo entre os dois. O vínculo chega no payload, em `remoteJidAlt` /
 * `participantAlt`, e esta classe é quem o transforma em UMA pessoa.
 *
 * Os casos que realmente importam, e por quê:
 *
 *  - `enderecosDaKey` lendo o payload REAL do Baileys 7, inclusive dentro de
 *    grupo, onde a pessoa é o `participant` e não o `remoteJid`.
 *  - a FUSÃO: o LID e o telefone já existiam como duas identidades e o payload
 *    prova que são a mesma pessoa. Tem que sobrar UMA, a mais antiga, com o
 *    melhor nome, sem violar os dois UNIQUE.
 *  - a hierarquia de nome: pushName do WhatsApp NUNCA apaga o nome que alguém
 *    digitou no CRM. Sem isso a tela piora sozinha a cada mensagem.
 *  - isolamento por instância: o mesmo telefone em dois canais são duas
 *    identidades, porque canal é fronteira de tenant aqui.
 *
 * ESCREVE NO BANCO. Não rode em produção.
 *
 * Uso local: C:\xampp\php\php.exe scripts/tests/whatsapp_identidade_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\WhatsAppAgente\Identidade;

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
 * Endereços de teste com marca própria. O prefixo 5599 não existe como DDD e o
 * LID começa com 7777, para a limpeza poder apagar por padrão sem risco de levar
 * junto dado real.
 */
$FONE_A = '5599900010001';
$FONE_B = '5599900010002';
$FONE_C = '5599900010003';
$FONE_D = '5599900010004';
$LID_A  = '777700000000001@lid';
$LID_B  = '777700000000002@lid';
$LID_C  = '777700000000003@lid';

$limpar = function () use ($pdo) {
    $pdo->exec("DELETE FROM whatsapp_identidades
                 WHERE phone LIKE '55999000%' OR jid LIKE '55999000%'
                    OR lid LIKE '7777%' OR lid = '7623902498956@lid'");
};
$limpar();

/* ===================================================================== */
secao('analisarJid separa telefone, LID, grupo e o resto sem adivinhar');

eq('telefone', Identidade::analisarJid('5511997529604@s.whatsapp.net')['tipo'], 'JID de telefone é telefone');
eq('5511997529604', Identidade::analisarJid('5511997529604@s.whatsapp.net')['digitos'], 'extrai os dígitos do telefone');
eq('lid',        Identidade::analisarJid('232366454870257@lid')['tipo'],        '@lid é LID');
eq('grupo',      Identidade::analisarJid('120363000000000000@g.us')['tipo'],   '@g.us é grupo');
eq('broadcast',  Identidade::analisarJid('status@broadcast')['tipo'],          '@broadcast é broadcast');
eq('newsletter', Identidade::analisarJid('120363111@newsletter')['tipo'],      '@newsletter é canal');
eq('desconhecido', Identidade::analisarJid('')['tipo'],                        'vazio é desconhecido');
eq('desconhecido', Identidade::analisarJid(null)['tipo'],                      'null é desconhecido');

// Sem sufixo, o tamanho é o que separa: LID tem 15 dígitos ou mais e não é discável.
eq('telefone',    Identidade::analisarJid('5511997529604')['tipo'],   'número solto de 13 dígitos é telefone');
eq('5511997529604@s.whatsapp.net', Identidade::analisarJid('5511997529604')['jid'],
   'número solto ganha o sufixo, para virar a mesma chave do JID');
eq('desconhecido', Identidade::analisarJid('232366454870257')['tipo'],
   'número solto de 15 dígitos NÃO é tratado como telefone (é LID disfarçado)');

/* ===================================================================== */
secao('telefoneValido e sufixo de 8 dígitos');

ok(Identidade::telefoneValido('5511997529604'),  '13 dígitos vale');
ok(Identidade::telefoneValido('1197529604'),     '10 dígitos vale');
ok(!Identidade::telefoneValido('997529604444444'), '15 dígitos não vale');
ok(!Identidade::telefoneValido('123'),           'curto demais não vale');
ok(!Identidade::telefoneValido(null),            'null não vale');
ok(!Identidade::telefoneValido(''),              'vazio não vale');

// Os últimos 8 são o que sobrevive a DDI e ao nono dígito.
eq('97529604', Identidade::sufixo('5511997529604'), 'sufixo com DDI e nono dígito');
eq('97529604', Identidade::sufixo('11997529604'),   'sufixo sem DDI');
eq(Identidade::sufixo('5511997529604'), Identidade::sufixo('11997529604'),
   'o MESMO número escrito de duas formas tem o mesmo sufixo');
eq(null, Identidade::sufixo('1234'), 'curto demais não tem sufixo');

/* ===================================================================== */
secao('enderecosDaKey lê o payload real do Baileys 7');

// 1:1 endereçado por LID, com o telefone real no Alt. É o caso da conta 83.
$r = Identidade::enderecosDaKey([
    'remoteJid'      => '232366454870257@lid',
    'remoteJidAlt'   => '5511997529604@s.whatsapp.net',
    'addressingMode' => 'lid',
]);
eq('232366454870257@lid', $r['lid'],  'pega o LID do remoteJid');
eq('5511997529604@s.whatsapp.net', $r['jid'], 'pega o JID do remoteJidAlt');
eq('5511997529604', $r['phone'],      'e o telefone real junto');
ok($r['de_grupo'] === false,          'não é grupo');

// Sem o Alt: só o LID, e nada inventado.
$r = Identidade::enderecosDaKey(['remoteJid' => '232366454870257@lid']);
eq('232366454870257@lid', $r['lid'], 'sem Alt, o LID entra');
eq(null, $r['jid'],   'sem Alt, NÃO inventa JID');
eq(null, $r['phone'], 'sem Alt, NÃO inventa telefone');

// 1:1 normal por telefone.
$r = Identidade::enderecosDaKey(['remoteJid' => '5511997529604@s.whatsapp.net']);
eq('5511997529604', $r['phone'], 'conversa por telefone dá o telefone');
eq(null, $r['lid'], 'e nenhum LID');

// GRUPO: a pessoa é o participant, o remoteJid é o grupo. Se isso for lido
// errado, o número do GRUPO vira o "telefone" do contato.
$r = Identidade::enderecosDaKey([
    'remoteJid'      => '120363000000000000@g.us',
    'participant'    => '232366454870257@lid',
    'participantAlt' => '5511997529604@s.whatsapp.net',
]);
ok($r['de_grupo'] === true,           'reconhece o grupo');
eq('232366454870257@lid', $r['lid'],  'em grupo, o LID vem do participant');
eq('5511997529604', $r['phone'],      'em grupo, o telefone vem do participantAlt');

// Grupo sem participant: não há pessoa a registrar, e não pode sobrar o grupo.
$r = Identidade::enderecosDaKey(['remoteJid' => '120363000000000000@g.us']);
eq(null, $r['lid'],   'grupo sem participant não vira LID');
eq(null, $r['phone'], 'o número do GRUPO nunca vira telefone de pessoa');

/* ===================================================================== */
secao('melhorNome: fonte mais forte manda, empate só aceita nome mais completo');

$P = Identidade::PESOS;
ok($P['manual'] > $P['crm'],             'manual pesa mais que CRM');
ok($P['crm'] > $P['contacts_upsert'],    'CRM pesa mais que a agenda');
ok($P['contacts_upsert'] > $P['messages_upsert'], 'agenda pesa mais que pushName');
ok($P['messages_upsert'] > $P['fallback'], 'pushName pesa mais que o fallback');

ok(Identidade::melhorNome('', 0, 'João', $P['messages_upsert']),
   'qualquer nome é melhor que nenhum');
ok(!Identidade::melhorNome('João Silva', $P['crm'], '', $P['manual']),
   'nome vazio nunca entra, nem vindo de fonte forte');
ok(Identidade::melhorNome('João', $P['messages_upsert'], 'João da Silva', $P['crm']),
   'CRM sobrescreve pushName');
ok(!Identidade::melhorNome('João da Silva Ferreira', $P['crm'], 'João', $P['messages_upsert']),
   'pushName NÃO apaga o nome do CRM (o bug que a hierarquia existe para evitar)');
ok(Identidade::melhorNome('João', $P['crm'], 'João Silva', $P['crm']),
   'mesma fonte: aceita o nome mais completo');
ok(!Identidade::melhorNome('João Silva', $P['crm'], 'João', $P['crm']),
   'mesma fonte: NÃO volta para o nome curto');
ok(!Identidade::melhorNome('João Silva', $P['crm'], 'Maria Souza', $P['crm']),
   'mesma fonte: nome sem relação não troca (senão fica alternando)');
ok(!Identidade::melhorNome('João', $P['crm'], 'João', $P['crm']),
   'nome idêntico não é troca');

/* ===================================================================== */
secao('registrar cria, é idempotente e completa o que falta');

$idA = Identidade::registrar($ACC, $INST, null, $LID_A, null, 'Fulano LID', 'messages_upsert');
ok($idA !== null, 'cria identidade só com LID');

$idA2 = Identidade::registrar($ACC, $INST, null, $LID_A, null, 'Fulano LID', 'messages_upsert');
eq($idA, $idA2, 'a mesma mensagem de novo devolve a MESMA identidade (idempotente)');

$n = (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_identidades WHERE lid = '$LID_A'")->fetchColumn();
eq(1, $n, 'e não duplicou a linha');

// Agora chega um payload com o Alt: o telefone entra na identidade que já existe.
Identidade::registrar($ACC, $INST, $FONE_A . '@s.whatsapp.net', $LID_A, $FONE_A, null, 'messages_upsert');
$linha = Identidade::porEndereco($INST, $LID_A);
eq($FONE_A, $linha['phone'], 'o remoteJidAlt completou o telefone da identidade que já existia');
eq($FONE_A . '@s.whatsapp.net', $linha['jid'], 'e o JID junto');

// Telefone sem JID: o JID é montado, para as duas colunas contarem a mesma história.
$idB = Identidade::registrar($ACC, $INST, null, null, $FONE_B, 'Beltrano', 'crm');
$linhaB = Identidade::porEndereco($INST, $FONE_B . '@s.whatsapp.net');
ok($linhaB !== null, 'identidade criada só com telefone é encontrável pelo JID');
eq($FONE_B . '@s.whatsapp.net', $linhaB['jid'], 'o JID foi montado a partir do telefone');

// Sem endereço nenhum não há o que registrar.
eq(null, Identidade::registrar($ACC, $INST, null, null, null, 'Sem Endereço', 'crm'),
   'sem nenhum endereço, não cria nada');
eq(null, Identidade::registrar($ACC, $INST, null, null, '123', 'Fone Curto', 'crm'),
   'telefone inválido sozinho não cria identidade');

/* ===================================================================== */
secao('o LID nunca vira telefone');

/*
 * Caso REAL, achado no backfill de produção de 10/09/2026: o LID
 * `7623902498956@lid` entrou com "telefone" 7623902498956, porque
 * `whatsapp_chats.phone` às vezes guarda o próprio LID e ele por acaso tem 13
 * dígitos. Um número desses não disca: mostrá-lo é pior que não mostrar nada.
 */
$LID_FALSO = '7623902498956@lid';
$idFalso = Identidade::registrar($ACC, $INST, null, $LID_FALSO, '7623902498956', 'Falso Telefone', 'crm');
ok($idFalso !== null, 'a identidade é criada mesmo assim (o LID vale, é endereço)');
$lf = Identidade::porEndereco($INST, $LID_FALSO);
eq(null, $lf['phone'], 'mas os dígitos do próprio LID NÃO viram telefone');
eq(null, $lf['jid'],   'e nenhum JID é montado a partir deles');
$pdo->prepare('DELETE FROM whatsapp_identidades WHERE id = ?')->execute([(int) $idFalso]);

// Um LID com um telefone de VERDADE junto continua funcionando.
$idOk = Identidade::registrar($ACC, $INST, null, $LID_FALSO, $FONE_D, null, 'messages_upsert');
$lo = Identidade::porEndereco($INST, $LID_FALSO);
eq($FONE_D, $lo['phone'], 'telefone diferente do LID entra normalmente');
$pdo->prepare('DELETE FROM whatsapp_identidades WHERE id = ?')->execute([(int) $idOk]);

/* ===================================================================== */
secao('a FUSÃO: o Alt prova que o LID e o telefone são a mesma pessoa');

// Duas identidades separadas, como o histórico realmente produz: uma nasceu do
// LID (sem nome), outra do CRM (com nome bom).
$idLid  = Identidade::registrar($ACC, $INST, null, $LID_C, null, null, 'messages_upsert');
$idFone = Identidade::registrar($ACC, $INST, null, null, $FONE_C, 'Ciclano da Silva', 'crm');
ok($idLid !== null && $idFone !== null && $idLid !== $idFone,
   'partem como DUAS identidades distintas');
$maisAntiga = min((int) $idLid, (int) $idFone);

// Chega a mensagem com os dois endereços na mesma key.
$idFundido = Identidade::registrar($ACC, $INST, $FONE_C . '@s.whatsapp.net', $LID_C, $FONE_C,
                                   'Ciclano', 'messages_upsert');

$sobrou = (int) $pdo->query(
    "SELECT COUNT(*) FROM whatsapp_identidades WHERE lid = '$LID_C' OR phone = '$FONE_C'"
)->fetchColumn();
eq(1, $sobrou, 'depois do Alt sobrou UMA identidade');
eq($maisAntiga, (int) $idFundido, 'sobreviveu a MAIS ANTIGA (é ela que outras tabelas apontam)');

$linhaC = Identidade::porEndereco($INST, $LID_C);
eq($FONE_C, $linhaC['phone'], 'a sobrevivente ficou com o telefone');
eq($LID_C,  $linhaC['lid'],   'e com o LID');
eq('Ciclano da Silva', $linhaC['nome'], 'manteve o nome da fonte mais forte, não o pushName');
eq('crm', $linhaC['nome_origem'], 'e a origem do nome foi preservada');

// O mesmo endereço resolve pelos dois lados.
$porFone = Identidade::porEndereco($INST, $FONE_C . '@s.whatsapp.net');
eq((int) $linhaC['id'], (int) $porFone['id'], 'buscar pelo telefone chega na MESMA identidade que o LID');

/* ===================================================================== */
secao('registrarNome recusa o que não é nome');

$idN = Identidade::registrar($ACC, $INST, null, $LID_B, null, null, 'messages_upsert');
ok(!Identidade::registrarNome((int) $idN, '5511997529604', 'messages_upsert'),
   'nome que é só número não vira nome');
ok(!Identidade::registrarNome((int) $idN, '+55 11 99752-9604', 'messages_upsert'),
   'telefone formatado também não');
ok(!Identidade::registrarNome((int) $idN, 'Você', 'messages_upsert'),
   '"Você" é o dono da conta, nunca o contato');
ok(!Identidade::registrarNome((int) $idN, '   ', 'messages_upsert'),
   'só espaço não é nome');
ok(Identidade::registrarNome((int) $idN, 'Nome Legítimo', 'messages_upsert'),
   'nome de verdade entra');
ok(!Identidade::registrarNome((int) $idN, 'Outro Qualquer', 'messages_upsert'),
   'e a mesma fonte não fica trocando por outro nome do mesmo tamanho');
ok(Identidade::registrarNome((int) $idN, 'Nome Digitado no CRM', 'crm'),
   'mas fonte mais forte troca');
ok(!Identidade::registrarNome(0, 'Qualquer', 'crm'), 'id inválido não grava');
ok(!Identidade::registrarNome(999999999, 'Qualquer', 'crm'), 'id inexistente não grava');

/* ===================================================================== */
secao('push_name cru é guardado mesmo quando não vira nome');

Identidade::registrar($ACC, $INST, null, $LID_B, null, 'Apelido do Zap', 'messages_upsert');
$st = $pdo->prepare('SELECT nome, push_name FROM whatsapp_identidades WHERE id = ?');
$st->execute([(int) $idN]);
$lb = $st->fetch(\PDO::FETCH_ASSOC);
eq('Nome Digitado no CRM', $lb['nome'], 'o nome bom continua de pé');
eq('Apelido do Zap', $lb['push_name'], 'e o pushName ficou guardado à parte, para a tela poder oferecer');

/* ===================================================================== */
secao('resolvido: o contrato que o resto do sistema e o n8n consomem');

$res = Identidade::resolvido($INST, $LID_C);
eq($FONE_C, $res['phone'], 'resolvido devolve o telefone');
eq($LID_C,  $res['lid'],   'resolvido devolve o LID');
eq('Ciclano da Silva', $res['name'], 'resolvido devolve o nome');
eq('crm', $res['name_source'], 'e diz de onde o nome veio');
ok($res['contact_resolved'] === true, 'contact_resolved verdadeiro quando há nome');

// Endereço nunca visto: responde no MESMO formato, sem explodir e sem inventar.
$res = Identidade::resolvido($INST, '5511911112222@s.whatsapp.net');
eq('5511911112222', $res['phone'], 'endereço desconhecido ainda devolve o telefone que dá para ler');
eq(null, $res['name'], 'sem nome, devolve null');
ok($res['contact_resolved'] === false, 'contact_resolved falso: a automação sabe que precisa perguntar');

$res = Identidade::resolvido($INST, '999999999999999@lid');
eq(null, $res['phone'], 'LID desconhecido não tem telefone para devolver');
eq('999999999999999@lid', $res['lid'], 'mas devolve o LID que recebeu');

/* ===================================================================== */
secao('isolamento: canal é fronteira');

$OUTRA = (int) $pdo->query("SELECT id FROM whatsapp_instances WHERE id <> $INST ORDER BY id LIMIT 1")->fetchColumn();
if ($OUTRA) {
    $accOutra = (int) $pdo->query("SELECT account_id FROM whatsapp_instances WHERE id = $OUTRA")->fetchColumn();
    $idOutra = Identidade::registrar($accOutra, $OUTRA, null, null, $FONE_A, 'Homônimo de Outro Canal', 'crm');
    ok($idOutra !== null && (int) $idOutra !== (int) $idA,
       'o MESMO telefone em outra instância é outra identidade');
    eq(null, Identidade::porEndereco($OUTRA, $LID_A),
       'e o LID da instância A não é encontrável pela instância B');
    $la = Identidade::porEndereco($INST, $FONE_A . '@s.whatsapp.net');
    ok(($la['nome'] ?? '') !== 'Homônimo de Outro Canal',
       'o nome gravado em um canal não vaza para o outro');
} else {
    echo "  [SKIP] só existe uma instância: isolamento por canal não pôde ser testado\n";
}

/* ===================================================================== */
secao('conta ou instância inválida não grava');

eq(null, Identidade::registrar(0, $INST, null, $LID_A, null, 'X', 'crm'), 'account_id 0 não grava');
eq(null, Identidade::registrar($ACC, 0, null, $LID_A, null, 'X', 'crm'), 'instance_id 0 não grava');

/* ===================================================================== */
echo "\n== limpeza ==\n";
$limpar();
$sobra = (int) $pdo->query(
    "SELECT COUNT(*) FROM whatsapp_identidades
      WHERE phone LIKE '55999000%' OR jid LIKE '55999000%'
         OR lid LIKE '7777%' OR lid = '7623902498956@lid'"
)->fetchColumn();
eq(0, $sobra, 'nenhuma identidade de teste ficou no banco');

printf("\n%s  %d PASS, %d FAIL\n", $FAILS === 0 ? 'OK' : 'FALHOU', $PASSES, $FAILS);
exit($FAILS === 0 ? 0 : 1);
