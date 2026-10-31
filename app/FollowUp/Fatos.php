<?php
namespace App\FollowUp;

use App\Core\Database;
use App\WhatsAppAgente\AutorDaMensagem;
use App\WhatsAppAgente\SdrFleetiflow;
use App\WhatsAppAgente\VendedorDoLead;

/**
 * Fatos: o que Regra precisa saber de cada card, lido do WhatsApp em poucas
 * consultas para a lista inteira (o funil da Fleet tem centenas de cards).
 *
 * A conversa de um card é a soma das conversas ligadas a ele (linked_card_id)
 * e das @lid do mesmo contato (whatsapp_identidades): a resposta do lead muitas
 * vezes chega num chat @lid separado do chat do número para onde a abertura
 * foi. Sem somar, o sistema acharia que ele nunca respondeu e mandaria "só para
 * não deixar minha mensagem perdida" a quem já está conversando.
 *
 * Datas do banco estão em UTC (o PHP roda em UTC); aqui viram timestamp.
 */
final class Fatos
{
    /** O robozinho do card só aparece com mensagem na conversa nos últimos N dias. */
    public const ROBO_DIAS = 3;
    /** O card calcula a fila do número quando o follow-up dele sai em até N horas. */
    public const FILA_HORAS = 12;

    /**
     * @param list<int> $accountIds contas que quem pede pode ver (os cards e os números têm de ser delas)
     * @param list<int> $cardIds
     * @return array<int, array> por card: etapa, nome, empresa, account_id, chat (por onde mandar) ou null,
     *         grupo, com_robo, robo_nome, tem_entrada, ultima_entrada, saidas, ultima_saida
     */
    public static function porCards(array $accountIds, array $cardIds): array
    {
        $accountIds = array_values(array_filter(array_map('intval', $accountIds)));
        $cardIds = array_values(array_unique(array_filter(array_map('intval', $cardIds))));
        if (!$accountIds || !$cardIds) return [];
        $pdo = Database::getConnection();
        $in = fn(array $l) => implode(',', array_fill(0, count($l), '?'));

        $st = $pdo->prepare('SELECT id, account_id, coluna_id, cliente_nome, empresa_nome FROM cards
                              WHERE id IN (' . $in($cardIds) . ') AND account_id IN (' . $in($accountIds) . ') AND deleted_at IS NULL');
        $st->execute(array_merge($cardIds, $accountIds));
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $c) {
            $out[(int) $c['id']] = [
                'card_id' => (int) $c['id'], 'account_id' => (int) $c['account_id'], 'etapa' => (int) $c['coluna_id'],
                'nome' => (string) $c['cliente_nome'], 'empresa' => (string) $c['empresa_nome'],
                'chat' => null, 'grupo' => false, 'com_robo' => false, 'robo_nome' => null,
                'tem_entrada' => false, 'ultima_entrada' => null, 'saidas' => [], 'ultima_saida' => null,
            ];
        }
        if (!$out) return [];

        // O vendedor de cada card (31/10/2026): a IA fala em nome dele, e o chip do card
        // diz "Kaue (IA) atendendo". Por conta, porque o card pode ser de uma filial.
        $vendedorDe = [];
        $porConta = [];
        foreach ($out as $idCard => $linha) $porConta[$linha['account_id']][] = $idCard;
        foreach ($porConta as $acc => $idsConta) $vendedorDe += VendedorDoLead::doscards((int) $acc, $idsConta);

        // Conversas ligadas, só em número de conta que quem pede enxerga.
        $ids = array_keys($out);
        $st = $pdo->prepare('SELECT wc.instance_id, wc.remote_jid, wc.linked_card_id, wc.agent_paused, wc.is_group, wc.contact_name,
                                    wi.status AS inst_status, wi.account_id AS dono
                               FROM whatsapp_chats wc
                               JOIN whatsapp_instances wi ON wi.id = wc.instance_id
                              WHERE wc.linked_card_id IN (' . $in($ids) . ') AND wi.account_id IN (' . $in($accountIds) . ')
                                AND wc.remote_jid <> ""');
        $st->execute(array_merge($ids, $accountIds));
        $chats = $st->fetchAll(\PDO::FETCH_ASSOC);
        if (!$chats) return $out;

        $instancias = array_values(array_unique(array_map(fn($c) => (int) $c['instance_id'], $chats)));
        $agenteLigado = [];
        try {
            $st = $pdo->prepare('SELECT whatsapp_instance_id FROM agent_configs WHERE enabled = 1 AND whatsapp_instance_id IN (' . $in($instancias) . ')');
            $st->execute($instancias);
            foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $i) $agenteLigado[(int) $i] = true;
        } catch (\Throwable $e) { /* sem tabela do agente: ninguém está com o robô */ }

        // Pares (número, jid) de cada card, com os apelidos @lid do mesmo contato.
        $pares = [];      // "inst|jid" => card
        $jids = [];
        foreach ($chats as $c) {
            $pares[$c['instance_id'] . '|' . $c['remote_jid']] = (int) $c['linked_card_id'];
            $jids[] = (string) $c['remote_jid'];
        }
        try {
            $st = $pdo->prepare('SELECT instance_id, jid, lid FROM whatsapp_identidades
                                  WHERE instance_id IN (' . $in($instancias) . ') AND jid IS NOT NULL AND lid IS NOT NULL
                                    AND (jid IN (' . $in($jids) . ') OR lid IN (' . $in($jids) . '))');
            $st->execute(array_merge($instancias, $jids, $jids));
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $kj = $r['instance_id'] . '|' . $r['jid'];
                $kl = $r['instance_id'] . '|' . $r['lid'];
                if (isset($pares[$kj]) && !isset($pares[$kl])) $pares[$kl] = $pares[$kj];
                if (isset($pares[$kl]) && !isset($pares[$kj])) $pares[$kj] = $pares[$kl];
            }
        } catch (\Throwable $e) { /* sem identidades: vale o chat ligado */ }
        $todosJids = array_values(array_unique(array_map(fn($k) => explode('|', $k, 2)[1], array_keys($pares))));

        // A última mensagem do lead em cada par (sem limite de data: quem respondeu
        // há meses continua sendo quem respondeu).
        $st = $pdo->prepare('SELECT instance_id, remote_jid, MAX(created_at) AS ult FROM whatsapp_messages
                              WHERE direction = "inbound" AND instance_id IN (' . $in($instancias) . ') AND remote_jid IN (' . $in($todosJids) . ')
                              GROUP BY instance_id, remote_jid');
        $st->execute(array_merge($instancias, $todosJids));
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $card = $pares[$r['instance_id'] . '|' . $r['remote_jid']] ?? null;
            if ($card === null || !isset($out[$card])) continue;
            $ts = (int) strtotime((string) $r['ult']);
            $out[$card]['tem_entrada'] = true;
            if ($ts > (int) $out[$card]['ultima_entrada']) $out[$card]['ultima_entrada'] = $ts;
        }

        // As nossas depois da última do lead naquele par; a soma por card filtra de novo.
        $st = $pdo->prepare('SELECT m.instance_id, m.remote_jid, m.created_at FROM whatsapp_messages m
                              WHERE m.direction = "outbound" AND m.instance_id IN (' . $in($instancias) . ') AND m.remote_jid IN (' . $in($todosJids) . ')
                                AND m.created_at > COALESCE((SELECT MAX(i.created_at) FROM whatsapp_messages i
                                                              WHERE i.instance_id = m.instance_id AND i.remote_jid = m.remote_jid
                                                                AND i.direction = "inbound"), "1970-01-01")');
        $st->execute(array_merge($instancias, $todosJids));
        $ultimaSaidaDoPar = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $k = $r['instance_id'] . '|' . $r['remote_jid'];
            $card = $pares[$k] ?? null;
            if ($card === null || !isset($out[$card])) continue;
            $ts = (int) strtotime((string) $r['created_at']);
            $out[$card]['saidas'][] = $ts;
            $ultimaSaidaDoPar[$k] = max($ultimaSaidaDoPar[$k] ?? 0, $ts);
        }

        $agenteDaConta = [];
        foreach ($out as $cardId => &$f) {
            $f['saidas'] = array_values(array_filter($f['saidas'], fn($t) => $f['ultima_entrada'] === null || $t > $f['ultima_entrada']));
            sort($f['saidas']);
            $f['ultima_saida'] = $f['saidas'] ? end($f['saidas']) : null;
        }
        unset($f);

        // Por onde mandar: o chat do número (não o @lid) com a nossa mensagem mais
        // recente; sem mensagem nossa, o chat ligado mais recente.
        foreach ($chats as $c) {
            $card = (int) $c['linked_card_id'];
            if (!isset($out[$card])) continue;
            $k = $c['instance_id'] . '|' . $c['remote_jid'];
            $dono = (int) $c['dono'];
            if (!isset($agenteDaConta[$dono])) {
                $agenteDaConta[$dono] = SdrFleetiflow::contaUsa($dono) && SdrFleetiflow::urlDaConta($dono) !== ''
                    ? AutorDaMensagem::nomeCurto(SdrFleetiflow::nomeAgenteDaConta($dono)) : '';
            }
            $cand = [
                'instance_id' => (int) $c['instance_id'], 'remote_jid' => (string) $c['remote_jid'], 'dono' => $dono,
                'aberto' => strtolower((string) $c['inst_status']) === 'open', 'contato' => (string) $c['contact_name'],
                'peso' => ($ultimaSaidaDoPar[$k] ?? 0) * 2 + (str_ends_with((string) $c['remote_jid'], '@s.whatsapp.net') ? 1 : 0),
                'grupo' => (int) $c['is_group'] === 1 || str_ends_with((string) $c['remote_jid'], '@g.us'),
                'robo' => $agenteDaConta[$dono] !== '' && !empty($agenteLigado[(int) $c['instance_id']]) && (int) $c['agent_paused'] === 0,
            ];
            if ($out[$card]['chat'] === null || $cand['peso'] > $out[$card]['chat']['peso']) $out[$card]['chat'] = $cand;
        }
        foreach ($out as &$f) {
            if (!$f['chat']) continue;
            $f['grupo'] = $f['chat']['grupo'];
            $f['com_robo'] = $f['chat']['robo'];
            $f['robo_nome'] = $f['com_robo']
                ? (isset($vendedorDe[$f['card_id']]) ? VendedorDoLead::rotuloIA($vendedorDe[$f['card_id']]) : $agenteDaConta[$f['chat']['dono']])
                : null;
            // O nome do contato: o do card, a não ser que ele seja a própria empresa;
            // aí o nome que a pessoa usa no WhatsApp (Regra::texto descarta se também for a empresa).
            if ($f['nome'] === '' || Regra::mesmoNome($f['nome'], $f['empresa'])) $f['nome'] = $f['chat']['contato'];
        }
        unset($f);
        return $out;
    }

    /**
     * O que o card do funil mostra: a cadência, o passo e quando sai o próximo,
     * e se a conversa está com o robô. Só cards com conversa ligada, ou sem
     * conversa numa etapa onde uma cadência ativa começa (aí "Ainda sem mensagem").
     *
     * @param list<array> $cards linhas da lista (id, account_id, coluna_id)
     * @return array<int, array>
     */
    public static function paraOFunil(array $accountIds, array $cards, ?int $agora = null): array
    {
        $agora = $agora ?? time();
        $porConta = [];
        foreach ($cards as $c) $porConta[(int) $c['account_id']][] = (int) $c['id'];
        $res = [];
        foreach ($porConta as $acc => $ids) {
            if (!in_array($acc, array_map('intval', $accountIds), true)) continue;
            $cads = Cadencia::daConta($acc, true);
            $fatos = self::porCards([$acc], $ids);
            $etapasComCadencia = [];
            $todas = false;
            foreach ($cads as $c) { if (!$c['etapas']) $todas = true; $etapasComCadencia = array_merge($etapasComCadencia, $c['etapas']); }
            $escolhas = [];
            foreach ($fatos as $id => $f) {
                if ($f['chat']) $escolhas[$id] = Regra::escolher($cads, $f, $agora);
            }
            // Fila do número (Envio::estimar): só quando algum card desta página sai nas
            // próximas horas, porque pede a fila da conta inteira.
            $est = [];
            $falhaDe = [];
            $agendados = null;
            foreach ($escolhas as [$cad, $sit]) {
                if ($cad && $sit['estado'] === 'agendado') {
                    $agendados ??= Envio::agendados($acc, $cads, $agora);
                    break;
                }
            }
            if ($agendados) {
                foreach ($agendados as $ag) $falhaDe[$ag['card_id']] = $ag['falha'] ?? null;
                foreach ($escolhas as [$cad, $sit]) {
                    if ($cad && $sit['estado'] === 'agendado' && $sit['previsto'] <= $agora + self::FILA_HORAS * 3600) { $est = Envio::estimar($agendados, $agora); break; }
                }
            }
            foreach ($fatos as $id => $f) {
                // Robô só em conversa viva: o lead já escreveu e houve mensagem nos últimos
                // ROBO_DIAS (conversa encerrada há semanas não está "com a Vitória").
                $ultima = max((int) $f['ultima_entrada'], (int) $f['ultima_saida']);
                $vivo = $f['com_robo'] && $f['tem_entrada'] && $agora - $ultima <= self::ROBO_DIAS * 86400;
                $item = ['robo' => $vivo ? ['nome' => $f['robo_nome']] : null, 'followup' => null];
                if (!$f['chat']) {
                    if ($cads && ($todas || in_array($f['etapa'], $etapasComCadencia, true))) $item['followup'] = ['estado' => 'sem_mensagem'];
                } else {
                    [$cad, $sit] = $escolhas[$id];
                    if ($cad) $item['followup'] = self::resumo($cad, $sit, $agora, $est[$id] ?? null, $falhaDe[$id] ?? null);
                }
                if ($item['robo'] || $item['followup']) $res[$id] = $item;
            }
        }
        return $res;
    }

    /**
     * Situação para a tela, com as datas em ISO 8601 (o navegador mostra no fuso de quem vê).
     * `previsto` é quando a cadência põe o lead na fila; com $est (Envio::estimar)
     * vêm a posição na fila do número (`ordem`, `na_frente`) e a faixa em que a
     * mensagem deve sair (`sai_de` a `sai_ate`, porque a espera é de 30 a 40 min).
     * Com $falha (de Envio::agendados), o envio que falhou antes e quando será refeito.
     */
    public static function resumo(array $cad, array $sit, int $agora, ?array $est = null, ?array $falha = null): array
    {
        $iso = fn(?int $t) => $t ? date('c', $t) : null;
        return [
            'cadencia_id' => $cad['id'], 'cadencia' => $cad['nome'], 'gatilho' => $cad['gatilho'],
            'estado' => $sit['estado'], 'passo' => $sit['passo'], 'total' => $sit['total'],
            'previsto' => $iso($sit['previsto']), 'na_fila' => $sit['previsto'] !== null && $sit['previsto'] <= $agora,
            'sai_de' => $est ? $iso($est['de']) : null, 'sai_ate' => $est ? $iso($est['ate']) : null,
            'ordem' => $est ? (int) $est['ordem'] : null, 'na_frente' => $est ? (int) $est['na_frente'] : 0,
            'desde' => $iso($sit['ciclo_desde']), 'ultima_saida' => $iso($sit['ultima_saida']),
            // Envio que falhou antes (31/10/2026): é refeito no dia seguinte, no horário da cadência.
            'falha' => $falha ? ['tentativas' => (int) $falha['tentativas'], 'falhou_em' => $iso((int) ($falha['falhou_em'] ?? 0)), 'teto' => Envio::TENTATIVAS, 'refaz_em' => $iso((int) $falha['refaz_em']), 'esgotada' => (bool) $falha['esgotada'], 'erro' => (string) $falha['erro']] : null,
        ];
    }
}
