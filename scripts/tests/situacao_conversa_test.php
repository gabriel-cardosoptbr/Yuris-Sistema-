<?php
/**
 * scripts/tests/situacao_conversa_test.php: a linha "Aguardando resposta" e o
 * rótulo de quem mandou cada mensagem no Chat da edição CRM.
 *
 * Confere:
 *   - AutorDaMensagem::classificar (pura): robô de disparo, agente, follow-up,
 *     balões seguidos, pessoa pelo Chat, celular, sincronizada sem rótulo;
 *   - SituacaoConversa::avaliar (pura): cada motivo e quando o botão aparece;
 *   - com banco, numa conversa de teste da conta CRM local: as mensagens do lead
 *     depois da nossa última, o motivo "chegou sem webhook", o "Mandar para o
 *     agente" registrando a entrega (endereço falso em 127.0.0.1:9, nada sai da
 *     máquina), a trava de dois cliques, a pausa, e outra conta não enxerga.
 *
 * Usa a conta CRM de teste (FLEET_TESTE_CONTA, padrão 304) e apaga tudo que criou.
 *
 * Uso: php scripts/tests/situacao_conversa_test.php
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\WhatsAppAgente\AutorDaMensagem as A;
use App\WhatsAppAgente\SituacaoConversa as S;
use App\WhatsAppAgente\SdrFleetiflow;

$ok = 0; $falhas = 0;
function ok(string $nome, bool $cond): void {
    global $ok, $falhas;
    if ($cond) { $ok++; echo "  [ok]   $nome\n"; } else { $falhas++; echo "  [FALHA] $nome\n"; }
}

echo "AutorDaMensagem::classificar\n";
$t = 1_000_000;
$m = fn($id, $dir, $dt, $api = true, $autor = null) => ['id' => $id, 'dir' => $dir, 'ts' => $t + $dt, 'api' => $api, 'autor' => $autor];
// O caso da Ouro Verde: abertura do robô, resposta automática da loja, Vitória em dois balões.
$c = A::classificar([$m(1, 'outbound', 0), $m(2, 'inbound', 2), $m(3, 'inbound', 2), $m(4, 'outbound', 26), $m(5, 'outbound', 31)]);
ok('primeira mensagem da conversa = robô de disparo', ($c[1] ?? '') === A::DISPARO);
ok('resposta 24 s depois do lead = agente', ($c[4] ?? '') === A::AGENTE);
ok('balão 5 s depois = mesmo autor (agente)', ($c[5] ?? '') === A::AGENTE);
ok('mensagem do lead não ganha rótulo', !isset($c[2]) && !isset($c[3]));
$c = A::classificar([$m(1, 'outbound', 0), $m(2, 'outbound', 30), $m(3, 'outbound', 86400)]);
ok('abertura em dois balões = robô nos dois', ($c[1] ?? '') === A::DISPARO && ($c[2] ?? '') === A::DISPARO);
ok('nossa de novo um dia depois, sem resposta = follow-up', ($c[3] ?? '') === A::FOLLOWUP);
$c = A::classificar([$m(1, 'outbound', 0), $m(2, 'inbound', 60), $m(3, 'outbound', 7200)]);
ok('duas horas depois do lead, sem entrega = envio automático (não chuta)', ($c[3] ?? '') === A::AUTOMATICO);
$c = A::classificar([$m(1, 'outbound', 0), $m(2, 'inbound', 60), $m(3, 'outbound', 7200)], [$t + 7180]);
ok('logo depois de uma entrega ao agente = agente', ($c[3] ?? '') === A::AGENTE);
$c = A::classificar([$m(1, 'outbound', 0, false, A::CHAT), $m(2, 'outbound', 30, true, A::CELULAR), $m(3, 'outbound', 40, false)]);
ok('autor gravado vale: pessoa pelo Chat', ($c[1] ?? '') === A::CHAT);
ok('autor gravado vale: celular', ($c[2] ?? '') === A::CELULAR);
ok('sem payload e sem autor (sincronizada) = sem rótulo', !isset($c[3]));
$c = A::classificar([$m(1, 'outbound', 0, false, A::CHAT), $m(2, 'outbound', 20)]);
ok('balão da API logo depois de pessoa não herda "pelo Chat"', ($c[2] ?? '') !== A::CHAT);
ok('rótulos', A::rotulo(A::CHAT, 'Vitória', 'Ana') === 'Ana pelo Chat' && A::rotulo(A::DISPARO, 'Vitória') === 'Robô de disparo'
    && A::rotulo(A::AGENTE, 'Vitória') === 'Vitória' && A::rotulo(A::FOLLOWUP, 'Vitória') === 'Follow-up automático');
ok('nome curto do agente', A::nomeCurto('Vitória (pré-qualificação)') === 'Vitória' && A::nomeCurto('Isa') === 'Isa');

echo "\nSituacaoConversa::avaliar\n";
$base = ['grupo' => false, 'treino' => false, 'agente_disponivel' => true, 'numero_aberto' => true, 'agente_ligado' => true,
         'pausada' => false, 'pendentes' => [['ts' => $t, 'webhook' => false]], 'entregas' => [], 'agora' => $t + 3 * 86400];
$av = fn(array $mud) => S::avaliar(array_merge($base, $mud));
ok('última palavra nossa: não aguarda', $av(['pendentes' => []])['aguardando'] === false);
ok('grupo: não aguarda', $av(['grupo' => true])['aguardando'] === false);
$r = $av([]);
ok('chegou só pela sincronização: chegou_sem_webhook e pode mandar', $r['motivo'] === 'chegou_sem_webhook' && $r['pode_mandar'] && $r['desde'] === $t);
ok('chegou pelo webhook sem registro: sem_registro', $av(['pendentes' => [['ts' => $t, 'webhook' => true]]])['motivo'] === 'sem_registro');
$r = $av(['entregas' => [['ts' => $t + 5, 'ok' => true]]]);
ok('entregue há dias: sem_resposta e pode mandar de novo', $r['motivo'] === 'sem_resposta' && $r['pode_mandar'] && $r['entregue_em'] === $t + 5);
$r = $av(['entregas' => [['ts' => $t + 5, 'ok' => true]], 'agora' => $t + 60]);
ok('entregue há 1 min: respondendo, sem botão', $r['motivo'] === 'respondendo' && !$r['pode_mandar']);
ok('entrega com erro: entrega_falhou', $av(['entregas' => [['ts' => $t + 5, 'ok' => false]]])['motivo'] === 'entrega_falhou');
ok('entrega antiga (antes da mensagem) não conta', $av(['entregas' => [['ts' => $t - 3600, 'ok' => true]]])['motivo'] === 'chegou_sem_webhook');
$r = $av(['pausada' => true]);
ok('pausada: motivo pausada, sem botão de mandar', $r['motivo'] === 'pausada' && !$r['pode_mandar']);
ok('número fora do ar', $av(['numero_aberto' => false])['motivo'] === 'numero_fora');
ok('agente desligado no número', $av(['agente_ligado' => false])['motivo'] === 'agente_desligado');
ok('conta sem agente', $av(['agente_disponivel' => false])['motivo'] === 'sem_agente');
ok('número de treino', $av(['treino' => true])['motivo'] === 'treino');
ok('conta as mensagens do lead', $av(['pendentes' => [['ts' => $t, 'webhook' => false], ['ts' => $t + 1, 'webhook' => false]]])['quantas'] === 2);

echo "\nCom banco (conversa de teste)\n";
$ACC = (int)(getenv('FLEET_TESTE_CONTA') ?: 304);
$pdo = Database::getConnection();
$st = $pdo->prepare('SELECT id FROM whatsapp_instances WHERE account_id = ? ORDER BY id LIMIT 1');
$st->execute([$ACC]);
$INST = (int)$st->fetchColumn();
$st = $pdo->prepare("SELECT id FROM users WHERE account_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1");
$st->execute([$ACC]);
$UID = (int)$st->fetchColumn();
if (!$INST || !SdrFleetiflow::contaUsa($ACC)) { echo "(pulado: conta $ACC sem instância ou fora da edição CRM)\n"; goto fim; }

// Endereço falso do agente: conexão recusada na hora, nada sai da máquina.
$_ENV['FLEETIFLOW_SDR_WEBHOOK_URL'] = 'http://127.0.0.1:9/teste-situacao';
$comAgente = SdrFleetiflow::urlDaConta($ACC) === $_ENV['FLEETIFLOW_SDR_WEBHOOK_URL'];

$JID = '5511900011122@s.whatsapp.net';
$limpa = function () use ($pdo, $INST, $JID) {
    $pdo->prepare('DELETE FROM whatsapp_messages WHERE instance_id = ? AND remote_jid = ?')->execute([$INST, $JID]);
    $pdo->prepare('DELETE FROM whatsapp_chats WHERE instance_id = ? AND remote_jid = ?')->execute([$INST, $JID]);
    $pdo->prepare('DELETE FROM sdr_encaminhamentos WHERE instance_id = ? AND remote_jid = ?')->execute([$INST, $JID]);
    $pdo->prepare("DELETE FROM whatsapp_msg_autor WHERE instance_id = ? AND wamid LIKE 'TESTESIT%'")->execute([$INST]);
};
$st = $pdo->prepare('SELECT status FROM whatsapp_instances WHERE id = ?');
$st->execute([$INST]);
$statusAntes = (string)$st->fetchColumn();
$st = $pdo->prepare('SELECT id, enabled FROM agent_configs WHERE whatsapp_instance_id = ?');
$st->execute([$INST]);
$agenteAntes = $st->fetch(PDO::FETCH_ASSOC) ?: null;

try {
    $limpa();
    $pdo->prepare("UPDATE whatsapp_instances SET status = 'open' WHERE id = ?")->execute([$INST]);
    $agenteCriado = null;
    if ($agenteAntes) $pdo->prepare('UPDATE agent_configs SET enabled = 1 WHERE id = ?')->execute([(int)$agenteAntes['id']]);
    elseif ($UID) {
        $pdo->prepare('INSERT INTO agent_configs (account_id, user_id, whatsapp_instance_id, enabled) VALUES (?, ?, ?, 1)')->execute([$ACC, $UID, $INST]);
        $agenteCriado = (int)$pdo->lastInsertId();
    }

    $pdo->prepare("INSERT INTO whatsapp_chats (account_id, instance_id, remote_jid, contact_name, phone, last_message_at, created_at, updated_at)
                   VALUES (?, ?, ?, 'Teste Situação', '5511900011122', NOW(), NOW(), NOW())")->execute([$ACC, $INST, $JID]);
    $ins = $pdo->prepare("INSERT INTO whatsapp_messages (account_id, instance_id, wamid, remote_jid, phone, message_type, message_content, direction, status, raw_payload, created_at)
                          VALUES (?, ?, ?, ?, '5511900011122', 'text', ?, ?, 'sent', ?, ?)");
    $h = fn($s) => date('Y-m-d H:i:s', time() - $s);
    $ins->execute([$ACC, $INST, 'TESTESIT1', $JID, 'Olá! Sou a Vitória', 'outbound', '{}', $h(4 * 86400)]);
    $ins->execute([$ACC, $INST, 'TESTESIT2', $JID, 'Oi, sou eu', 'inbound', '{}', $h(4 * 86400 - 20)]);
    $ins->execute([$ACC, $INST, 'TESTESIT3', $JID, 'Queria falar com quem cuida das multas', 'outbound', '{}', $h(4 * 86400 - 40)]);
    $ins->execute([$ACC, $INST, 'TESTESIT4', $JID, 'Ana aqui, pelo Chat', 'outbound', null, $h(4 * 86400 - 600)]);
    $ins->execute([$ACC, $INST, 'TESTESIT5', $JID, 'Bom dia', 'inbound', null, $h(3 * 86400)]);
    $ins->execute([$ACC, $INST, 'TESTESIT6', $JID, 'sou eu mesmo', 'inbound', null, $h(3 * 86400 - 1)]);
    A::registrar($ACC, $INST, 'TESTESIT4', A::CHAT, $UID ?: null);
    A::registrar($ACC, $INST, 'TESTESIT4', A::CELULAR); // segundo registro não troca o primeiro

    $msgs = $pdo->prepare('SELECT id, wamid, direction, created_at FROM whatsapp_messages WHERE instance_id = ? AND remote_jid = ? ORDER BY created_at, id');
    $msgs->execute([$INST, $JID]);
    $rot = A::rotular($ACC, $INST, $JID, $msgs->fetchAll(PDO::FETCH_ASSOC));
    $por = array_column($rot, null, 'wamid');
    ok('rotular: abertura = Robô de disparo', ($por['TESTESIT1']['autor'] ?? '') === A::DISPARO);
    ok('rotular: resposta ao lead = agente', ($por['TESTESIT3']['autor'] ?? '') === A::AGENTE);
    ok('rotular: gravada pelo Chat vence, e o 2º registro não trocou', ($por['TESTESIT4']['autor'] ?? '') === A::CHAT && str_ends_with($por['TESTESIT4']['autor_rotulo'] ?? '', 'pelo Chat'));
    ok('rotular: mensagem do lead sem rótulo', !isset($por['TESTESIT5']['autor']));
    $soUltima = A::rotular($ACC, $INST, $JID, [$por['TESTESIT3']]);
    ok('rotular só a última (poll): olha o que veio antes', ($soUltima[0]['autor'] ?? '') === A::AGENTE);

    $s = S::daConversa($ACC, $INST, $JID);
    ok('aguarda há 3 dias, 2 mensagens do lead', $s && $s['aguardando'] && $s['quantas'] === 2);
    if ($comAgente) {
        ok('motivo: chegou sem webhook, com botão', $s['motivo'] === 'chegou_sem_webhook' && $s['pode_mandar']);
        $r = S::mandarParaAgente($ACC, $INST, $JID, $UID ?: null);
        $q = $pdo->prepare("SELECT COUNT(*) total, SUM(ok) ok, MIN(origem) origem FROM sdr_encaminhamentos WHERE instance_id = ? AND remote_jid = ?");
        $q->execute([$INST, $JID]);
        $reg = $q->fetch(PDO::FETCH_ASSOC);
        ok('mandar com o agente fora: diz que falhou e registra as 2 entregas', !$r['ok'] && (int)$reg['total'] === 2 && (int)$reg['ok'] === 0 && $reg['origem'] === 'manual');
        $s = S::daConversa($ACC, $INST, $JID);
        ok('depois da falha: motivo entrega_falhou', $s['motivo'] === 'entrega_falhou');
        $pdo->prepare("UPDATE sdr_encaminhamentos SET ok = 1, created_at = ? WHERE instance_id = ? AND remote_jid = ?")
            ->execute([date('Y-m-d H:i:s', time() - 30), $INST, $JID]);
        $r = S::mandarParaAgente($ACC, $INST, $JID, $UID ?: null);
        ok('entregue há 30 s: não deixa mandar de novo', !$r['ok']);
        $pdo->prepare("UPDATE sdr_encaminhamentos SET created_at = ? WHERE instance_id = ? AND remote_jid = ?")
            ->execute([date('Y-m-d H:i:s', time() - 3600), $INST, $JID]);
        ok('entregue há 1 h sem resposta: sem_resposta', S::daConversa($ACC, $INST, $JID)['motivo'] === 'sem_resposta');
    } else {
        echo "  (conta com marca própria: entrega ao agente não testada)\n";
    }
    $pdo->prepare('UPDATE whatsapp_chats SET agent_paused = 1, agent_paused_by = ?, agent_paused_at = NOW() WHERE instance_id = ? AND remote_jid = ?')
        ->execute([$UID ?: null, $INST, $JID]);
    $s = S::daConversa($ACC, $INST, $JID);
    ok('pausada: motivo pausada e quem pausou', $s['motivo'] === 'pausada' && ($UID === 0 || $s['pausada_por'] !== null));
    ok('pausada: mandar é recusado', S::mandarParaAgente($ACC, $INST, $JID, $UID ?: null)['ok'] === false);

    $outra = (int)$pdo->query("SELECT id FROM accounts WHERE id <> $ACC ORDER BY id LIMIT 1")->fetchColumn();
    ok('outra conta não enxerga a conversa', S::daConversa($outra, $INST, $JID) === null);
} finally {
    $limpa();
    $pdo->prepare('UPDATE whatsapp_instances SET status = ? WHERE id = ?')->execute([$statusAntes, $INST]);
    if ($agenteAntes) $pdo->prepare('UPDATE agent_configs SET enabled = ? WHERE id = ?')->execute([(int)$agenteAntes['enabled'], (int)$agenteAntes['id']]);
    if (!empty($agenteCriado)) $pdo->prepare('DELETE FROM agent_configs WHERE id = ?')->execute([$agenteCriado]);
}

fim:
echo "\n----\nResultado: $ok ok · $falhas falha(s)\n";
exit($falhas > 0 ? 1 : 0);
