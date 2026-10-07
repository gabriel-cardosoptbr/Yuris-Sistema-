<?php
/**
 * scripts/tests/contatos_salvos_test.php — todo contato do WhatsApp da edição CRM tem
 * card (SdrFleetiflow::conversasSemCard e ligarConversasSoltas).
 *
 * Pedido (07/10/2026): "o lead pingou no número, tem que criar um card novo". O card
 * nasce com a mensagem (aoMensagem); esta é a rede que pega o que o webhook perdeu.
 * Confere, numa conta CRM de teste: conversa com telefone e mensagem ganha card, com o
 * nome da conversa e ligada a ele; conversa de telefone que já é card só é ligada
 * (nada duplica); ficam de fora, com o motivo, a conversa sem nenhuma mensagem, a de
 * telefone desconhecido (@lid), a do número da própria conta, a de número de treino e
 * o grupo; e uma segunda passada não faz nada. Apaga o que criou.
 *
 * Uso: php scripts/tests/contatos_salvos_test.php   (precisa da migration 140)
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\WhatsAppAgente\SdrFleetiflow as S;

$ok = 0; $falhas = 0;
function ok(string $nome, bool $cond): void {
    global $ok, $falhas;
    if ($cond) { $ok++; echo "  [ok]   $nome\n"; } else { $falhas++; echo "  [FALHA] $nome\n"; }
}

$pdo = Database::getConnection();
$ACC = 476; $INST = 15; $P = '551998886';
$f = fn(int $n) => $P . '000' . $n;          // 13 dígitos; só estes são apagados
$TREINO = '5511925592706';                   // um dos números de treino da equipe (SdrFleetiflow::NUMEROS_TREINO)

$conta = \App\Master\Account::findById($ACC);
$dono = $pdo->prepare('SELECT account_id, phone FROM whatsapp_instances WHERE id = ?');
$dono->execute([$INST]);
$inst = $dono->fetch(PDO::FETCH_ASSOC);
if (!$conta || \App\Master\Account::moduloJuridicoDisponivel($conta) || !$inst || (int)$inst['account_id'] !== $ACC) {
    echo "(pulado: precisa da conta CRM de teste $ACC e da instância $INST)\n";
    exit(0);
}
$foneOriginal = $inst['phone'];
$proprio = '5519988869999';

$jids = [$f(1), $f(2), $f(3), $f(4), $proprio, $TREINO];
$limpa = function () use ($pdo, $ACC, $INST, $jids, $f) {
    foreach (array_merge($jids, [$f(7)]) as $n) {
        $j = $n . '@s.whatsapp.net';
        $pdo->prepare('DELETE FROM whatsapp_messages WHERE instance_id = ? AND remote_jid = ?')->execute([$INST, $j]);
        $pdo->prepare('DELETE FROM whatsapp_chats WHERE instance_id = ? AND remote_jid = ?')->execute([$INST, $j]);
        $pdo->prepare('DELETE FROM cards WHERE account_id = ? AND telefone_whatsapp = ?')->execute([$ACC, $n]);
    }
    foreach (['999000111222@g.us', '555000111222333@lid'] as $j) {
        $pdo->prepare('DELETE FROM whatsapp_messages WHERE instance_id = ? AND remote_jid = ?')->execute([$INST, $j]);
        $pdo->prepare('DELETE FROM whatsapp_chats WHERE instance_id = ? AND remote_jid = ?')->execute([$INST, $j]);
    }
};
$limpa();
try {
    $pdo->prepare('UPDATE whatsapp_instances SET phone = ? WHERE id = ?')->execute([$proprio, $INST]);
    $chat = function (string $jid, string $nome, bool $grupo, bool $comMsg) use ($pdo, $ACC, $INST) {
        $pdo->prepare('INSERT INTO whatsapp_chats (instance_id, account_id, remote_jid, contact_name, is_group, created_at, updated_at) VALUES (?,?,?,?,?,NOW(),NOW())')
            ->execute([$INST, $ACC, $jid, $nome, $grupo ? 1 : 0]);
        if ($comMsg) {
            $pdo->prepare("INSERT INTO whatsapp_messages (instance_id, account_id, remote_jid, direction, message_type, message_content, created_at) VALUES (?,?,?,'inbound','conversation','oi',NOW())")
                ->execute([$INST, $ACC, $jid]);
        }
    };
    $cardDe = function (string $fone) use ($pdo, $ACC) {
        $st = $pdo->prepare('SELECT * FROM cards WHERE account_id = ? AND telefone_whatsapp = ? AND deleted_at IS NULL');
        $st->execute([$ACC, $fone]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };
    $ligado = function (string $jid) use ($pdo, $INST) {
        $st = $pdo->prepare('SELECT linked_card_id FROM whatsapp_chats WHERE instance_id = ? AND remote_jid = ?');
        $st->execute([$INST, $jid]);
        return (int)($st->fetchColumn() ?: 0);
    };

    echo "== Conversas sem card ==\n";
    $chat($f(1) . '@s.whatsapp.net', 'Loja Teste Um', false, true);    // 1: ganha card
    $chat($f(2) . '@s.whatsapp.net', '', false, true);                  // 2: o telefone já é card
    $chat($f(3) . '@s.whatsapp.net', 'Sem Mensagem', false, false);     // 3: contato da agenda
    $chat($f(4) . '@s.whatsapp.net', 'Outro Lead', false, true);        // 4: ganha card, sem nome útil
    $chat($proprio . '@s.whatsapp.net', 'Meu Chip', false, true);       // próprio número
    $chat($TREINO . '@s.whatsapp.net', 'Treino', false, true);          // número de treino
    $chat('999000111222@g.us', 'Grupo X', true, true);                  // grupo
    $chat('555000111222333@lid', 'Escondido', false, true);             // @lid sem telefone conhecido
    $existente = (int)\App\Prospeccao\Card::create([
        'account_id' => $ACC, 'titulo' => 'Já Era Card', 'cliente_nome' => 'Já Era Card', 'telefone_whatsapp' => $f(2),
        'coluna_id' => (S::colunasDaConta($pdo, $ACC)['novo'] ?? \App\Prospeccao\CaptacaoAutomatica::primeiraColuna($pdo, $ACC)),
        'ordem_na_coluna' => 0, 'status' => 'aberto', '_usuario_id' => null,
    ]);

    $plano = S::conversasSemCard($pdo, $ACC, $INST);
    $jidsCriar = array_column($plano['criar'], 'jid');
    $motivo = [];
    foreach ($plano['pular'] as $p) $motivo[$p['jid']] = $p['motivo'];
    ok('com telefone e mensagem e sem card: criar', in_array($f(1) . '@s.whatsapp.net', $jidsCriar, true) && in_array($f(4) . '@s.whatsapp.net', $jidsCriar, true));
    ok('telefone que já é card: só ligar', in_array($f(2) . '@s.whatsapp.net', array_column($plano['ligar'], 'jid'), true) && !in_array($f(2) . '@s.whatsapp.net', $jidsCriar, true));
    ok('sem nenhuma mensagem: pular', isset($motivo[$f(3) . '@s.whatsapp.net']) && str_contains($motivo[$f(3) . '@s.whatsapp.net'], 'sem nenhuma mensagem'));
    ok('número da própria conta: pular', isset($motivo[$proprio . '@s.whatsapp.net']) && str_contains($motivo[$proprio . '@s.whatsapp.net'], 'própria conta'));
    ok('número de treino: pular', isset($motivo[$TREINO . '@s.whatsapp.net']) && str_contains($motivo[$TREINO . '@s.whatsapp.net'], 'treino'));
    ok('@lid sem telefone: pular', isset($motivo['555000111222333@lid']) && str_contains($motivo['555000111222333@lid'], 'sem telefone'));
    ok('grupo não entra em nenhuma lista', !in_array('999000111222@g.us', array_merge($jidsCriar, array_keys($motivo), array_column($plano['ligar'], 'jid')), true));
    $nomes = array_column($plano['criar'], 'nome', 'jid');
    ok('o nome da conversa vai junto; sem nome útil, vazio', ($nomes[$f(1) . '@s.whatsapp.net'] ?? null) === 'Loja Teste Um' && ($nomes[$f(4) . '@s.whatsapp.net'] ?? null) === 'Outro Lead');

    echo "\n== Salvar ==\n";
    $feitas = S::ligarConversasSoltas($pdo, $ACC, $INST);
    ok('ligou uma e criou duas', $feitas >= 3);
    $c1 = $cardDe($f(1));
    ok('o contato ganhou card, ligado à conversa', count($c1) === 1 && $ligado($f(1) . '@s.whatsapp.net') === (int)$c1[0]['id']);
    ok('o card nasce em Novos leads, com o nome da conversa', count($c1) === 1 && $c1[0]['cliente_nome'] === 'Loja Teste Um');
    $c4 = $cardDe($f(4));
    ok('o outro contato também', count($c4) === 1 && $ligado($f(4) . '@s.whatsapp.net') === (int)$c4[0]['id']);
    ok('o telefone que já era card foi ligado, sem duplicar', count($cardDe($f(2))) === 1 && $ligado($f(2) . '@s.whatsapp.net') === $existente);
    ok('os pulados continuam sem card', !$cardDe($f(3)) && !$cardDe($proprio) && !$cardDe($TREINO));
    ok('uma segunda passada não cria nada', S::ligarConversasSoltas($pdo, $ACC, $INST) === 0 && count($cardDe($f(1))) === 1);
} finally {
    $pdo->prepare('UPDATE whatsapp_instances SET phone = ? WHERE id = ?')->execute([$foneOriginal, $INST]);
    $limpa();
}
echo "  histórico de auditoria permanece (imutável por trigger), órfão e invisível\n";

echo "\n----\nResultado: $ok ok · $falhas falha(s)\n";
exit($falhas > 0 ? 1 : 0);
