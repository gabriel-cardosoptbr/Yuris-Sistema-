<?php
namespace App\WhatsAppAgente;

use App\Core\Database;

/**
 * SituacaoConversa: a linha "Aguardando resposta" do Chat da edição CRM.
 *
 * Pedido de 10/10/2026. Um lead respondeu numa quarta às 9:12 e ficou três dias
 * sem resposta, e o Chat não dizia nada: o cliente achou que a Vitória tinha sido
 * desligada para ele. O que houve foi o número estar fora do ar naquela hora; a
 * mensagem voltou depois pela sincronização, que não entrega ao agente (e a
 * Vitória ignora de propósito mensagem com mais de 15 min).
 *
 * Agora, quando a última palavra é do lead, o Chat diz há quanto tempo, quem
 * atende e por que ninguém respondeu, com um botão "Mandar para a Vitória" quando
 * isso resolve.
 *
 * ---------------------------------------------------------------------------
 * MOTIVOS (avaliar(), pura)
 * ---------------------------------------------------------------------------
 *   treino             número de treino: a Vitória responde lá pelo fluxo de treino
 *   sem_agente         conta sem agente de pré-venda configurado
 *   pausada            alguém assumiu a conversa: a Vitória não responde ali
 *   numero_fora        o número da conversa não está conectado
 *   agente_desligado   a chave "Agente" do número está desligada
 *   respondendo        entregue ao agente há menos de 3 min
 *   sem_resposta       entregue ao agente, que não respondeu (pode ter decidido
 *                      esperar ou passado para o especialista)
 *   entrega_falhou     a entrega ao agente deu erro
 *   chegou_sem_webhook a mensagem só chegou pela sincronização (número fora do ar
 *                      na hora): o agente nunca recebeu
 *   sem_registro       chegou pelo webhook antes de existir o registro de entregas
 *
 * Mandar para o agente (mandarParaAgente) refaz a entrega com o horário do clique,
 * porque a Vitória descarta mensagem velha. Ela lê o histórico no WhatsApp, então
 * responde sabendo o que já foi dito. Cada entrega fica em sdr_encaminhamentos.
 */
final class SituacaoConversa
{
    /** Entregue há menos que isto: o agente ainda está respondendo. */
    public const RESPONDENDO_SEG = 180;
    /** Dois cliques seguidos em "Mandar para a Vitória" não entregam duas vezes. */
    public const INTERVALO_MANUAL_SEG = 60;

    private const PODE_MANDAR = ['chegou_sem_webhook', 'sem_registro', 'entrega_falhou', 'sem_resposta'];

    /**
     * PURA. $f:
     *   grupo, treino, agente_disponivel, numero_aberto, agente_ligado, pausada (bool)
     *   pendentes  list<array{ts:int, webhook:bool}>  mensagens do lead depois da nossa última
     *   entregas   list<array{ts:int, ok:bool}>       entregas ao agente, em ordem
     *   agora      int
     * @return array{aguardando:bool, desde:?int, quantas:int, motivo:?string, pode_mandar:bool, entregue_em:?int}
     */
    public static function avaliar(array $f): array
    {
        $r = ['aguardando' => false, 'desde' => null, 'quantas' => 0, 'motivo' => null, 'pode_mandar' => false, 'entregue_em' => null];
        $pend = $f['pendentes'] ?? [];
        if (!empty($f['grupo']) || !$pend) return $r;

        $r['aguardando'] = true;
        $r['quantas']    = count($pend);
        $r['desde']      = (int)$pend[0]['ts'];

        // Entregas que valem: a partir da primeira mensagem pendente (folga de 1 min
        // para relógio do servidor e do WhatsApp).
        $entregas = array_values(array_filter($f['entregas'] ?? [], fn($e) => (int)$e['ts'] >= $r['desde'] - 60));
        $ultimaEntrega = $entregas ? end($entregas) : null;

        if (!empty($f['treino']))                 $motivo = 'treino';
        elseif (empty($f['agente_disponivel']))   $motivo = 'sem_agente';
        elseif (!empty($f['pausada']))            $motivo = 'pausada';
        elseif (empty($f['numero_aberto']))       $motivo = 'numero_fora';
        elseif (empty($f['agente_ligado']))       $motivo = 'agente_desligado';
        elseif ($ultimaEntrega && $ultimaEntrega['ok']) {
            $r['entregue_em'] = (int)$ultimaEntrega['ts'];
            $motivo = ((int)$f['agora'] - (int)$ultimaEntrega['ts'] <= self::RESPONDENDO_SEG) ? 'respondendo' : 'sem_resposta';
        }
        elseif ($ultimaEntrega)                    { $motivo = 'entrega_falhou'; $r['entregue_em'] = (int)$ultimaEntrega['ts']; }
        else {
            $algumWebhook = false;
            foreach ($pend as $p) if (!empty($p['webhook'])) { $algumWebhook = true; break; }
            $motivo = $algumWebhook ? 'sem_registro' : 'chegou_sem_webhook';
        }
        $r['motivo'] = $motivo;
        $r['pode_mandar'] = in_array($motivo, self::PODE_MANDAR, true);
        return $r;
    }

    /**
     * Junta os fatos da conversa e avalia. A conta é a DONA do canal, já resolvida
     * pelo WhatsAppChannelAccessService; tudo aqui filtra pela instância dela.
     *
     * @return array|null  null se a conversa não existe nessa instância
     */
    public static function daConversa(int $ownerAccountId, int $instanceId, string $remoteJid): ?array
    {
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT c.id, c.agent_paused, c.agent_paused_by, c.agent_paused_at, c.is_group, c.phone,
                                    i.status AS inst_status, i.instance_name,
                                    COALESCE(NULLIF(TRIM(i.display_name), \'\'), i.instance_name) AS numero_nome
                               FROM whatsapp_chats c
                               JOIN whatsapp_instances i ON i.id = c.instance_id
                              WHERE c.instance_id = ? AND c.remote_jid = ? AND i.account_id = ?
                              LIMIT 1');
        $st->execute([$instanceId, $remoteJid, $ownerAccountId]);
        $c = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$c) return null;

        $crm = SdrFleetiflow::contaUsa($ownerAccountId);
        $agenteDisp = $crm && SdrFleetiflow::urlDaConta($ownerAccountId) !== '';
        $st = $pdo->prepare('SELECT 1 FROM agent_configs WHERE whatsapp_instance_id = ? AND enabled = 1 LIMIT 1');
        $st->execute([$instanceId]);
        $ligado = (bool)$st->fetchColumn();

        $pausadaPor = null;
        if ((int)$c['agent_paused'] === 1 && (int)$c['agent_paused_by'] > 0) {
            $st = $pdo->prepare('SELECT nome FROM users WHERE id = ? AND account_id = ? LIMIT 1');
            $st->execute([(int)$c['agent_paused_by'], $ownerAccountId]);
            $pausadaPor = ((string)$st->fetchColumn()) ?: null;
        }

        $pend = self::pendentes($instanceId, $remoteJid);
        $entregas = [];
        if ($pend) {
            $st = $pdo->prepare('SELECT created_at, ok FROM sdr_encaminhamentos
                                  WHERE instance_id = ? AND remote_jid = ? AND created_at >= ?
                                  ORDER BY created_at, id');
            try {
                $st->execute([$instanceId, $remoteJid, date('Y-m-d H:i:s', $pend[0]['ts'] - 60)]);
                foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $e) $entregas[] = ['ts' => (int)strtotime((string)$e['created_at']), 'ok' => (int)$e['ok'] === 1];
            } catch (\Throwable $e) { /* migration 142 ainda não rodou: sem entregas */ }
        }

        $av = self::avaliar([
            'grupo'             => (int)$c['is_group'] === 1 || str_ends_with($remoteJid, '@g.us'),
            'treino'            => $crm && SdrFleetiflow::ehTreino($instanceId, $remoteJid),
            'agente_disponivel' => $agenteDisp,
            'numero_aberto'     => strtolower((string)$c['inst_status']) === 'open',
            'agente_ligado'     => $ligado,
            'pausada'           => (int)$c['agent_paused'] === 1,
            'pendentes'         => $pend,
            'entregas'          => $entregas,
            'agora'             => time(),
        ]);

        $fmt = fn(?int $ts) => $ts ? date('Y-m-d H:i:s', $ts) : null;
        return [
            'aguardando'  => $av['aguardando'],
            'desde'       => $fmt($av['desde']),
            'quantas'     => $av['quantas'],
            'motivo'      => $av['motivo'],
            'pode_mandar' => $av['pode_mandar'],
            'entregue_em' => $fmt($av['entregue_em']),
            'agente'      => [
                'nome'       => AutorDaMensagem::nomeCurto(SdrFleetiflow::nomeAgenteDaConta($ownerAccountId)),
                'disponivel' => $agenteDisp,
                'ligado'     => $ligado,
            ],
            'pausada'     => (int)$c['agent_paused'] === 1,
            'pausada_por' => $pausadaPor,
            'pausada_em'  => $c['agent_paused_at'] ?: null,
            'numero'      => ['nome' => (string)$c['numero_nome'], 'aberto' => strtolower((string)$c['inst_status']) === 'open'],
            'agora'       => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Mensagens do lead depois da nossa última (as que esperam resposta), em
     * ordem. Olha as 50 mais recentes.
     *
     * @return list<array{ts:int,webhook:bool,id:int,wamid:string,tipo:string,texto:string,nome:string}>
     */
    private static function pendentes(int $instanceId, string $remoteJid): array
    {
        $st = Database::getConnection()->prepare(
            'SELECT id, wamid, direction, message_type, message_content, contact_name, created_at, raw_payload IS NOT NULL AS webhook
               FROM whatsapp_messages
              WHERE instance_id = ? AND remote_jid = ? AND deleted_at IS NULL
              ORDER BY created_at DESC, id DESC LIMIT 50'
        );
        $st->execute([$instanceId, $remoteJid]);
        $pend = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $m) {
            if ($m['direction'] === 'outbound') break;
            $pend[] = ['ts' => (int)strtotime((string)$m['created_at']), 'webhook' => (bool)$m['webhook'], 'id' => (int)$m['id'],
                       'wamid' => (string)$m['wamid'], 'tipo' => (string)$m['message_type'],
                       'texto' => (string)$m['message_content'], 'nome' => (string)$m['contact_name']];
        }
        return array_reverse($pend);
    }

    /**
     * Botão "Mandar para a Vitória": entrega de novo ao agente as mensagens do lead
     * que esperam resposta, com o horário de agora.
     *
     * @return array{ok:bool, error?:string, enviadas?:int}
     */
    public static function mandarParaAgente(int $ownerAccountId, int $instanceId, string $remoteJid, ?int $userId): array
    {
        $sit = self::daConversa($ownerAccountId, $instanceId, $remoteJid);
        if ($sit === null) return ['ok' => false, 'error' => 'Conversa não encontrada.'];
        if (!$sit['pode_mandar']) {
            $porque = [
                'pausada'          => 'A conversa está com uma pessoa do time: devolva antes de mandar.',
                'agente_desligado' => 'A chave "Agente" deste número está desligada.',
                'numero_fora'      => 'O número desta conversa não está conectado.',
                'respondendo'      => 'Acabou de ser entregue e está sendo respondida.',
            ][$sit['motivo'] ?? ''] ?? 'Não há mensagem do lead esperando resposta.';
            return ['ok' => false, 'error' => $porque];
        }

        $pdo = Database::getConnection();
        $st = $pdo->prepare("SELECT COUNT(*) FROM sdr_encaminhamentos WHERE instance_id = ? AND remote_jid = ? AND origem = 'manual' AND created_at >= ?");
        $st->execute([$instanceId, $remoteJid, date('Y-m-d H:i:s', time() - self::INTERVALO_MANUAL_SEG)]);
        if ((int)$st->fetchColumn() > 0) return ['ok' => false, 'error' => 'Já foi mandada há instantes. Aguarde a resposta.'];

        $st = $pdo->prepare('SELECT phone FROM whatsapp_chats WHERE instance_id = ? AND remote_jid = ? LIMIT 1');
        $st->execute([$instanceId, $remoteJid]);
        $fone = preg_replace('/\D/', '', (string)$st->fetchColumn());

        $enviadas = 0; $falhas = 0;
        foreach (self::pendentes($instanceId, $remoteJid) as $p) {
            if ($p['tipo'] !== 'text' || trim($p['texto']) === '') continue;
            $key = ['remoteJid' => $remoteJid, 'fromMe' => false, 'id' => $p['wamid']];
            if (str_ends_with($remoteJid, '@lid') && preg_match('/^\d{10,13}$/', $fone)) $key['remoteJidAlt'] = $fone . '@s.whatsapp.net';
            $ok = SdrFleetiflow::encaminhar([
                'account_id'  => $ownerAccountId,
                'instance_id' => $instanceId,
                'remote_jid'  => $remoteJid,
                'origem'      => 'manual',
                'user_id'     => $userId,
                'payload'     => [
                    'key'              => $key,
                    'pushName'         => $p['nome'],
                    'messageType'      => 'conversation',
                    'message'          => ['conversation' => $p['texto']],
                    // Horário do clique: a Vitória descarta mensagem com mais de 15 min.
                    'messageTimestamp' => time(),
                ],
            ]);
            $ok ? $enviadas++ : $falhas++;
        }
        if ($enviadas === 0 && $falhas === 0) return ['ok' => false, 'error' => 'O lead mandou só mídia: responda pelo Chat.'];
        if ($enviadas === 0) return ['ok' => false, 'error' => 'Não foi possível entregar. Tente de novo em instantes.'];

        \App\Master\Account::audit($ownerAccountId, 'sdr.mandar_para_agente', [
            'user_id' => $userId, 'entidade' => 'whatsapp_chats', 'entidade_id' => null,
            'detalhes' => ['instance_id' => $instanceId, 'mensagens' => $enviadas, 'falhas' => $falhas],
        ]);
        return ['ok' => true, 'enviadas' => $enviadas];
    }
}
