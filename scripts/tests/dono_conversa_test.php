<?php
/**
 * Dono por conversa (App\WhatsAppAgente\DonoDaConversa, migration 164).
 *
 * Inovaize, 22/10/2026: dois vendedores no mesmo número, cada um vê só as próprias
 * conversas. Confere: modo desligado não muda nada; a conversa ganha dono pela data
 * da mensagem (antes do corte para quem fica com as antigas, depois para quem recebe
 * as novas); o vendedor só vê e lista as dele e as sem dono; "Assumir" e "Passar para"
 * (quem pode, para quem); o lead da automação segue o dono da conversa; a distribuição
 * das conversas sem dono; outra conta nunca entra.
 *
 * Usa a conta 304 local (número 11), dois vendedores temporários e conversas
 * ZZDONO; desfaz tudo no fim. Precisa da migration 164.
 *
 *   php scripts/tests/dono_conversa_test.php
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\WhatsAppAgente\DonoDaConversa as D;
use App\WhatsAppAgente\WhatsAppMessage;

$OK = 0;
$FALHAS = [];
function ok(string $msg, bool $cond): void
{
    global $OK, $FALHAS;
    if ($cond) { $OK++; echo "  [ok]   $msg\n"; }
    else { $FALHAS[] = $msg; echo "  [FALHA] $msg\n"; }
}
function recusa(callable $f): ?string { try { $f(); return null; } catch (\DomainException $e) { return $e->getMessage(); } }

$pdo = Database::getConnection();
$INST = 11; $CONTA = 304;
$criados = ['users' => [], 'jids' => []];
$antes = $pdo->query("SELECT dono_conversa_desde, dono_novas_user_id, dono_antigas_user_id FROM whatsapp_instances WHERE id = $INST")->fetch(\PDO::FETCH_ASSOC);
if ($antes === false) exit("Número $INST não existe na base local.\n");

$novoUser = function (string $nome, int $conta) use ($pdo, &$criados): int {
    $pdo->prepare("INSERT INTO users (account_id, nome, login, senha_hash, perfil, role, status) VALUES (?, ?, ?, 'x', 'user', 'user', 'active')")
        ->execute([$conta, $nome, 'zzdono_' . bin2hex(random_bytes(4)) . '@teste.local']);
    $id = (int) $pdo->lastInsertId();
    $criados['users'][] = $id;
    return $id;
};
$jid = function (string $s) use (&$criados): string { $j = '5511900' . $s . '@s.whatsapp.net'; $criados['jids'][] = $j; return $j; };
$msgModel = new WhatsAppMessage();
$mensagem = function (string $j, string $quando) use ($msgModel, $INST, $CONTA): void {
    $msgModel->upsertChat(['instance_id' => $INST, 'account_id' => $CONTA, 'remote_jid' => $j, 'direction' => 'inbound',
        'message_content' => 'oi', 'message_type' => 'text', 'created_at' => $quando, 'contact_name' => 'ZZDONO']);
};
$doChat = fn(string $j) => D::dono($pdo, $INST, $j);

try {
    $isa   = $novoUser('ZZDONO Isa', $CONTA);
    $kaue  = $novoUser('ZZDONO Kaue', $CONTA);
    $fora  = $novoUser('ZZDONO Outra conta', 476);

    echo "\n== Modo desligado: nada muda ==\n";
    D::configurar($pdo, $INST, null, null, null);
    $jA = $jid('0001');
    $mensagem($jA, date('Y-m-d H:i:s'));
    ok('sem modo, a conversa não ganha dono', $doChat($jA) === null);
    ok('sem modo, o vendedor vê qualquer conversa', D::podeVer($pdo, $INST, $jA, $kaue));
    ok('sem modo, a listagem não filtra', D::filtroSql($pdo, $INST, $kaue)[0] === '');
    ok('sem modo, o vendedor não alcança o número pelo modo', !D::vendedorAlcanca($pdo, $INST, $kaue));

    echo "\n== Liga o modo: antigas para a Isa, novas para o Kauê ==\n";
    $corte = date('Y-m-d H:i:s', time() - 3600);
    D::configurar($pdo, $INST, $corte, $kaue, $isa);
    ok('modo ligado', D::modo($pdo, $INST) !== null && D::modo($pdo, $INST)['novas'] === $kaue);
    ok('o número aparece entre os do modo', D::numerosNoModo($pdo, [$INST]) === [$INST]);
    ok('vendedor da conta alcança o número', D::vendedorAlcanca($pdo, $INST, $isa) && D::vendedorAlcanca($pdo, $INST, $kaue));
    ok('usuário de outra conta não alcança', !D::vendedorAlcanca($pdo, $INST, $fora));
    ok('dono de outra conta é recusado na configuração', recusa(fn() => D::configurar($pdo, $INST, $corte, $fora, $isa)) !== null);
    D::configurar($pdo, $INST, $corte, $kaue, $isa);

    $jVelha = $jid('0002'); $jNova = $jid('0003');
    $mensagem($jVelha, date('Y-m-d H:i:s', time() - 7200));   // histórico de antes do corte
    $mensagem($jNova, date('Y-m-d H:i:s'));                    // conversa nova
    ok('mensagem de antes do corte: conversa da Isa', $doChat($jVelha) === $isa);
    ok('mensagem de depois do corte: conversa do Kauê', $doChat($jNova) === $kaue);
    $mensagem($jVelha, date('Y-m-d H:i:s'));
    ok('contato antigo que volta a escrever continua com a Isa', $doChat($jVelha) === $isa);
    $mensagem($jA, date('Y-m-d H:i:s'));
    ok('a conversa sem dono de antes do modo ganha dono na próxima mensagem', $doChat($jA) === $kaue);

    echo "\n== Cada um vê só as suas ==\n";
    ok('Isa vê a dela', D::podeVer($pdo, $INST, $jVelha, $isa));
    ok('Isa não vê a do Kauê', !D::podeVer($pdo, $INST, $jNova, $isa));
    ok('Kauê não vê a da Isa', !D::podeVer($pdo, $INST, $jVelha, $kaue));
    ok('dono e admin (sem vendedor) veem tudo', D::podeVer($pdo, $INST, $jVelha, null) && D::podeVer($pdo, $INST, $jNova, null));
    $jSem = $jid('0004');
    $pdo->prepare("INSERT INTO whatsapp_chats (account_id, instance_id, remote_jid, contact_name, linked_card_id) VALUES (?, ?, ?, 'ZZDONO', NULL)")->execute([$CONTA, $INST, $jSem]);
    ok('conversa sem dono: os dois veem (para assumir)', D::podeVer($pdo, $INST, $jSem, $isa) && D::podeVer($pdo, $INST, $jSem, $kaue));
    ok('conversa que ainda não existe é livre (vai nascer com dono)', D::podeVer($pdo, $INST, '551190009999@s.whatsapp.net', $isa));

    $lista = fn(?int $v) => array_column($msgModel->getChatList($INST, 'ZZDONO', null, null, false, 500, null, $v), 'dono_nome', 'remote_jid');
    $daIsa = $lista($isa); $doKaue = $lista($kaue); $todas = $lista(null);
    ok('lista da Isa: a dela, sem a do Kauê', isset($daIsa[$jVelha]) && !isset($daIsa[$jNova]));
    ok('lista do Kauê: as dele, sem a da Isa', isset($doKaue[$jNova]) && isset($doKaue[$jA]) && !isset($doKaue[$jVelha]));
    ok('lista do admin: todas', isset($todas[$jVelha]) && isset($todas[$jNova]));
    ok('a lista traz o nome do dono', ($todas[$jVelha] ?? '') === 'ZZDONO Isa');
    $pdo->prepare('UPDATE whatsapp_chats SET unread_count = 5 WHERE instance_id = ? AND remote_jid = ?')->execute([$INST, $jVelha]);
    ok('não lidas da Isa contam a conversa dela; as do Kauê não', $msgModel->getTotalUnread($INST, $isa) >= 5 && $msgModel->getTotalUnread($INST, $kaue) < $msgModel->getTotalUnread($INST, null));

    echo "\n== Assumir e passar ==\n";
    ok('Isa não assume a conversa do Kauê', recusa(fn() => D::passar($pdo, $INST, $jNova, $isa, $isa)) !== null && $doChat($jNova) === $kaue);
    D::passar($pdo, $INST, $jSem, $isa, $isa);
    ok('Isa assume a conversa sem dono', $doChat($jSem) === $isa && !D::podeVer($pdo, $INST, $jSem, $kaue));
    D::passar($pdo, $INST, $jNova, $isa, $kaue);
    ok('Kauê passa a dele para a Isa: some para ele, aparece para ela', $doChat($jNova) === $isa && !D::podeVer($pdo, $INST, $jNova, $kaue) && D::podeVer($pdo, $INST, $jNova, $isa));
    D::passar($pdo, $INST, $jNova, $kaue, null);
    ok('admin passa qualquer conversa', $doChat($jNova) === $kaue);
    ok('não passa para usuário de outra conta', recusa(fn() => D::passar($pdo, $INST, $jNova, $fora, null)) !== null && $doChat($jNova) === $kaue);
    ok('conversa inexistente é recusada', recusa(fn() => D::passar($pdo, $INST, '551190008888@s.whatsapp.net', $isa, null)) !== null);
    ok('a equipe para "Passar para" é da conta do número', in_array($isa, array_column(D::equipe($pdo, $INST), 'id'), true) && !in_array($fora, array_column(D::equipe($pdo, $INST), 'id'), true));

    echo "\n== Lead da automação segue o dono da conversa ==\n";
    ok('lead da conversa da Isa é da Isa', \App\WhatsAppAgente\SdrFleetiflow::donoDoNumero($pdo, $CONTA, $INST, $jVelha) === $isa);
    ok('lead de conversa ainda sem dono vai para quem recebe as novas', \App\WhatsAppAgente\SdrFleetiflow::donoDoNumero($pdo, $CONTA, $INST, '551190007777@s.whatsapp.net') === $kaue);

    echo "\n== Distribuir as conversas sem dono ==\n";
    $jD = $jid('0005'); $jE = $jid('0006');
    $pdo->prepare("INSERT INTO whatsapp_chats (account_id, instance_id, remote_jid, contact_name, created_at) VALUES (?, ?, ?, 'ZZDONO', ?)")->execute([$CONTA, $INST, $jD, date('Y-m-d H:i:s', time() - 86400)]);
    $pdo->prepare("INSERT INTO whatsapp_chats (account_id, instance_id, remote_jid, contact_name, created_at) VALUES (?, ?, ?, 'ZZDONO', ?)")->execute([$CONTA, $INST, $jE, date('Y-m-d H:i:s')]);
    $previa = D::distribuirSemDono($pdo, $INST, false);
    ok('conferir não grava', $doChat($jD) === null && $previa['antigas'] >= 1 && $previa['novas'] >= 1);
    D::distribuirSemDono($pdo, $INST, true);
    ok('criada antes do corte vai para a Isa, depois para o Kauê', $doChat($jD) === $isa && $doChat($jE) === $kaue);
    ok('quem já tinha dono não mudou', $doChat($jNova) === $kaue && $doChat($jSem) === $isa);
} finally {
    D::configurar($pdo, $INST, $antes['dono_conversa_desde'], $antes['dono_novas_user_id'] !== null ? (int) $antes['dono_novas_user_id'] : null, $antes['dono_antigas_user_id'] !== null ? (int) $antes['dono_antigas_user_id'] : null);
    foreach (array_unique($criados['jids']) as $j) $pdo->prepare('DELETE FROM whatsapp_chats WHERE instance_id = ? AND remote_jid = ?')->execute([$INST, $j]);
    foreach ($criados['users'] as $u) $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$u]);
}

echo "\n$OK ok/" . count($FALHAS) . "\n";
exit($FALHAS ? 1 : 0);
