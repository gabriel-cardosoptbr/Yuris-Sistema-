<?php
/**
 * followup_test.php: o follow-up automático (App\FollowUp, migration 168).
 *
 *   1. Regra, sem banco: horário de envio, rajadas, passo da vez, quem respondeu,
 *      conversa antiga, etapa, com quem, texto com {nome}/{empresa}, escolha da
 *      cadência e a validação do que vem da tela.
 *   2. Com banco (conta 304 local, número 11, envio de mentira): a conversa do
 *      card, a resposta que chega pelo @lid, o card do funil, o envio (texto,
 *      registro, mensagem gravada, card movido), o intervalo do número, a chave
 *      geral e o n8n saindo do ar quando o Yuris cuida de quem não respondeu.
 *
 * Desfaz tudo no fim. Uso: php scripts/tests/followup_test.php
 */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\FollowUp\Cadencia;
use App\FollowUp\Envio;
use App\FollowUp\Fatos;
use App\FollowUp\Regra as R;
use App\WhatsAppAgente\AutomacaoSdr;

$OK = 0; $FALHAS = [];
function ok(string $m, bool $c): void { global $OK, $FALHAS; if ($c) { $OK++; echo "  [ok]   $m\n"; } else { $FALHAS[] = $m; echo "  [FALHA] $m\n"; } }
$sp = fn(string $s) => (new \DateTimeImmutable($s, new \DateTimeZone('America/Sao_Paulo')))->getTimestamp();
$fmt = fn(int $t) => (new \DateTimeImmutable('@' . $t))->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('D Y-m-d H:i');

echo "== 1. Regra ==\n";
$h = ['dias' => [1, 2, 3, 4, 5, 6], 'de' => '08:00', 'ate' => '18:00'];
// 23/10/2026 é sexta.
ok('dentro do horário sai na hora', R::proximaJanela($sp('2026-10-20 10:15'), $h) === $sp('2026-10-20 10:15'));
ok('de madrugada espera as 8h do mesmo dia', R::proximaJanela($sp('2026-10-20 03:00'), $h) === $sp('2026-10-20 08:00'));
ok('sexta 20h vai para sábado 8h', R::proximaJanela($sp('2026-10-23 20:00'), $h) === $sp('2026-10-24 08:00'));
ok('sábado 19h pula o domingo e vai para segunda 8h', R::proximaJanela($sp('2026-10-24 19:00'), $h) === $sp('2026-10-26 08:00'));
ok('horário inválido volta ao padrão', R::horario(['dias' => [], 'de' => '19:00', 'ate' => '07:00']) === R::HORARIO_PADRAO);

ok('balões seguidos são uma rajada só', R::rajadas([0, 60, 200]) === 1);
ok('mensagens separadas por horas são rajadas diferentes', R::rajadas([0, 60, 5000, 90000]) === 3);

$cad = ['gatilho' => 'sem_resposta', 'com_quem' => 'todas', 'etapas' => [10, 12], 'inicio_max_dias' => 3,
        'horario' => ['dias' => [1, 2, 3, 4, 5, 6, 7], 'de' => '00:00', 'ate' => '23:59'],
        'passos' => [['espera_min' => 300, 'texto' => 'p1'], ['espera_min' => 1080, 'texto' => 'p2'], ['espera_min' => 2640, 'texto' => 'p3']]];
$T = $sp('2026-10-20 10:00');
$f = ['etapa' => 10, 'tem_entrada' => false, 'ultima_entrada' => null, 'saidas' => [$T, $T + 30]];
$s = R::situacao($cad, $f, $T + 3600);
ok('abertura sem resposta: passo 1 de 3, 5 h depois', $s['estado'] === 'agendado' && $s['passo'] === 1 && $s['total'] === 3 && $s['previsto'] === $T + 30 + 300 * 60);
ok('o ciclo começa na abertura', $s['ciclo_desde'] === $T);
$s = R::situacao($cad, ['saidas' => [$T, $T + 6 * 3600]] + $f, $T + 7 * 3600);
ok('depois do follow-up 1, o passo 2 conta 18 h do último envio', $s['passo'] === 2 && $s['previsto'] === $T + 6 * 3600 + 1080 * 60);
$s = R::situacao($cad, ['saidas' => [$T, $T + 6 * 3600, $T + 30 * 3600, $T + 80 * 3600]] + $f, $T + 90 * 3600);
ok('os 3 follow-ups dados: cadência encerrada', $s['estado'] === 'encerrada' && $s['passo'] === 3);
$s = R::situacao($cad, ['tem_entrada' => true, 'ultima_entrada' => $T + 100, 'saidas' => [$T]] + $f, $T + 3600);
ok('o lead escreveu por último: fora (é a vez de responder a ele)', $s['estado'] === 'fora' && $s['motivo'] === 'lead_falou');
$s = R::situacao($cad, ['tem_entrada' => true, 'ultima_entrada' => $T - 100, 'saidas' => [$T]] + $f, $T + 3600);
ok('"não respondeu" não vale para quem já respondeu', $s['motivo'] === 'respondeu');
$s = R::situacao(['gatilho' => 'parou'] + $cad, ['tem_entrada' => true, 'ultima_entrada' => $T - 100, 'saidas' => [$T]] + $f, $T + 3600);
ok('"respondeu e parou" vale para quem respondeu e ficou quieto', $s['estado'] === 'agendado' && $s['passo'] === 1);
$s = R::situacao(['gatilho' => 'parou'] + $cad, $f, $T + 3600);
ok('"respondeu e parou" não vale para quem nunca respondeu', $s['motivo'] === 'nao_respondeu');
$s = R::situacao($cad, $f, $T + 4 * 86400);
ok('silêncio mais velho que o limite não começa a cadência', $s['motivo'] === 'antiga');
$s = R::situacao($cad, ['saidas' => [$T - 2 * 86400, $T - 46 * 3600]] + $f, $T);
ok('quem já está no passo 2 segue, mesmo com a abertura mais velha que o limite', $s['estado'] === 'agendado' && $s['passo'] === 2);
$s = R::situacao($cad, ['saidas' => [$T - 9 * 86400, $T - 8 * 86400]] + $f, $T);
ok('passo vencido há mais que o limite não sai (cadência parada não recomeça sozinha)', $s['estado'] === 'fora' && $s['motivo'] === 'parada');
ok('card fora das etapas da cadência fica de fora', R::situacao($cad, ['etapa' => 99] + $f, $T)['motivo'] === 'etapa');
ok('"só com o robô" pula conversa com pessoa', R::situacao(['com_quem' => 'robo'] + $cad, ['com_robo' => false] + $f, $T)['motivo'] === 'com_quem');
ok('"só com uma pessoa" pula conversa com o robô', R::situacao(['com_quem' => 'pessoa'] + $cad, ['com_robo' => true] + $f, $T)['motivo'] === 'com_quem');
ok('sem mensagem nossa não há follow-up', R::situacao($cad, ['saidas' => []] + $f, $T)['motivo'] === 'sem_mensagem');

ok('{nome} vira o primeiro nome', R::texto('Oi {nome}, tudo bem?', ['nome' => 'Carla Souza']) === 'Oi Carla, tudo bem?');
ok('{nome} vazio some sem sobrar espaço', R::texto('Oi {nome}, tudo bem?', ['nome' => '']) === 'Oi, tudo bem?');
ok('nome que é telefone não entra no texto', R::texto('Oi {nome}!', ['nome' => '+55 11 98888-7777']) === 'Oi!');
ok('nome que é a própria empresa não entra no texto', R::texto('Oi {nome}, tudo bem?', ['nome' => 'KWM Express', 'empresa' => 'KWM Express Ltda']) === 'Oi, tudo bem?');
ok('nome de gente com a empresa ao lado entra', R::texto('Oi {nome}!', ['nome' => 'Alanna', 'empresa' => 'KWM Express']) === 'Oi Alanna!');
ok('{empresa} vira a empresa do card', R::texto('Como está a {empresa}?', ['empresa' => 'Blue Transportes']) === 'Como está a Blue Transportes?');
ok('{vendedor} vira o nome do vendedor do card', R::texto('Aqui é {vendedor}, da Fleetiflow.', ['vendedor' => 'Kaue']) === 'Aqui é Kaue, da Fleetiflow.');
ok('{vendedor} vazio some sem sobrar espaço', R::texto('Oi {nome}! Aqui é {vendedor}.', ['nome' => 'Ana', 'vendedor' => '']) === 'Oi Ana! Aqui é.');

$a = ['id' => 1, 'ativo' => false] + $cad; $b = ['id' => 2, 'ativo' => true] + $cad; $c = ['id' => 3, 'ativo' => true, 'gatilho' => 'qualquer'] + $cad;
ok('cadência desligada não conta', R::escolher([$a, $b], $f, $T)[0]['id'] === 2);
ok('a primeira ativa que serve ganha', R::escolher([$b, $c], $f, $T)[0]['id'] === 2);
ok('nenhuma serve: nada', R::escolher([$b], ['etapa' => 99] + $f, $T) === [null, null]);

$base = ['nome' => 'X', 'gatilho' => 'sem_resposta', 'com_quem' => 'todas', 'etapas' => [10], 'inicio_max_dias' => 3,
         'horario' => ['dias' => [1, 2], 'de' => '08:00', 'ate' => '18:00'], 'passos' => [['espera_min' => 300, 'texto' => 'oi']]];
ok('validação: o que vem certo passa', Cadencia::validar($base, [10, 11])['ok'] === true);
ok('validação: espera de menos de 30 min é recusada', !Cadencia::validar(['passos' => [['espera_min' => 10, 'texto' => 'oi']]] + $base, [10])['ok']);
ok('validação: mensagem vazia é recusada', !Cadencia::validar(['passos' => [['espera_min' => 300, 'texto' => '  ']]] + $base, [10])['ok']);
ok('validação: sem dia da semana é recusada', !Cadencia::validar(['horario' => ['dias' => [], 'de' => '08:00', 'ate' => '18:00']] + $base, [10])['ok']);
ok('validação: horário que termina antes de começar é recusado', !Cadencia::validar(['horario' => ['dias' => [1], 'de' => '18:00', 'ate' => '08:00']] + $base, [10])['ok']);
ok('validação: etapa de outra conta é recusada', !Cadencia::validar(['etapas' => [999]] + $base, [10])['ok']);

// Previsão de saída (Envio::estimar): ordem na fila do número e faixa de 30 a 40 min, no horário.
$ag = fn(int $card, int $inst, int $prev) => ['card_id' => $card, 'fatos' => ['chat' => ['instance_id' => $inst]], 'cadencia' => ['horario' => $h], 'situacao' => ['previsto' => $prev]];
$A = $sp('2026-10-30 13:45'); // sexta
$e = Envio::estimar([$ag(1, 7, $A - 3600), $ag(2, 7, $A - 1800), $ag(3, 7, $A - 600)], $A, [7 => $sp('2026-10-30 14:18')]);
ok('o 1º da fila sai exatamente quando o número libera', $e[1]['ordem'] === 1 && $e[1]['de'] === $sp('2026-10-30 14:18') && $e[1]['ate'] === $sp('2026-10-30 14:18'));
ok('o 2º sai de 30 a 40 min depois', $e[2]['ordem'] === 2 && $e[2]['de'] === $sp('2026-10-30 14:48') && $e[2]['ate'] === $sp('2026-10-30 14:58') && $e[2]['na_frente'] === 1);
ok('o 3º, de 60 a 80 min depois do 1º', $e[3]['ordem'] === 3 && $e[3]['de'] === $sp('2026-10-30 15:18') && $e[3]['ate'] === $sp('2026-10-30 15:38'));
$e = Envio::estimar([$ag(1, 7, $A + 7200)], $A, [7 => 0]);
ok('sem fila, sai na hora da cadência', $e[1]['de'] === $A + 7200 && $e[1]['ate'] === $A + 7200 && $e[1]['na_frente'] === 0);
$e = Envio::estimar([$ag(1, 7, $A - 600), $ag(2, 8, $A - 300)], $A, [7 => $A + 3000, 8 => 0]);
ok('cada número tem a sua fila e a sua ordem', $e[1]['de'] === $A + 3000 && $e[2]['de'] === $A && $e[1]['ordem'] === 1 && $e[2]['ordem'] === 1);
$e = Envio::estimar([$ag(1, 7, $sp('2026-10-30 17:00')), $ag(2, 7, $sp('2026-10-30 17:10'))], $sp('2026-10-30 17:30'), [7 => $sp('2026-10-30 17:50')]);
ok('o que não cabe até as 18h sai na próxima janela (sábado 8h)', $e[1]['de'] === $sp('2026-10-30 17:50') && $e[2]['de'] === $sp('2026-10-31 08:00') && $e[2]['ate'] === $sp('2026-10-31 08:00'));

echo "\n== 2. Com banco (conta 304, número 11) ==\n";
$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$CONTA = 304; $INST = 11;
$temTabela = (bool) $pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'followup_envios'")->fetchColumn();
$cols = $pdo->query("SELECT id FROM pipeline_columns WHERE account_id = $CONTA ORDER BY ordem, id LIMIT 3")->fetchAll(\PDO::FETCH_COLUMN);
if (!$temTabela || count($cols) < 3) {
    echo "  [pulado] precisa da migration 168 e do funil da conta 304\n";
} else {
    [$colNovo, $colQualif, $colFu] = array_map('intval', $cols);
    $fone = '55119' . str_pad((string) (getmypid() % 100000000), 8, '0', STR_PAD_LEFT);
    $jid = $fone . '@s.whatsapp.net';
    $lid = '9' . $fone . '@lid';
    $statusAntes = $pdo->query("SELECT status FROM whatsapp_instances WHERE id = $INST")->fetchColumn();
    $chaveAntes = $pdo->query("SELECT config_value FROM whatsapp_settings WHERE account_id = $CONTA AND config_key = 'sdr_followup_ligado'")->fetchColumn();
    $cardId = 0; $cadId = 0;
    // Cadência que já exista na conta de teste fica desligada durante o teste e volta no fim.
    $ligadasAntes = array_map('intval', $pdo->query("SELECT id FROM followup_cadencias WHERE account_id = $CONTA AND ativo = 1")->fetchAll(\PDO::FETCH_COLUMN));
    $pdo->exec("UPDATE followup_cadencias SET ativo = 0 WHERE account_id = $CONTA");
    try {
        $pdo->prepare('INSERT INTO cards (account_id, coluna_id, titulo, cliente_nome, empresa_nome, telefone_whatsapp, created_at) VALUES (?,?,?,?,?,?, NOW())')
            ->execute([$CONTA, $colNovo, 'ZZTESTE_FU', 'Fulano Silva', 'ZZTESTE Transportes', $fone]);
        $cardId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO whatsapp_chats (account_id, instance_id, remote_jid, contact_name, is_group, linked_card_id, last_message_at) VALUES (?,?,?,?,0,?,?)')
            ->execute([$CONTA, $INST, $jid, 'Fulano', $cardId, date('Y-m-d H:i:s', time() - 6 * 3600)]);
        $msg = $pdo->prepare('INSERT INTO whatsapp_messages (account_id, instance_id, wamid, remote_jid, phone, message_type, message_content, direction, status, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $msg->execute([$CONTA, $INST, 'ZZFU' . bin2hex(random_bytes(6)), $jid, $fone, 'text', 'Olá! Abertura de teste', 'outbound', 'sent', date('Y-m-d H:i:s', time() - 6 * 3600)]);

        $r = Cadencia::salvar($CONTA, [
            'nome' => 'ZZTESTE sem resposta', 'gatilho' => 'sem_resposta', 'com_quem' => 'todas', 'etapas' => [$colNovo, $colFu],
            'mover_para' => $colFu, 'inicio_max_dias' => 3, 'horario' => ['dias' => [1, 2, 3, 4, 5, 6, 7], 'de' => '00:00', 'ate' => '23:59'],
            'passos' => [['espera_min' => 300, 'texto' => 'Oi {nome}, passo 1 da {empresa}'], ['espera_min' => 1080, 'texto' => 'passo 2'], ['espera_min' => 2640, 'texto' => 'passo 3']],
        ], 0);
        $cadId = (int) ($r['id'] ?? 0);
        ok('a cadência é criada desligada', $r['ok'] && Cadencia::buscar($cadId, $CONTA)['ativo'] === false);
        ok('outra conta não enxerga a cadência', Cadencia::buscar($cadId, $CONTA + 1) === null);
        ok('desligada, o n8n segue com o follow-up dele', AutomacaoSdr::paraOn8n($CONTA)['followup'] === AutomacaoSdr::estado($CONTA)['followup']);
        Cadencia::ligar($cadId, $CONTA, true, 0);
        ok('ligada, o n8n recebe follow-up desligado (o lead não recebe dois)', AutomacaoSdr::paraOn8n($CONTA)['followup'] === false);

        $f = Fatos::porCards([$CONTA], [$cardId])[$cardId] ?? null;
        ok('a conversa do card: nunca respondeu, uma mensagem nossa', $f && !$f['tem_entrada'] && count($f['saidas']) === 1 && $f['chat']['remote_jid'] === $jid);
        ok('outra conta não lê a conversa do card', Fatos::porCards([$CONTA + 1], [$cardId]) === []);
        $card = ['id' => $cardId, 'account_id' => $CONTA, 'coluna_id' => $colNovo];
        $fu = Fatos::paraOFunil([$CONTA], [$card])[$cardId]['followup'] ?? null;
        ok('o card mostra o follow-up 1 de 3, já na fila', $fu && $fu['estado'] === 'agendado' && $fu['passo'] === 1 && $fu['total'] === 3 && $fu['na_fila']);

        // Envio de mentira.
        $pdo->exec("UPDATE whatsapp_instances SET status = 'open' WHERE id = $INST");
        $mandados = [];
        $falso = function (array $cfg, string $inst, string $j, string $txt) use (&$mandados) { $mandados[] = [$j, $txt]; return ['key' => ['id' => 'ZZFU' . bin2hex(random_bytes(6))]]; };
        $res = Envio::processar($falso);
        ok('o worker manda o passo 1', $res['enviadas'] === 1 && count($mandados) === 1 && $mandados[0][0] === $jid);
        ok('com o nome e a empresa no texto', ($mandados[0][1] ?? '') === 'Oi Fulano, passo 1 da ZZTESTE Transportes');
        $e = $pdo->query("SELECT * FROM followup_envios WHERE cadencia_id = $cadId")->fetch(\PDO::FETCH_ASSOC);
        ok('o envio fica registrado como enviado', $e && $e['status'] === 'enviado' && (int) $e['passo'] === 1 && (int) $e['card_id'] === $cardId);
        ok('a mensagem fica na conversa', (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_messages WHERE instance_id = $INST AND remote_jid = " . $pdo->quote($jid) . " AND direction = 'outbound'")->fetchColumn() === 2);
        ok('o card foi para a etapa escolhida', (int) $pdo->query("SELECT coluna_id FROM cards WHERE id = $cardId")->fetchColumn() === $colFu);

        $f = Fatos::porCards([$CONTA], [$cardId])[$cardId];
        $s = R::situacao(Cadencia::buscar($cadId, $CONTA), $f, time());
        ok('agora o próximo é o passo 2, 18 h depois', $s['passo'] === 2 && $s['previsto'] >= time() + 1080 * 60 - 120);
        $mandados = [];
        $res = Envio::processar($falso, time() + 19 * 3600);
        ok('o número espera de 30 a 40 min entre envios... e o passo 2 só sai na hora dele', count($mandados) === 1 && ($mandados[0][1] ?? '') === 'passo 2');
        $mandados = [];
        Envio::processar($falso, time() + 19 * 3600 + 60);
        ok('logo em seguida não manda de novo', $mandados === []);

        // Passo 3 vencido, mas fora do horário de envio: espera a próxima janela.
        $quando3 = time() + 64 * 3600;
        $dia3 = (int) (new \DateTimeImmutable('@' . $quando3))->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('N');
        $horario = fn(array $dias) => $pdo->prepare('UPDATE followup_cadencias SET horario = ? WHERE id = ?')
            ->execute([json_encode(['dias' => $dias, 'de' => '00:00', 'ate' => '23:59']), $cadId]);
        $horario(array_values(array_diff([1, 2, 3, 4, 5, 6, 7], [$dia3])));
        $mandados = [];
        Envio::processar($falso, $quando3);
        ok('fora do horário da cadência a fila não sai (nem a que já venceu)', $mandados === []);
        $horario([1, 2, 3, 4, 5, 6, 7]);
        Envio::processar($falso, $quando3);
        ok('dentro do horário o passo 3 sai', count($mandados) === 1 && ($mandados[0][1] ?? '') === 'passo 3');

        // A resposta chega pelo @lid, numa conversa separada.
        $pdo->prepare('INSERT INTO whatsapp_identidades (account_id, instance_id, phone, jid, lid, nome_peso) VALUES (?,?,?,?,?,0)')->execute([$CONTA, $INST, $fone, $jid, $lid]);
        $msg->execute([$CONTA, $INST, 'ZZFU' . bin2hex(random_bytes(6)), $lid, $fone, 'text', 'Oi, quem é?', 'inbound', 'received', date('Y-m-d H:i:s', time() + 19 * 3600 + 120)]);
        $f = Fatos::porCards([$CONTA], [$cardId])[$cardId];
        ok('a resposta pelo @lid conta como resposta do lead', $f['tem_entrada'] === true);
        ok('quem respondeu sai da cadência "não respondeu"', R::escolher([Cadencia::buscar($cadId, $CONTA)], $f, time() + 30 * 3600) === [null, null]);

        AutomacaoSdr::definir($CONTA, 'followup', false);
        ok('com a chave geral desligada o worker não olha a conta', Envio::processar($falso, time() + 99 * 3600)['contas'] === 0);
        Cadencia::ligar($cadId, $CONTA, false, 0);
        ok('a cadência desligada sai do lugar do n8n', !Cadencia::cobreSemResposta($CONTA));
        ok('excluir apaga só a cadência da conta', !Cadencia::excluir($cadId, $CONTA + 1, 0) && Cadencia::excluir($cadId, $CONTA, 0));
    } catch (\Throwable $e) {
        $FALHAS[] = $e->getMessage(); echo '  [FALHA] ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
    } finally {
        $pdo->prepare('UPDATE whatsapp_instances SET status = ? WHERE id = ?')->execute([$statusAntes, $INST]);
        if ($ligadasAntes) $pdo->exec('UPDATE followup_cadencias SET ativo = 1 WHERE id IN (' . implode(',', $ligadasAntes) . ')');
        if ($chaveAntes === false) $pdo->exec("DELETE FROM whatsapp_settings WHERE account_id = $CONTA AND config_key = 'sdr_followup_ligado'");
        else $pdo->prepare("UPDATE whatsapp_settings SET config_value = ? WHERE account_id = ? AND config_key = 'sdr_followup_ligado'")->execute([$chaveAntes, $CONTA]);
        $pdo->prepare('DELETE FROM whatsapp_messages WHERE instance_id = ? AND remote_jid IN (?, ?)')->execute([$INST, $jid, $lid]);
        $pdo->prepare('DELETE FROM whatsapp_identidades WHERE instance_id = ? AND jid = ?')->execute([$INST, $jid]);
        $pdo->prepare('DELETE FROM whatsapp_chats WHERE instance_id = ? AND remote_jid IN (?, ?)')->execute([$INST, $jid, $lid]);
        if ($cadId) { $pdo->exec("DELETE FROM followup_envios WHERE cadencia_id = $cadId"); $pdo->exec("DELETE FROM followup_cadencias WHERE id = $cadId"); }
        if ($cardId) {
            $pdo->exec("DELETE FROM crm_interacoes WHERE entidade = 'card' AND entidade_id = $cardId");
            $pdo->exec("DELETE FROM cards WHERE id = $cardId");
        }
    }
}
echo "\n" . ($FALHAS ? count($FALHAS) . ' FALHA(S): ' . implode(' | ', $FALHAS) : "$OK ok/0") . "\n";
exit($FALHAS ? 1 : 0);
