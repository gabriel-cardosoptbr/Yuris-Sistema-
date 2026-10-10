<?php
/**
 * agenda_lead_test.php: a próxima interação com o lead (App\Prospeccao\AgendaDoLead).
 *
 *   - agendar cria a TAREFA no quadro, com o lead vinculado e a hora exata;
 *   - hora passada, tipo inválido e mensagem sem WhatsApp são recusados;
 *   - o lembrete sai uma vez, na janela certa, e de novo se a tarefa for remarcada;
 *   - a mensagem programada sai na hora (enviador falso), conclui a tarefa e não
 *     sai duas vezes; falha avisa quem agendou e só volta por "reenviar";
 *   - tarefa desmarcada cancela a mensagem;
 *   - "a agenda de hoje" traz o que é da pessoa e não o dos outros;
 *   - outra conta não enxerga o lead nem o agendamento.
 *
 * Cria linhas marcadas com ZZTESTE_AGENDA_<pid> e apaga tudo no fim.
 * Nada sai para o WhatsApp: o envio usa um enviador falso.
 * Uso: php scripts/tests/agenda_lead_test.php
 */
if (PHP_SAPI !== 'cli') exit;

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Prospeccao\AgendaDoLead;
use App\Tarefas\TaskEntrega;

$OK = 0;
$FALHAS = [];
function ok(string $msg, bool $cond): void
{
    global $OK, $FALHAS;
    if ($cond) { $OK++; echo "  [ok]   $msg\n"; }
    else { $FALHAS[] = $msg; echo "  [FALHA] $msg\n"; }
}
function recusa(callable $f): ?string
{
    try { $f(); return null; } catch (\InvalidArgumentException $e) { return $e->getMessage(); }
}

echo "\n== 1. Normalização ==\n";
ok('datetime-local vira hora cheia', AgendaDoLead::momento('2026-10-12T14:30') === '2026-10-12 14:30:00');
ok('data com espaço também', AgendaDoLead::momento('2026-10-12 09:05') === '2026-10-12 09:05:00');
ok('data inválida é recusada', AgendaDoLead::momento('2026-02-30 10:00') === null && AgendaDoLead::momento('amanhã') === null);
ok('celular com DDD ganha o 55', AgendaDoLead::telefoneInternacional('(11) 99999-8888') === '5511999998888');
ok('número já com 55 fica igual', AgendaDoLead::telefoneInternacional('+55 11 3333-4444') === '551133334444');
ok('lixo não vira telefone', AgendaDoLead::telefoneInternacional('123') === null);
ok('quadro padrão é o compartilhado', AgendaDoLead::quadroPadrao([['id' => 1, 'tipo' => 'pessoal'], ['id' => 2, 'tipo' => 'compartilhado']]) === 2);
ok('sem compartilhado, o primeiro', AgendaDoLead::quadroPadrao([['id' => 5, 'tipo' => 'pessoal']]) === 5);
ok('sem quadro nenhum, null', AgendaDoLead::quadroPadrao([]) === null);

$pdo = null;
try {
    $pdo = Database::getConnection();
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $temTabela = (bool) $pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_agendamentos'")->fetchColumn();
    // Conta com 2 usuários ativos, funil, quadro e número de WhatsApp próprio.
    $conta = $pdo->query(
        "SELECT a.id FROM accounts a
          WHERE a.deleted_at IS NULL
            AND (SELECT COUNT(*) FROM users u WHERE u.account_id = a.id AND u.status = 'active' AND u.deleted_at IS NULL) >= 2
            AND EXISTS (SELECT 1 FROM pipeline_columns c WHERE c.account_id = a.id)
            AND EXISTS (SELECT 1 FROM whatsapp_channel_accounts w WHERE w.account_id = a.id AND w.access_type = 'owner' AND w.revoked_at IS NULL)
          ORDER BY a.id LIMIT 1"
    )->fetchColumn();
    $outra = $pdo->query('SELECT id FROM accounts WHERE deleted_at IS NULL AND id <> ' . (int) $conta . ' ORDER BY id LIMIT 1')->fetchColumn();
} catch (\Throwable $e) {
    echo '  [pulado] sem banco: ' . $e->getMessage() . "\n";
    $temTabela = false; $conta = null;
}

if (!$pdo || !$temTabela || !$conta) {
    echo "\n  [pulado] precisa do banco, da migration 141 e de uma conta com 2 usuários, funil e WhatsApp\n";
} else {
    $acc   = (int) $conta;
    $users = $pdo->query("SELECT id FROM users WHERE account_id = $acc AND status = 'active' AND deleted_at IS NULL ORDER BY id LIMIT 2")->fetchAll(\PDO::FETCH_COLUMN);
    [$eu, $colega] = array_map('intval', $users);
    $marca = 'ZZTESTE_AGENDA_' . getmypid();
    $fone  = '55119' . str_pad((string) (getmypid() % 100000000), 8, '0', STR_PAD_LEFT);
    $jid   = $fone . '@s.whatsapp.net';
    $col   = (int) $pdo->query("SELECT id FROM pipeline_columns WHERE account_id = $acc ORDER BY ordem, id LIMIT 1")->fetchColumn();

    $novoCard = function (string $sufixo, ?string $tel) use ($pdo, $acc, $col, $marca, $eu): array {
        $pdo->prepare('INSERT INTO cards (account_id, coluna_id, titulo, cliente_nome, telefone_whatsapp, responsavel_user_id, created_at)
                       VALUES (?,?,?,?,?,?, NOW())')
            ->execute([$acc, $col, $marca . $sufixo, $marca . $sufixo, $tel, $eu]);
        return AgendaDoLead::cardDaConta((int) $pdo->lastInsertId(), [$acc]);
    };

    $amanha = (new \DateTimeImmutable(TaskEntrega::agoraLocal()))->modify('+1 day')->format('Y-m-d');
    $tasks = [];
    try {
        echo "\n== 2. Agendar ==\n";
        $card = $novoCard('_com_zap', $fone);
        $semZap = $novoCard('_sem_zap', null);
        ok('o lead da conta é encontrado', $card !== null && (int) $card['id'] > 0);
        ok('outra conta não enxerga o lead', $outra === false || AgendaDoLead::cardDaConta((int) $card['id'], [(int) $outra]) === null);

        ok('tipo inválido é recusado', recusa(fn() => AgendaDoLead::criar($card, ['tipo' => 'visita', 'quando' => "$amanha 10:00"], $eu, true)) !== null);
        ok('hora passada é recusada', str_contains((string) recusa(fn() => AgendaDoLead::criar($card, ['tipo' => 'ligacao', 'quando' => '2020-01-01 10:00'], $eu, true)), 'já passou'));
        ok('sem data é recusado', recusa(fn() => AgendaDoLead::criar($card, ['tipo' => 'ligacao', 'quando' => ''], $eu, true)) !== null);
        ok('mensagem para lead sem WhatsApp é recusada',
            str_contains((string) recusa(fn() => AgendaDoLead::criar($semZap, ['tipo' => 'mensagem', 'quando' => "$amanha 10:00", 'mensagem' => 'Oi'], $eu, true)), 'WhatsApp'));
        ok('responsável de fora da conta é recusado', recusa(fn() => AgendaDoLead::criar($card, ['tipo' => 'ligacao', 'quando' => "$amanha 10:00", 'responsavel_id' => 99999999], $eu, true)) !== null);

        $r = AgendaDoLead::criar($card, ['tipo' => 'ligacao', 'quando' => "{$amanha}T14:30", 'lembrete_min' => 15, 'observacao' => 'Pediu retorno em 2 dias'], $eu, true);
        $tasks[] = $r['task_id'];
        $t = $pdo->query('SELECT t.*, b.account_id FROM tasks t JOIN task_boards b ON b.id = t.board_id WHERE t.id = ' . (int) $r['task_id'])->fetch(\PDO::FETCH_ASSOC);
        ok('a tarefa nasce no quadro da conta', $t && (int) $t['account_id'] === $acc);
        ok('com a hora exata', $t && $t['prazo'] === "$amanha 14:30:00");
        ok('com título a partir do lead', $t && $t['titulo'] === 'Ligar para ' . $marca . '_com_zap');
        ok('responsável é quem agendou', $t && (int) $t['responsavel_id'] === $eu);
        ok('a anotação vai na descrição', $t && str_contains((string) $t['descricao'], 'Pediu retorno em 2 dias'));
        $link = $pdo->query("SELECT COUNT(*) FROM task_links WHERE task_id = {$r['task_id']} AND link_type = 'card' AND link_id = {$card['id']}")->fetchColumn();
        ok('o lead fica vinculado à tarefa', (int) $link === 1);
        $nota = $pdo->query("SELECT COUNT(*) FROM crm_interacoes WHERE entidade = 'card' AND entidade_id = {$card['id']} AND assunto = 'Próxima interação agendada'")->fetchColumn();
        ok('o histórico do lead registra o agendamento', (int) $nota === 1);

        $lista = AgendaDoLead::doCard((int) $card['id'], $acc);
        ok('a ficha lista o agendamento', count($lista) === 1 && $lista[0]['tipo'] === 'ligacao' && $lista[0]['envio_status'] === 'nao_se_aplica');
        ok('outra conta não acha o agendamento', $outra === false || AgendaDoLead::buscar($r['id'], (int) $outra) === null);

        echo "\n== 3. Lembrete ==\n";
        $prazo = "$amanha 14:30:00";
        $menos = fn(int $min) => (new \DateTimeImmutable($prazo))->modify("-{$min} minutes")->format('Y-m-d H:i:s');
        $avisos = fn() => (int) $pdo->query("SELECT COUNT(*) FROM account_notifications WHERE account_id = $acc AND user_id = $eu AND chave_dedupe LIKE 'agenda:{$r['id']}:%'")->fetchColumn();
        AgendaDoLead::processarLembretes($menos(20));
        ok('20 minutos antes ainda não avisa', $avisos() === 0);
        AgendaDoLead::processarLembretes($menos(14));
        ok('dentro dos 15 minutos avisa', $avisos() === 1);
        AgendaDoLead::processarLembretes($menos(5));
        ok('não avisa duas vezes', $avisos() === 1);
        $pdo->prepare('UPDATE tasks SET prazo = ? WHERE id = ?')->execute(["$amanha 16:00:00", $r['task_id']]);
        AgendaDoLead::processarLembretes("$amanha 15:50:00");
        ok('remarcada na agenda, avisa de novo para a nova hora', $avisos() === 2);

        echo "\n== 4. Mensagem programada ==\n";
        $m = AgendaDoLead::criar($card, ['tipo' => 'mensagem', 'quando' => "$amanha 10:00", 'mensagem' => "Olá, $marca", 'lembrete_min' => -1], $eu, true);
        $tasks[] = $m['task_id'];
        $linha = fn() => $pdo->query('SELECT * FROM crm_agendamentos WHERE id = ' . (int) $m['id'])->fetch(\PDO::FETCH_ASSOC);
        ok('fica pendente, com destino resolvido no servidor', $linha()['envio_status'] === 'pendente' && $linha()['remote_jid'] === $jid);

        $enviadas = [];
        $falso = function (array $cfg, string $inst, string $para, string $txt) use (&$enviadas, $marca) {
            $enviadas[] = [$para, $txt];
            return ['key' => ['id' => $marca . '_WAMID_' . count($enviadas)]];
        };
        AgendaDoLead::processarMensagens($falso, "$amanha 09:59:00");
        ok('antes da hora não sai', $enviadas === [] && $linha()['envio_status'] === 'pendente');
        $res = AgendaDoLead::processarMensagens($falso, "$amanha 10:00:00");
        ok('na hora sai para o número do lead', count($enviadas) === 1 && $enviadas[0][0] === $jid && $enviadas[0][1] === "Olá, $marca");
        ok('fica enviada com o id da mensagem', $linha()['envio_status'] === 'enviada' && str_starts_with((string) $linha()['wamid'], $marca));
        $st = $pdo->query('SELECT status FROM tasks WHERE id = ' . (int) $m['task_id'])->fetchColumn();
        ok('e a tarefa é concluída', $st === 'concluida');
        AgendaDoLead::processarMensagens($falso, "$amanha 10:05:00");
        ok('não sai duas vezes', count($enviadas) === 1 && $res['enviadas'] === 1);
        $gravada = $pdo->prepare('SELECT COUNT(*) FROM whatsapp_messages WHERE remote_jid = ? AND direction = "outbound"');
        $gravada->execute([$jid]);
        ok('a mensagem aparece na conversa', (int) $gravada->fetchColumn() === 1);
        ok('não avisou por lembrete (pediu para não avisar)', (int) $pdo->query("SELECT COUNT(*) FROM account_notifications WHERE chave_dedupe LIKE 'agenda:{$m['id']}:%'")->fetchColumn() === 0);

        echo "\n== 5. Falha, reenviar e cancelar ==\n";
        $f = AgendaDoLead::criar($card, ['tipo' => 'mensagem', 'quando' => "$amanha 11:00", 'mensagem' => 'Vai falhar', 'responsavel_id' => $colega], $eu, true);
        $tasks[] = $f['task_id'];
        $quebrado = fn() => ['_http' => 500, 'message' => 'erro'];
        AgendaDoLead::processarMensagens($quebrado, "$amanha 11:00:00");
        $lf = $pdo->query('SELECT * FROM crm_agendamentos WHERE id = ' . (int) $f['id'])->fetch(\PDO::FETCH_ASSOC);
        ok('falha fica marcada, com o motivo', $lf['envio_status'] === 'falhou' && $lf['envio_erro'] !== null);
        ok('quem agendou é avisado', (int) $pdo->query("SELECT COUNT(*) FROM account_notifications WHERE user_id = $eu AND chave_dedupe = 'agenda-falha:{$f['id']}'")->fetchColumn() === 1);
        $tentativas = (int) $lf['envio_tentativas'];
        AgendaDoLead::processarMensagens($falso, "$amanha 11:02:00");
        ok('falha não é repetida sozinha', count($enviadas) === 1);
        ok('reenviar volta para a fila', AgendaDoLead::reenviar((int) $f['id'], $acc) === true);
        AgendaDoLead::processarMensagens($falso, "$amanha 11:03:00");
        ok('e sai no minuto seguinte', count($enviadas) === 2);
        ok('reenviar o que já saiu não é aceito', AgendaDoLead::reenviar((int) $f['id'], $acc) === false);

        $c = AgendaDoLead::criar($card, ['tipo' => 'mensagem', 'quando' => "$amanha 12:00", 'mensagem' => 'Desmarcada'], $eu, true);
        $tasks[] = $c['task_id'];
        ok('desmarcar funciona', AgendaDoLead::cancelar((int) $c['id'], $acc, $eu) === true);
        AgendaDoLead::processarMensagens($falso, "$amanha 12:00:00");
        ok('desmarcada não sai', count($enviadas) === 2);
        $lc = $pdo->query('SELECT a.envio_status, t.status FROM crm_agendamentos a JOIN tasks t ON t.id = a.task_id WHERE a.id = ' . (int) $c['id'])->fetch(\PDO::FETCH_ASSOC);
        ok('mensagem cancelada e tarefa fora da agenda', $lc['envio_status'] === 'cancelada' && $lc['status'] === 'arquivada');

        $z = AgendaDoLead::criar($card, ['tipo' => 'mensagem', 'quando' => "$amanha 13:00", 'mensagem' => 'Concluída antes'], $eu, true);
        $tasks[] = $z['task_id'];
        \App\Tarefas\Task::complete((int) $z['task_id'], $eu);
        AgendaDoLead::processarMensagens($falso, "$amanha 13:00:00");
        ok('tarefa concluída na agenda antes da hora cancela a mensagem',
            count($enviadas) === 2 && $pdo->query('SELECT envio_status FROM crm_agendamentos WHERE id = ' . (int) $z['id'])->fetchColumn() === 'cancelada');

        echo "\n== 5b. O card do funil ==\n";
        $p1 = AgendaDoLead::proximas([(int) $card['id'], (int) $semZap['id']], "$amanha 07:00:00");
        ok('o card mostra a ligação das 16:00 (a única ainda em aberto)', ($p1[(int) $card['id']]['prazo'] ?? '') === "$amanha 16:00:00" && $p1[(int) $card['id']]['tipo_rotulo'] === 'Ligação');
        ok('lead sem agendamento não traz nada', !isset($p1[(int) $semZap['id']]));
        $cedo = AgendaDoLead::criar($card, ['tipo' => 'reuniao', 'quando' => "$amanha 09:30"], $eu, true);
        $tasks[] = $cedo['task_id'];
        $p2 = AgendaDoLead::proximas([(int) $card['id']], "$amanha 07:00:00");
        ok('o mais cedo vence', ($p2[(int) $card['id']]['prazo'] ?? '') === "$amanha 09:30:00" && $p2[(int) $card['id']]['tipo'] === 'reuniao');
        $p3 = AgendaDoLead::proximas([(int) $card['id']], "$amanha 10:00:00");
        ok('passada a hora, vem como atrasado', ($p3[(int) $card['id']]['atrasada'] ?? false) === true);
        AgendaDoLead::concluir((int) $cedo['id'], $acc, $eu);
        $p4 = AgendaDoLead::proximas([(int) $card['id']], "$amanha 10:00:00");
        ok('feito, sai do card e volta o seguinte', ($p4[(int) $card['id']]['prazo'] ?? '') === "$amanha 16:00:00" && $p4[(int) $card['id']]['atrasada'] === false);

        echo "\n== 6. A agenda de hoje ==\n";
        $h = AgendaDoLead::criar($card, ['tipo' => 'reuniao', 'quando' => "$amanha 08:00"], $eu, true);
        $tasks[] = $h['task_id'];
        $o = AgendaDoLead::criar($card, ['tipo' => 'ligacao', 'quando' => "$amanha 09:00", 'responsavel_id' => $colega], $eu, true);
        $tasks[] = $o['task_id'];
        $dia = AgendaDoLead::doDia($eu, [$acc], "$amanha 07:30:00");
        $ids = array_column($dia, 'task_id');
        ok('traz a reunião de hoje da pessoa', in_array($h['task_id'], $ids, true));
        ok('não traz a ligação do colega', !in_array($o['task_id'], $ids, true));
        ok('não traz o que já foi feito', !in_array($m['task_id'], $ids, true));
        $item = array_values(array_filter($dia, fn($i) => $i['task_id'] === $h['task_id']))[0] ?? [];
        ok('com hora, tipo e link para o lead', ($item['hora'] ?? '') === '08:00' && ($item['tipo_rotulo'] ?? '') === 'Reunião' && ($item['url'] ?? '') === '/prospeccao.php?open=' . $card['id']);
        $diaDepois = AgendaDoLead::doDia($eu, [$acc], "$amanha 20:00:00");
        $atrasada = array_values(array_filter($diaDepois, fn($i) => $i['task_id'] === $h['task_id']))[0] ?? [];
        ok('passada a hora, aparece como atrasada', ($atrasada['atrasada'] ?? false) === true);
        ok('outra conta não vê a agenda desta', $outra === false || AgendaDoLead::doDia($eu, [(int) $outra], "$amanha 07:30:00") === [] || !in_array($h['task_id'], array_column(AgendaDoLead::doDia($eu, [(int) $outra], "$amanha 07:30:00"), 'task_id'), true));
    } catch (\Throwable $e) {
        ok('sem exceção: ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(), false);
    } finally {
        // Limpeza: tudo que o teste criou.
        $cards = $pdo->query("SELECT id FROM cards WHERE account_id = $acc AND titulo LIKE '{$marca}%'")->fetchAll(\PDO::FETCH_COLUMN);
        $todas = $tasks;
        if ($cards) {
            $in = implode(',', array_map('intval', $cards));
            $todas = array_merge($todas, $pdo->query("SELECT task_id FROM crm_agendamentos WHERE card_id IN ($in)")->fetchAll(\PDO::FETCH_COLUMN));
            $pdo->exec("DELETE FROM crm_agendamentos WHERE card_id IN ($in)");
            $pdo->exec("DELETE FROM crm_interacoes WHERE entidade = 'card' AND entidade_id IN ($in)");
            $pdo->exec("DELETE FROM card_history WHERE card_id IN ($in)");
            $pdo->exec("DELETE FROM account_notifications WHERE entidade = 'card' AND entidade_id IN ($in)");
            $pdo->exec("UPDATE whatsapp_chats SET linked_card_id = NULL WHERE linked_card_id IN ($in)");
            $pdo->exec("DELETE FROM cards WHERE id IN ($in)");
        }
        $todas = array_values(array_unique(array_map('intval', $todas)));
        if ($todas) {
            $in = implode(',', $todas);
            $pdo->exec("DELETE FROM account_notifications WHERE entidade = 'tarefa' AND entidade_id IN ($in)");
            foreach (['task_links', 'task_history', 'task_reminders'] as $tb) { try { $pdo->exec("DELETE FROM $tb WHERE task_id IN ($in)"); } catch (\Throwable $_) {} }
            $pdo->exec("DELETE FROM tasks WHERE id IN ($in)");
        }
        $pdo->prepare('DELETE FROM whatsapp_messages WHERE remote_jid = ?')->execute([$jid]);
        $pdo->prepare('DELETE FROM whatsapp_chats WHERE remote_jid = ?')->execute([$jid]);
        $resto = (int) $pdo->query("SELECT COUNT(*) FROM cards WHERE titulo LIKE '{$marca}%'")->fetchColumn();
        ok('limpeza: nada do teste ficou no banco', $resto === 0);
    }
}

echo "\nResultado: $OK ok · " . count($FALHAS) . " falha(s)\n";
if ($FALHAS) exit(1);
