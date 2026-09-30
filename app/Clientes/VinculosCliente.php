<?php

namespace App\Clientes;

use App\Core\Database;
use App\Core\Timeline;

/**
 * VinculosCliente — o que está ligado a um cliente e mora do lado da prospecção.
 *
 * ---------------------------------------------------------------------------
 * O PROBLEMA
 * ---------------------------------------------------------------------------
 * Conversa de WhatsApp e tarefa são amarradas à PROSPECÇÃO:
 *
 *   whatsapp_chats.linked_card_id
 *   task_links.link_type = 'card'
 *
 * Nenhuma das duas tem equivalente para cliente. Quando a prospecção virava
 * cliente, a conversa e as tarefas ficavam para trás: a ficha do cliente abria
 * sem a conversa que originou o contato e sem os compromissos já marcados.
 *
 * ---------------------------------------------------------------------------
 * A ESCOLHA: RESOLVER PELA ORIGEM, NÃO DUPLICAR O VÍNCULO
 * ---------------------------------------------------------------------------
 * Em vez de criar `linked_cliente_id` e um valor 'cliente' no ENUM de
 * task_links, o caminho é o mesmo da timeline: o cliente pergunta quais
 * prospecções apontam para ele (`cards.cliente_id`) e resolve a partir dali.
 *
 * Três razões:
 *
 *  1. Dois vínculos para a mesma coisa divergem. Alguém desvincula a conversa
 *     do card e ela continua no cliente, ou o contrário, e não há como saber
 *     qual dos dois está certo.
 *  2. O card NUNCA é apagado na conversão, então a ponte é permanente.
 *  3. A pessoa que volta como prospecção nova, ligada ao MESMO cliente, entra
 *     automaticamente. Com vínculo duplicado seria preciso lembrar de copiar.
 *
 * O processo é a exceção deliberada, e por um motivo concreto:
 * `processos.cliente_id` já existia e a tela de Clientes já lista por ele. Ali
 * o vínculo direto é o que o sistema esperava, não uma invenção nova.
 *
 * ---------------------------------------------------------------------------
 * MULTI-TENANT
 * ---------------------------------------------------------------------------
 * `tasks` NÃO tem account_id: a conta vem do quadro (`task_boards.account_id`).
 * Toda leitura de tarefa aqui passa por esse JOIN. Sem ele, a ficha do cliente
 * de um escritório mostraria tarefa de outro.
 */
final class VinculosCliente
{
    /**
     * Conversas de WhatsApp do cliente, por dois caminhos que se somam:
     *
     *  1. as das prospecções que deram origem a ele (`linked_card_id`);
     *  2. a conversa cujo NÚMERO é o WhatsApp ou o telefone do próprio cliente.
     *
     * O segundo caminho existe porque cliente cadastrado direto (pela tela de
     * Clientes, ou pelo botão "Cadastrar como cliente" da conversa) não tem
     * prospecção nenhuma: a ficha abria sem a conversa, embora o número dela
     * estivesse escrito no cadastro. Continua sendo resolução, não vínculo
     * gravado: trocou o telefone do cliente, a conversa acompanha.
     *
     * @param int[] $accountIds contas que a sessão alcança
     */
    public static function conversas(int $clienteId, array $accountIds): array
    {
        $accountIds = self::inteiros($accountIds);
        if ($accountIds === [] || $clienteId <= 0) {
            return [];
        }
        $pdo = Database::getConnection();
        $inA = implode(',', array_fill(0, count($accountIds), '?'));
        $achadas = [];

        $cards = Timeline::cardsDoCliente($clienteId, $accountIds);
        if ($cards !== []) {
            $inC = implode(',', array_fill(0, count($cards), '?'));
            $st  = $pdo->prepare(
                "SELECT wc.id, wc.remote_jid, wc.contact_name, wc.linked_card_id,
                        wc.instance_id, wc.phone, wc.last_message_at,
                        c.cliente_nome AS origem_titulo
                   FROM whatsapp_chats wc
                   JOIN cards c ON c.id = wc.linked_card_id
                  WHERE wc.linked_card_id IN ($inC)
                    AND wc.account_id IN ($inA)"
            );
            $st->execute(array_merge($cards, array_values($accountIds)));
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $achadas[(int) $r['id']] = $r;
            }
        }

        foreach (self::conversasPeloTelefone($pdo, $clienteId, $accountIds) as $r) {
            $achadas[(int) $r['id']] ??= $r;   // a de prospecção já traz a origem
        }

        krsort($achadas);                      // mesma ordem de antes: id DESC
        return array_values($achadas);
    }

    /**
     * Conversas 1:1 cujo número é o WhatsApp ou o telefone do cliente.
     *
     * Conta filtrada duas vezes: o cliente tem de ser de uma conta alcançada, e a
     * conversa também. Grupo fica de fora. Conversa gravada sob o identificador
     * de privacidade (@lid) entra pela tabela de identidades, que guarda o
     * telefone real por trás dele.
     */
    private static function conversasPeloTelefone(\PDO $pdo, int $clienteId, array $accountIds): array
    {
        $contas = array_values($accountIds);
        $inA    = implode(',', array_fill(0, count($contas), '?'));
        $st     = $pdo->prepare(
            "SELECT telefone, whatsapp FROM clientes
              WHERE id = ? AND account_id IN ($inA) AND deleted_at IS NULL LIMIT 1"
        );
        $st->execute(array_merge([$clienteId], $contas));
        $cli = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$cli) {
            return [];
        }

        $fones = [];
        foreach ([$cli['whatsapp'] ?? '', $cli['telefone'] ?? ''] as $bruto) {
            foreach (self::variantesDeTelefone((string) $bruto) as $v) {
                $fones[$v] = $v;
            }
        }
        if ($fones === []) {
            return [];
        }
        $fones = array_values($fones);
        $jids  = array_map(static fn(string $f): string => $f . '@s.whatsapp.net', $fones);
        $inF   = implode(',', array_fill(0, count($fones), '?'));

        $st = $pdo->prepare(
            "SELECT wc.id, wc.remote_jid, wc.contact_name, wc.linked_card_id,
                    wc.instance_id, wc.phone, wc.last_message_at,
                    NULL AS origem_titulo
               FROM whatsapp_chats wc
              WHERE wc.account_id IN ($inA)
                AND COALESCE(wc.is_group, 0) = 0
                AND (
                      wc.remote_jid IN ($inF)
                   OR EXISTS (
                        SELECT 1 FROM whatsapp_identidades i
                         WHERE i.instance_id = wc.instance_id
                           AND i.account_id  = wc.account_id
                           AND i.phone IN ($inF)
                           AND (i.lid = wc.remote_jid OR i.jid = wc.remote_jid)
                      )
                    )"
        );
        $st->execute(array_merge($contas, $jids, $fones));
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * As formas em que um telefone brasileiro pode aparecer como conversa.
     *
     * O cadastro guarda "(11) 98888-7777"; a conversa guarda "5511988887777".
     * E número antigo circula no WhatsApp SEM o nono dígito, então as duas
     * formas entram. Número que não parece brasileiro só casa exato. PURA.
     *
     * @return string[] só dígitos, com o 55 na frente
     */
    public static function variantesDeTelefone(string $bruto): array
    {
        $d = ltrim((string) preg_replace('/\D+/', '', $bruto), '0');
        if (strlen($d) < 10) {
            return [];
        }
        if (str_starts_with($d, '55') && in_array(strlen($d), [12, 13], true)) {
            $nacional = substr($d, 2);
        } elseif (in_array(strlen($d), [10, 11], true)) {
            $nacional = $d;
        } else {
            return [$d];
        }

        $ddd    = substr($nacional, 0, 2);
        $resto  = substr($nacional, 2);
        $formas = [$resto];
        if (strlen($resto) === 9 && $resto[0] === '9') {
            $formas[] = substr($resto, 1);
        } elseif (strlen($resto) === 8) {
            $formas[] = '9' . $resto;
        }
        return array_map(static fn(string $r): string => '55' . $ddd . $r, $formas);
    }

    /**
     * Tarefas ligadas às prospecções que deram origem a este cliente.
     *
     * @param int[] $accountIds contas que a sessão alcança
     */
    public static function tarefas(int $clienteId, array $accountIds): array
    {
        $accountIds = self::inteiros($accountIds);
        if ($accountIds === [] || $clienteId <= 0) {
            return [];
        }
        $cards = Timeline::cardsDoCliente($clienteId, $accountIds);

        $pdo = Database::getConnection();
        $inA = implode(',', array_fill(0, count($accountIds), '?'));

        /*
         * DOIS caminhos, uma consulta.
         *
         *   link_type='card'     tarefa da prospecção que originou o cliente
         *   link_type='cliente'  tarefa marcada direto na ficha do cliente
         *
         * O segundo só existe desde a migration 127, que ACRESCENTOU 'cliente'
         * ao ENUM de task_links.link_type. Antes dela, um cliente cadastrado à
         * mão, que nunca foi prospecção, não tinha como ter compromisso próprio:
         * a tarefa só chegava até ele por dentro do card de origem.
         *
         * O bloco de card só entra no OR quando existe card, senão o IN () ficaria
         * vazio e o SQL não compila.
         */
        $ors    = ["(tl.link_type = 'cliente' AND tl.link_id = ?)"];
        $params = [$clienteId];

        if ($cards !== []) {
            $inC      = implode(',', array_fill(0, count($cards), '?'));
            $ors[]    = "(tl.link_type = 'card' AND tl.link_id IN ($inC))";
            $params   = array_merge($params, $cards);
        }

        // O JOIN em task_boards não é enfeite: é de onde vem a conta da tarefa.
        // O GROUP BY não é estatística: uma tarefa pode estar ligada ao cliente E
        // ao card de origem ao mesmo tempo, e ela é uma tarefa só. Sem ele a
        // ficha mostraria a mesma tarefa duas vezes. Todas as colunas não
        // agregadas estão no GROUP BY, então ONLY_FULL_GROUP_BY aceita.
        $st = $pdo->prepare(
            "SELECT t.id, t.titulo, t.status, t.prazo, t.prioridade,
                    u.nome AS responsavel_nome,
                    MAX(CASE WHEN tl.link_type = 'card' THEN tl.link_id END) AS origem_card_id
               FROM task_links tl
               JOIN tasks       t ON t.id = tl.task_id
               JOIN task_boards b ON b.id = t.board_id
          LEFT JOIN users       u ON u.id = t.responsavel_id
              WHERE (" . implode(' OR ', $ors) . ")
                AND b.account_id IN ($inA)
           GROUP BY t.id, t.titulo, t.status, t.prazo, t.prioridade, u.nome
           ORDER BY (t.status = 'concluida'), t.prazo IS NULL, t.prazo, t.id DESC"
        );
        $st->execute(array_merge($params, $accountIds));
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return int[] */
    private static function inteiros(array $v): array
    {
        $out = [];
        foreach ($v as $x) {
            $i = (int) $x;
            if ($i > 0) {
                $out[$i] = $i;
            }
        }
        return array_values($out);
    }
}
