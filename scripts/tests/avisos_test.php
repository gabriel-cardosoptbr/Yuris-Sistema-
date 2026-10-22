<?php
/**
 * Avisos de tempo real e push (App\Notificacoes\Push, Destinatarios, Aviso::leadNovo;
 * migration 167).
 *
 * Inovaize, 22/10/2026: "chegou um lead, dá um bling na tela, só pro Kauê e pros
 * ADM, pra Isa não", com aviso no celular. Confere:
 *   · a cifra do push contra o exemplo oficial da RFC 8291 (byte a byte) e a ida
 *     e volta com chave aleatória (o aparelho decifra);
 *   · a lista de serviços de push aceitos (sem ela, SSRF);
 *   · quem recebe: padrão da edição CRM (administradores), escolha gravada,
 *     usuário de outra conta descartado, e "pra Isa não";
 *   · o lead novo avisa os escolhidos, não avisa quem cadastrou, não duplica e
 *     entra na fila do push; e o "bling" (novosTempoReal) o encontra.
 *
 * Usa a conta 304 local, usuários temporários ZZAVT; desfaz tudo no fim.
 * Precisa da migration 167. O "Lembrar" é conferido com curl (ver
 * app/Usuarios/README.md), porque depende de cookie e sessão de verdade.
 *
 *   php scripts/tests/avisos_test.php
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Master\AccountNotification;
use App\Notificacoes\Aviso;
use App\Notificacoes\Destinatarios;
use App\Notificacoes\Push;

$OK = 0;
$FALHAS = [];
function ok(string $msg, bool $cond): void
{
    global $OK, $FALHAS;
    if ($cond) { $OK++; echo "  [ok]   $msg\n"; }
    else { $FALHAS[] = $msg; echo "  [FALHA] $msg\n"; }
}
function chaveEc(): array
{
    $cfg = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
    if (PHP_OS_FAMILY === 'Windows' && !getenv('OPENSSL_CONF') && is_file('C:/xampp/apache/conf/openssl.cnf')) $cfg['config'] = 'C:/xampp/apache/conf/openssl.cnf';
    $k = openssl_pkey_new($cfg);
    $d = openssl_pkey_get_details($k);
    return [$k, "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT)];
}

echo "== 1. Cifra do push (RFC 8291) ==\n";
$d = fn(string $s): string => Push::deB64u($s);
$asPriv = $d('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw');
$asPub  = $d('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8');
$uaPub  = $d('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4');
$auth   = $d('BTBZMqHH6r4Tts7J_aSIgg');
$salt   = $d('DGv6ra1nlYgDCS1FRnbzlw');
$der = hex2bin('30770201010420') . $asPriv . hex2bin('a00a06082a8648ce3d030107a144034200') . $asPub;
$priv = openssl_pkey_get_private("-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n");
$sh = openssl_pkey_derive(openssl_pkey_get_public(Push::pemPublica($uaPub)), $priv, 32);
[$cek, $nonce] = Push::derivar($sh, $auth, $uaPub, $asPub, $salt);
$tag = '';
$ct = openssl_encrypt("When I grow up, I want to be a watermelon\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
ok('saída igual ao exemplo da RFC 8291, byte a byte', Push::b64u($salt . pack('N', 4096) . chr(65) . $asPub . $ct . $tag)
    === 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN');

[$uaK, $uaP] = chaveEc();
$seg = random_bytes(16);
$c = Push::cifrar('{"titulo":"Lead novo: Maria"}', $uaP, $seg);
$srv = substr($c, 21, 65);
$sh2 = openssl_pkey_derive(openssl_pkey_get_public(Push::pemPublica($srv)), $uaK, 32);
[$k2, $n2] = Push::derivar($sh2, $seg, $uaP, $srv, substr($c, 0, 16));
$claro = openssl_decrypt(substr($c, 86, -16), 'aes-128-gcm', $k2, OPENSSL_RAW_DATA, $n2, substr($c, -16));
ok('o aparelho decifra o que o servidor cifrou (chave aleatória)', $claro === "{\"titulo\":\"Lead novo: Maria\"}\x02");
ok('chave pública VAPID gerada e guardada (65 bytes)', strlen(Push::deB64u((string) Push::chavePublica())) === 65);

echo "\n== 2. Serviços de push aceitos ==\n";
ok('Google (Android e Chrome)', Push::endpointValido('https://fcm.googleapis.com/fcm/send/abc'));
ok('Apple (iPhone com o app instalado)', Push::endpointValido('https://web.push.apple.com/QGx'));
ok('Mozilla', Push::endpointValido('https://updates.push.services.mozilla.com/wpush/v2/x'));
ok('endereço qualquer é recusado (SSRF)', !Push::endpointValido('https://evil.example.com/fcm.googleapis.com'));
ok('serviço certo sem HTTPS é recusado', !Push::endpointValido('http://fcm.googleapis.com/fcm/send/abc'));
ok('serviço certo com porta é recusado', !Push::endpointValido('https://fcm.googleapis.com:8443/x'));
ok('host que só termina parecido é recusado', !Push::endpointValido('https://xfcm.googleapis.com/x'));

echo "\n== 3. Quem recebe (conta 304) ==\n";
$pdo = Database::getConnection();
$CONTA = 304;
$cfgAntes = $pdo->query("SELECT configuracoes FROM accounts WHERE id = $CONTA")->fetchColumn();
$users = [];
$cards = [];
try {
    foreach ([['Kaue', 'user'], ['Isa', 'user'], ['Adm', 'admin']] as [$n, $papel]) {
        $pdo->prepare("INSERT INTO users (account_id, nome, login, senha_hash, perfil, role, status) VALUES (?, ?, ?, 'x', ?, ?, 'active')")
            ->execute([$CONTA, 'ZZAVT ' . $n, 'zzavt_' . bin2hex(random_bytes(4)) . '@teste.local', $papel === 'admin' ? 'admin' : 'user', $papel]);
        $users[$n] = (int) $pdo->lastInsertId();
    }
    $outraConta = (int) $pdo->query("SELECT id FROM users WHERE account_id <> $CONTA AND deleted_at IS NULL LIMIT 1")->fetchColumn();

    // Padrão (nada gravado): edição CRM avisa os administradores.
    $cfg = json_decode((string) $cfgAntes, true) ?: [];
    unset($cfg['avisos']);
    $pdo->prepare('UPDATE accounts SET configuracoes = ? WHERE id = ?')->execute([json_encode($cfg), $CONTA]);
    $padrao = Destinatarios::usuarios($CONTA, 'lead_novo');
    ok('sem escolha gravada: administradores recebem, vendedores não', in_array($users['Adm'], $padrao, true) && !in_array($users['Kaue'], $padrao, true));

    // A escolha da Inovaize: administradores + Kauê, Isa não; usuário de outra conta é descartado.
    $salvo = Destinatarios::salvar($CONTA, 'lead_novo', true, [$users['Kaue'], $outraConta]);
    ok('usuário de outra conta não entra na escolha', !in_array($outraConta, $salvo['usuarios'], true) && $salvo['usuarios'] === [$users['Kaue']]);
    $quem = Destinatarios::usuarios($CONTA, 'lead_novo');
    ok('Kauê e administradores recebem; Isa não', in_array($users['Kaue'], $quem, true) && in_array($users['Adm'], $quem, true) && !in_array($users['Isa'], $quem, true));
    $resto = json_decode((string) $pdo->query("SELECT configuracoes FROM accounts WHERE id = $CONTA")->fetchColumn(), true);
    ok('o resto de configuracoes fica como estava', array_diff_key($resto, ['avisos' => 1]) == array_diff_key($cfg, ['avisos' => 1]));

    Destinatarios::salvar($CONTA, 'lead_novo', false, [$users['Kaue']]);
    $soKaue = Destinatarios::usuarios($CONTA, 'lead_novo');
    ok('sem "todos os administradores": só quem foi marcado', $soKaue === [$users['Kaue']]);

    echo "\n== 4. Lead novo ==\n";
    Destinatarios::salvar($CONTA, 'lead_novo', true, [$users['Kaue']]);
    $antesMax = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM account_notifications')->fetchColumn();
    $cards[] = $cardId = \App\Prospeccao\Card::create(['account_id' => $CONTA, 'cliente_nome' => 'ZZAVT Lead do site', 'telefone_whatsapp' => '5511900001111']);
    $avisos = $pdo->query("SELECT user_id, titulo, url FROM account_notifications WHERE id > $antesMax AND tipo = 'lead_novo' AND entidade_id = $cardId")->fetchAll(\PDO::FETCH_ASSOC);
    $para = array_map('intval', array_column($avisos, 'user_id'));
    ok('lead que chegou sozinho (WhatsApp, site) avisa Kauê e administrador', in_array($users['Kaue'], $para, true) && in_array($users['Adm'], $para, true));
    ok('Isa não recebe o aviso do lead', !in_array($users['Isa'], $para, true));
    ok('título e link do aviso', ($avisos[0]['titulo'] ?? '') === 'Lead novo: ZZAVT Lead do site' && ($avisos[0]['url'] ?? '') === '/prospeccao?open=' . $cardId);

    $novos = AccountNotification::novosTempoReal($users['Kaue'], $CONTA, $antesMax);
    ok('o "bling" do Kauê encontra o aviso novo', in_array('Lead novo: ZZAVT Lead do site', array_column($novos, 'titulo'), true));
    ok('a primeira pergunta da página (desde=-1) dá só o ponto de partida', AccountNotification::ultimoId($users['Kaue'], $CONTA) > $antesMax);

    Aviso::leadNovo($CONTA, $cardId, 'ZZAVT Lead do site', null);
    $dup = (int) $pdo->query("SELECT COUNT(*) FROM account_notifications WHERE tipo = 'lead_novo' AND entidade_id = $cardId AND user_id = {$users['Kaue']}")->fetchColumn();
    ok('o mesmo lead não avisa duas vezes', $dup === 1);

    $antesMax2 = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM account_notifications')->fetchColumn();
    $cards[] = $card2 = \App\Prospeccao\Card::create(['account_id' => $CONTA, 'cliente_nome' => 'ZZAVT Lead do Kauê', '_usuario_id' => $users['Kaue']]);
    $para2 = array_map('intval', $pdo->query("SELECT user_id FROM account_notifications WHERE id > $antesMax2 AND tipo = 'lead_novo' AND entidade_id = $card2")->fetchAll(\PDO::FETCH_COLUMN));
    ok('quem cadastrou o lead não é avisado do próprio cadastro', !in_array($users['Kaue'], $para2, true) && in_array($users['Adm'], $para2, true));

    $fila = (new ReflectionClass(Push::class))->getProperty('fila');
    $fila->setAccessible(true);
    $naFila = array_filter($fila->getValue(), fn($i) => ($i['msg']['tipo'] ?? '') === 'lead_novo');
    ok('o lead novo entra na fila do push (sai no fim da requisição)', count($naFila) >= 2);
    $fila->setValue(null, []); // sem aparelho inscrito: nada a mandar

    echo "\n== 5. Inscrição do aparelho ==\n";
    [, $p] = chaveEc();
    $ep = 'https://fcm.googleapis.com/fcm/send/ZZAVT' . bin2hex(random_bytes(8));
    ok('inscreve um aparelho válido', Push::inscrever($CONTA, $users['Kaue'], $ep, Push::b64u($p), Push::b64u(random_bytes(16))));
    ok('recusa chave de aparelho inválida', !Push::inscrever($CONTA, $users['Kaue'], $ep, Push::b64u(random_bytes(10)), Push::b64u(random_bytes(16))));
    ok('recusa endereço fora dos serviços de push', !Push::inscrever($CONTA, $users['Kaue'], 'https://evil.example.com/x', Push::b64u($p), Push::b64u(random_bytes(16))));
    Push::inscrever($CONTA, $users['Isa'], $ep, Push::b64u($p), Push::b64u(random_bytes(16)));
    ok('o mesmo aparelho passa a ser de quem ativou por último', Push::aparelhos($users['Isa'], $CONTA) === 1 && Push::aparelhos($users['Kaue'], $CONTA) === 0);
    Push::remover($users['Kaue'], $ep);
    ok('ninguém remove o aparelho de outra pessoa', Push::aparelhos($users['Isa'], $CONTA) === 1);
    Push::remover($users['Isa'], $ep);
    ok('a pessoa remove o próprio aparelho', Push::aparelhos($users['Isa'], $CONTA) === 0);
} finally {
    $pdo->prepare('UPDATE accounts SET configuracoes = ? WHERE id = ?')->execute([$cfgAntes, $CONTA]);
    if ($cards) {
        $in = implode(',', array_map('intval', $cards));
        $pdo->exec("DELETE FROM account_notifications WHERE entidade = 'card' AND entidade_id IN ($in)");
        // card_history é imutável (gatilho LGPD): o card de teste sai por exclusão lógica.
        $pdo->exec("UPDATE cards SET deleted_at = NOW() WHERE id IN ($in)");
    }
    if ($users) {
        $in = implode(',', $users);
        $pdo->exec("DELETE FROM push_inscricoes WHERE user_id IN ($in)");
        $pdo->exec("DELETE FROM account_notifications WHERE user_id IN ($in)");
        $pdo->exec("DELETE FROM users WHERE id IN ($in)");
    }
}
$sobra = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE nome LIKE 'ZZAVT %'")->fetchColumn()
       + (int) $pdo->query("SELECT COUNT(*) FROM cards WHERE cliente_nome LIKE 'ZZAVT %' AND deleted_at IS NULL")->fetchColumn();
ok('nada de teste ficou no banco', $sobra === 0);

echo "\n" . $OK . ' ok/' . count($FALHAS) . "\n";
exit($FALHAS ? 1 : 0);
