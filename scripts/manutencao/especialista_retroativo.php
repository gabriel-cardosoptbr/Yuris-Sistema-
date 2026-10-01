<?php
/**
 * scripts/manutencao/especialista_retroativo.php
 *
 * Move para "Em atendimento pelo especialista" os cards das conversas que uma
 * PESSOA do time já respondeu pelo celular ou pelo WhatsApp Web antes da
 * correção de 01/10/2026 (commit 1922b1c). Até ali a resposta pelo WhatsApp Web
 * não movia o card, porque a Evolution marca o WhatsApp Web com source "web",
 * igual ao envio pela API.
 *
 * COMO SABE QUE FOI GENTE: no payload gravado, a mensagem enviada pela API (robô,
 * Vitória, Chat do CRM) nasce com status "PENDING" (é o que o Baileys põe no
 * envio próprio), e a digitada em outro aparelho chega sem esse status. A primeira
 * parte da saída mostra a contagem por status e origem, para conferir o critério
 * antes de aplicar.
 *
 * Só mexe em card de conta da edição CRM, ligado a conversa individual, que esteja
 * numa etapa da automação (novos leads, em qualificação, follow-up, qualificado).
 * Card que alguém já levou para outra etapa não é tocado (mesma regra do
 * SdrFleetiflow::moverEtapa). A IA NÃO é pausada aqui: isso passa a acontecer na
 * próxima mensagem da pessoa naquela conversa.
 *
 * DRY-RUN POR PADRÃO. Uso:
 *   php scripts/manutencao/especialista_retroativo.php            (simula)
 *   php scripts/manutencao/especialista_retroativo.php --dias=10  (janela maior)
 *   php scripts/manutencao/especialista_retroativo.php --aplicar  (move de verdade)
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Só via CLI.\n"); }

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Master\Account;
use App\WhatsAppAgente\SdrFleetiflow;

$opts    = getopt('', ['aplicar', 'dias:']);
$aplicar = isset($opts['aplicar']);
$dias    = max(1, min(60, (int)($opts['dias'] ?? 7)));

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo "== Cards de conversas já atendidas por pessoa -> Em atendimento pelo especialista ==\n";
echo $aplicar ? "   MODO APLICAÇÃO\n" : "   MODO SIMULAÇÃO (nada é gravado; use --aplicar)\n";
echo "   janela: últimos {$dias} dias\n\n";

$contas = [];
foreach ($pdo->query('SELECT DISTINCT account_id FROM whatsapp_instances')->fetchAll(\PDO::FETCH_COLUMN) as $a) {
    if (SdrFleetiflow::contaUsa((int)$a)) $contas[] = (int)$a;
}
if (!$contas) { echo "Nenhuma conta da edição CRM com canal.\n"; exit(0); }

$total = 0; $movidos = 0;
foreach ($contas as $acc) {
    $conta = Account::findById($acc);
    echo "-- conta #{$acc} (" . ($conta['nome'] ?? '?') . ")\n";

    // 1. Evidência do critério: mensagens próprias por status e origem (só contagem).
    $st = $pdo->prepare(
        "SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(m.raw_payload, '$.status')), '(vazio)') AS st,
                COALESCE(JSON_UNQUOTE(JSON_EXTRACT(m.raw_payload, '$.source')), '(vazio)') AS src,
                COUNT(*) AS n
           FROM whatsapp_messages m
           JOIN whatsapp_instances wi ON wi.id = m.instance_id AND wi.account_id = ?
          WHERE m.direction = 'outbound' AND m.created_at >= NOW() - INTERVAL ? DAY
            AND m.raw_payload IS NOT NULL AND m.raw_payload LIKE '{%'
       GROUP BY 1, 2 ORDER BY n DESC"
    );
    $st->execute([$acc, $dias]);
    echo "   mensagens próprias com payload, por status/origem:\n";
    foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        printf("     %-12s %-10s %6d\n", $r['st'], $r['src'], $r['n']);
    }

    // 2. Candidatos: card vivo, ligado a conversa 1:1, numa etapa da automação, com
    //    ao menos uma mensagem própria SEM status PENDING (digitada por pessoa).
    $colunas = SdrFleetiflow::colunasDaConta($pdo, $acc);
    $origem  = array_values(array_filter(array_map(
        fn($e) => $colunas[$e] ?? null, ['novo', 'qualificacao', 'followup', 'qualificado']
    )));
    if (!isset($colunas['especialista']) || !$origem) {
        echo "   funil sem as etapas esperadas: pulado\n\n";
        continue;
    }
    $nomeEtapa = array_flip($colunas);
    $in = implode(',', array_fill(0, count($origem), '?'));
    $st = $pdo->prepare(
        "SELECT c.id, c.cliente_nome, c.coluna_id, wc.instance_id, wc.remote_jid,
                COUNT(m.id) AS humanas, MAX(m.created_at) AS ultima
           FROM whatsapp_chats wc
           JOIN whatsapp_instances wi ON wi.id = wc.instance_id AND wi.account_id = ?
           JOIN cards c ON c.id = wc.linked_card_id AND c.deleted_at IS NULL AND c.account_id = ?
           JOIN whatsapp_messages m ON m.instance_id = wc.instance_id AND m.remote_jid = wc.remote_jid
          WHERE COALESCE(wc.is_group, 0) = 0
            AND c.coluna_id IN ($in)
            AND m.direction = 'outbound'
            AND m.created_at >= NOW() - INTERVAL ? DAY
            AND m.raw_payload IS NOT NULL AND m.raw_payload LIKE '{%'
            AND JSON_EXTRACT(m.raw_payload, '$.key.fromMe') = true
            AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(m.raw_payload, '$.status')), '') <> 'PENDING'
       GROUP BY c.id, c.cliente_nome, c.coluna_id, wc.instance_id, wc.remote_jid
       ORDER BY ultima DESC"
    );
    $st->execute(array_merge([$acc, $acc], $origem, [$dias]));
    $cands = $st->fetchAll(\PDO::FETCH_ASSOC);
    echo "   cards a mover: " . count($cands) . "\n";
    foreach ($cands as $c) {
        $total++;
        $ok = false;
        if ($aplicar) {
            $ok = SdrFleetiflow::moverEtapa($acc, (int)$c['id'], 'especialista');
            if ($ok) {
                $movidos++;
                \App\Prospeccao\Card::logEvento((int)$c['id'], null, 'atendimento_especialista', 'coluna_id', null,
                    'Movido para Em atendimento: a especialista já tinha respondido pelo WhatsApp (correção retroativa)');
            }
        }
        printf("     card #%-6d %-32s %-12s -> especialista  (%d msg de pessoa, última %s)%s\n",
            $c['id'], mb_substr((string)$c['cliente_nome'], 0, 32), $nomeEtapa[(int)$c['coluna_id']] ?? '?',
            $c['humanas'], $c['ultima'], $aplicar ? ($ok ? '  [movido]' : '  [não movido]') : '');
    }
    echo "\n";
}

echo $aplicar ? "Movidos: {$movidos} de {$total}.\n" : "Simulação: {$total} card(s) seriam movidos. Nada foi gravado.\n";
