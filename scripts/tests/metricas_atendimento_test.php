<?php
/**
 * Métricas de atendimento do WhatsApp (App\WhatsAppAgente\MetricasAtendimento).
 *
 * Confere a regra pura (sem banco): a espera abre na primeira mensagem do contato
 * sem resposta e fecha na próxima nossa; mensagens seguidas do contato contam uma
 * só; quem respondeu pelo CRM conta para si, o resto para o dono da conversa; o
 * agente de IA fecha a espera sem contar para operador; primeira resposta;
 * faixas; mediana; sem resposta. Depois, com banco: o relatório da conta 304
 * (número 11) com mensagens ZZMETR, o filtro do vendedor e o escopo por conta.
 * Precisa da migration 165. Desfaz tudo no fim.
 *
 *   php scripts/tests/metricas_atendimento_test.php
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\WhatsAppAgente\MetricasAtendimento as M;

$OK = 0; $FALHAS = [];
function ok(string $msg, bool $cond): void { global $OK, $FALHAS; if ($cond) { $OK++; echo "  [ok]   $msg\n"; } else { $FALHAS[] = $msg; echo "  [FALHA] $msg\n"; } }
function msg(string $jid, string $dir, string $quando, ?int $por = null, bool $bot = false): array
{
    return ['instance_id' => 1, 'remote_jid' => $jid, 'direction' => $dir, 'created_at' => $quando, 'sent_by_user_id' => $por, 'bot' => $bot];
}

echo "\n== 1. Regra pura ==\n";
$A = '5511900000001@s.whatsapp.net'; $B = '5511900000002@s.whatsapp.net'; $C = '5511900000003@s.whatsapp.net';
$nomes = [10 => 'Isa', 20 => 'Kaue'];
$donos = ['1|' . $A => 10, '1|' . $B => 20, '1|' . $C => null];
$msgs = [
    // A: contato escreve 3 vezes seguidas, Isa responde 4 min depois da primeira; depois escreve de novo, responde em 60 min.
    msg($A, 'inbound', '2026-10-20 09:00:00'), msg($A, 'inbound', '2026-10-20 09:01:00'), msg($A, 'inbound', '2026-10-20 09:02:00'),
    msg($A, 'outbound', '2026-10-20 09:04:00', 10),
    msg($A, 'inbound', '2026-10-20 10:00:00'), msg($A, 'outbound', '2026-10-20 11:00:00', 10),
    // B: a Vitória responde em 1 min (não conta para operador); depois o contato escreve e o Kauê responde em 10 min;
    // uma mensagem sem autor (automação) depois não abre nada; no fim o contato escreve e ninguém responde.
    msg($B, 'inbound', '2026-10-21 14:00:00'), msg($B, 'outbound', '2026-10-21 14:01:00', null, true),
    msg($B, 'inbound', '2026-10-21 15:00:00'), msg($B, 'outbound', '2026-10-21 15:10:00', 20),
    msg($B, 'outbound', '2026-10-21 16:00:00'),
    msg($B, 'inbound', '2026-10-21 18:00:00'),
    // C: sem dono; resposta sem autor cai em "Sem operador", 2 dias depois.
    msg($C, 'inbound', '2026-10-19 08:00:00'), msg($C, 'outbound', '2026-10-21 08:00:00'),
    // Fora do período: não conta.
    msg($A, 'inbound', '2026-10-10 09:00:00'), msg($A, 'outbound', '2026-10-10 09:30:00', 10),
];
usort($msgs, fn($x, $y) => [$x['remote_jid'], $x['created_at']] <=> [$y['remote_jid'], $y['created_at']]);
$r = M::calcular($msgs, $donos, $nomes, '2026-10-19', '2026-10-21');
$por = array_column($r['operadores'], null, 'user_id');
ok('Isa: 2 respostas (as 3 seguidas do contato contam uma), média 32 min', $por[10]['respostas'] === 2 && abs($por[10]['media'] - 32) < .01);
ok('Isa: mediana 32, maior 60, 1 em até 5 min (50%)', abs($por[10]['mediana'] - 32) < .01 && $por[10]['maior'] == 60 && $por[10]['ate_meta'] === 1 && abs($por[10]['ate_meta_pct'] - 50) < .01);
ok('Isa: primeira resposta em 4 min, 1 conversa, 2 enviadas', abs($por[10]['media_primeira'] - 4) < .01 && $por[10]['primeiras'] === 1 && $por[10]['conversas'] === 1 && $por[10]['enviadas'] === 2);
ok('Kauê: 1 resposta de 10 min; a automação não abriu espera; 2 enviadas', $por[20]['respostas'] === 1 && $por[20]['media'] == 10 && $por[20]['enviadas'] === 2);
ok('Kauê: a conversa terminou sem resposta', $por[20]['sem_resposta'] === 1 && $r['equipe']['sem_resposta'] === 1);
ok('Vitória: 1 resposta em 1 min, fora dos operadores', $r['bot']['respostas'] === 1 && $r['bot']['soma'] == 1 && !isset($por[0]) === false);
ok('sem dono: "Sem operador", 2 dias', isset($por[0]) && $por[0]['nome'] === 'Sem operador' && $por[0]['respostas'] === 1 && $por[0]['media'] == 2880);
ok('a primeira resposta do Kauê é a dele (a da Vitória não zera a primeira)', $por[20]['primeiras'] === 1);
ok('fora do período não conta: 4 respostas e 8 recebidas', $r['equipe']['respostas'] === 4 && $r['equipe']['recebidas'] === 8);
ok('equipe: 3 conversas com contato, mediana das 4 respostas', $r['equipe']['conversas'] === 3 && abs($r['equipe']['mediana'] - 35) < .01);
ok('faixas: 4 min, 10 min, 60 min e 2 dias caem cada uma na sua', $r['faixas']['ate_5'] === 1 && $r['faixas']['ate_30'] === 1 && $r['faixas']['ate_2h'] === 1 && $r['faixas']['mais_24h'] === 1);
ok('dia a dia: 20/10 com 2 respostas, 21/10 com 2', $r['dias']['2026-10-20']['respostas'] === 2 && $r['dias']['2026-10-21']['respostas'] === 2);
ok('ranking: quem respondeu mais primeiro', $r['operadores'][0]['user_id'] === 10);
ok('faixa: 5 min bom, 20 atenção, 31 crítico, null sem', M::faixa(5) === 'bom' && M::faixa(20) === 'atencao' && M::faixa(31) === 'ruim' && M::faixa(null) === 'sem');
ok('duração legível', M::duracao(0.4) === 'menos de 1 min' && M::duracao(32) === '32 min' && M::duracao(65) === '1 h 05' && M::duracao(2880) === '2 d 00 h');
ok('período: inverte quando de > até e limita a 92 dias', M::periodo('2026-10-21', '2026-10-01') === ['2026-10-01', '2026-10-21'] && M::periodo('2025-01-01', '2026-10-21')[0] === '2026-07-22');
$s = M::serieDiaria($r['dias'], '2026-10-19', '2026-10-21');
ok('série diária preenche os dias sem resposta com zero', $s['respostas'] === [0, 2, 2] && $s['media'][0] === null && $s['rotulos'][0] === '19/10');

echo "\n== 2. Com banco (conta 304, número 11) ==\n";
$pdo = Database::getConnection();
$INST = 11; $CONTA = 304;
$temSentBy = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_messages' AND COLUMN_NAME = 'sent_by_user_id'")->fetchColumn() > 0;
if (!$temSentBy) { echo "  (pulado: rode a migration 165)\n"; }
else {
    $jids = ['5511900990001@s.whatsapp.net', '5511900990002@s.whatsapp.net'];
    $users = [];
    try {
        foreach (['Isa', 'Kaue'] as $n) {
            $pdo->prepare("INSERT INTO users (account_id, nome, login, senha_hash, perfil, role, status) VALUES (?, ?, ?, 'x', 'user', 'user', 'active')")->execute([$CONTA, 'ZZMETR ' . $n, 'zzmetr_' . bin2hex(random_bytes(4)) . '@teste.local']);
            $users[$n] = (int) $pdo->lastInsertId();
        }
        $hoje = date('Y-m-d');
        $ins = $pdo->prepare('INSERT INTO whatsapp_messages (account_id, instance_id, wamid, remote_jid, direction, status, message_type, message_content, created_at, sent_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $m = function (string $jid, string $dir, string $hora, ?int $por = null) use ($ins, $INST, $CONTA, $hoje) {
            $ins->execute([$CONTA, $INST, 'ZZMETR' . bin2hex(random_bytes(6)), $jid, $dir, 'sent', 'text', 'ZZMETR', "$hoje $hora", $por]);
        };
        $pdo->prepare("INSERT INTO whatsapp_chats (account_id, instance_id, remote_jid, contact_name, linked_user_id, last_message_from_me, last_message_at) VALUES (?, ?, ?, 'ZZMETR um', ?, 0, ?)")->execute([$CONTA, $INST, $jids[0], $users['Isa'], "$hoje 12:00:00"]);
        $pdo->prepare("INSERT INTO whatsapp_chats (account_id, instance_id, remote_jid, contact_name, linked_user_id, last_message_from_me, last_message_at) VALUES (?, ?, ?, 'ZZMETR dois', ?, 1, ?)")->execute([$CONTA, $INST, $jids[1], $users['Kaue'], "$hoje 10:08:00"]);
        $m($jids[0], 'inbound', '09:00:00'); $m($jids[0], 'outbound', '09:06:00', $users['Isa']); $m($jids[0], 'inbound', '12:00:00');
        $m($jids[1], 'inbound', '10:00:00'); $m($jids[1], 'outbound', '10:08:00', $users['Kaue']);

        $rel = M::relatorio($CONTA, [$INST], $hoje, $hoje);
        $por = array_column($rel['operadores'], null, 'user_id');
        ok('relatório da conta: Isa 6 min e Kauê 8 min', isset($por[$users['Isa']], $por[$users['Kaue']]) && $por[$users['Isa']]['media'] == 6 && $por[$users['Kaue']]['media'] == 8);
        ok('equipe: média 7 min, 1 sem resposta', abs($rel['equipe']['media'] - 7) < .01 && $rel['equipe']['sem_resposta'] === 1);
        ok('esperando agora: o contato da Isa, com o operador certo', count(array_filter($rel['aguardando'], fn($a) => $a['remote_jid'] === $jids[0] && $a['operador'] === 'ZZMETR Isa')) === 1 && !in_array($jids[1], array_column($rel['aguardando'], 'remote_jid'), true));
        $relV = M::relatorio($CONTA, [$INST], $hoje, $hoje, $users['Kaue']);
        ok('vendedor vê só o próprio atendimento', $relV['equipe']['respostas'] === 1 && $relV['equipe']['media'] == 8 && $relV['foco']['user_id'] === $users['Kaue'] && !in_array($jids[0], array_column($relV['aguardando'], 'remote_jid'), true));
        $relF = M::relatorio($CONTA, [$INST], $hoje, $hoje, null, $users['Isa']);
        ok('filtro por operador (admin): só a Isa, e o "esperando" dela', $relF['foco']['metricas']['respostas'] === 1 && count($relF['aguardando']) === 1);
        ok('outra conta não vê nada (números não são dela)', M::relatorio(476, [], $hoje, $hoje)['equipe']['respostas'] === 0);
        ok('a lista de operadores e de números vem da conta', in_array($users['Isa'], array_column($rel['operadores_lista'], 'user_id'), true) && array_column($rel['numeros'], 'id') === [$INST]);
    } finally {
        $pdo->exec("DELETE FROM whatsapp_messages WHERE instance_id = $INST AND message_content = 'ZZMETR'");
        foreach ($jids as $j) $pdo->prepare('DELETE FROM whatsapp_chats WHERE instance_id = ? AND remote_jid = ?')->execute([$INST, $j]);
        foreach ($users as $u) $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$u]);
    }
}

echo "\n$OK ok/" . count($FALHAS) . "\n";
exit($FALHAS ? 1 : 0);
