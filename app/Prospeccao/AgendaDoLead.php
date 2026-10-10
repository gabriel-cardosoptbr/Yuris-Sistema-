<?php
namespace App\Prospeccao;

use App\Core\Database;
use App\Crm\Interacao;
use App\Notificacoes\Aviso;
use App\Tarefas\Task;
use App\Tarefas\TaskBoard;
use App\Tarefas\TaskColumn;
use App\Tarefas\TaskEntrega;
use App\Tarefas\TaskLink;
use App\WhatsAppAgente\EvolutionApiService;
use App\WhatsAppAgente\SdrFleetiflow;
use App\WhatsAppAgente\WhatsAppChannelAccessService;
use App\WhatsAppAgente\WhatsAppInstance;
use App\WhatsAppAgente\WhatsAppMessage;

/**
 * A PRÓXIMA INTERAÇÃO COM O LEAD (10/10/2026)
 *
 * Pedido do cliente Fleetiflow: "conversei com o cliente e ele pediu para eu
 * entrar em contato daqui a 2 dias, ou marcamos uma call". O vendedor agenda na
 * ficha do lead, e o agendamento vira TAREFA no quadro: aparece no Kanban, na
 * Lista e no Calendário de Tarefas, com o lead vinculado.
 *
 * A DATA MORA NA TAREFA. `tasks.prazo` é a única fonte da hora: quem arrasta a
 * tarefa para outro dia na agenda move o lembrete e o envio da mensagem junto.
 * `crm_agendamentos` guarda só o que a tarefa não tem (lead, tipo, lembrete,
 * mensagem programada).
 *
 * TRÊS EFEITOS, todos em `bin/agenda_crm_worker.php`, a cada minuto:
 *
 *  1. LEMBRETE: `lembrete_min` antes da hora, o responsável da tarefa recebe um
 *     aviso no sino com link para o lead. `lembrete_enviado_para` guarda o prazo
 *     avisado: se a tarefa for remarcada, avisa de novo para a nova hora.
 *  2. MENSAGEM PROGRAMADA: tipo "mensagem" com texto sai pelo WhatsApp na hora.
 *     A linha é marcada `enviando` ANTES de chamar a Evolution (dois workers não
 *     mandam a mesma mensagem). Falha não é repetida sozinha: quem agendou é
 *     avisado e decide reenviar, porque um erro de rede pode ter entregue.
 *     Enviada, a tarefa é concluída e a conversa vira "pessoa assumiu" para o
 *     robô SDR, igual a quem manda pelo Chat.
 *  3. CANCELAMENTO: tarefa concluída, arquivada ou lead excluído antes da hora
 *     cancela a mensagem.
 *
 * O AVISO DO DIA (popup ao entrar) é `doDia()`, servido por /api/crm_agenda.php.
 *
 * Fuso: `tasks.prazo` guarda a hora de Brasília como digitada, e PHP/MySQL rodam
 * em UTC. Toda comparação usa `TaskEntrega::agoraLocal()`, nunca NOW().
 */
final class AgendaDoLead
{
    public const TIPOS = [
        'ligacao'  => 'Ligação',
        'reuniao'  => 'Reunião',
        'mensagem' => 'Mensagem',
        'tarefa'   => 'Tarefa',
    ];

    /** Minutos antes da hora. -1 = não avisar. */
    public const LEMBRETES = [-1, 0, 5, 15, 30, 60, 120, 1440];

    public const MENSAGEM_MAX = 4000;

    /** Quanto tempo uma linha pode ficar `enviando` antes de ser dada como interrompida. */
    private const ENVIANDO_MAX_MIN = 10;

    /** Lembrete de algo que passou há mais que isto não sai mais (worker parado). */
    private const LEMBRETE_ATRASO_MAX_MIN = 120;

    // ── Leitura ──────────────────────────────────────────────────────────────

    /** O lead, se for de uma das contas acessíveis. */
    public static function cardDaConta(int $cardId, array $accountIds): ?array
    {
        $ids = self::ids($accountIds);
        if ($cardId <= 0 || !$ids) return null;
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::getConnection()->prepare(
            "SELECT id, account_id, cliente_nome, empresa_nome, titulo, telefone_whatsapp, responsavel_user_id
               FROM cards WHERE id = ? AND deleted_at IS NULL AND account_id IN ($in) LIMIT 1"
        );
        $st->execute(array_merge([$cardId], $ids));
        $c = $st->fetch(\PDO::FETCH_ASSOC);
        return $c ?: null;
    }

    public static function nomeDoLead(array $card): string
    {
        foreach (['cliente_nome', 'empresa_nome', 'titulo'] as $k) {
            $v = trim((string) ($card[$k] ?? ''));
            if ($v !== '') return $v;
        }
        return 'Lead #' . (int) $card['id'];
    }

    /** Quadros da conta do lead em que a pessoa pode criar tarefa. */
    public static function quadros(int $userId, int $accountId, bool $isAdmin): array
    {
        $out = [];
        foreach (TaskBoard::findForUser($userId, [$accountId], $isAdmin) as $b) {
            if ((int) $b['account_id'] !== $accountId) continue;
            if (!TaskBoard::canEdit((int) $b['id'], $userId, [$accountId], $isAdmin)) continue;
            $out[] = ['id' => (int) $b['id'], 'nome' => (string) $b['nome'], 'tipo' => (string) $b['tipo']];
        }
        return $out;
    }

    /** O quadro sugerido: o primeiro compartilhado; senão o primeiro; null se nenhum. */
    public static function quadroPadrao(array $quadros): ?int
    {
        foreach ($quadros as $q) if ($q['tipo'] === 'compartilhado') return $q['id'];
        return $quadros[0]['id'] ?? null;
    }

    /** Pessoas da conta do lead que podem ser responsáveis. */
    public static function equipe(int $accountId): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT id, nome FROM users WHERE account_id = ? AND status = 'active' AND deleted_at IS NULL ORDER BY nome"
        );
        $st->execute([$accountId]);
        return array_map(fn($u) => ['id' => (int) $u['id'], 'nome' => (string) $u['nome']], $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Por onde a mensagem programada sai: a conversa já ligada ao lead, senão o
     * WhatsApp do cadastro pelo número da própria conta. null = não há como.
     *
     * @return array{canal_id:int, remote_jid:string, numero:string}|null
     */
    public static function destinoDoWhatsapp(array $card): ?array
    {
        $pdo = Database::getConnection();
        $acc = (int) $card['account_id'];

        $st = $pdo->prepare(
            'SELECT instance_id, remote_jid FROM whatsapp_chats
              WHERE linked_card_id = ? AND account_id = ? AND remote_jid <> ""
              ORDER BY last_message_at DESC, id DESC LIMIT 5'
        );
        $st->execute([(int) $card['id'], $acc]);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $chat) {
            $canal = (int) $chat['instance_id'];
            if ($canal > 0 && WhatsAppChannelAccessService::check($pdo, $acc, $canal, 'send')) {
                $jid = (string) $chat['remote_jid'];
                return ['canal_id' => $canal, 'remote_jid' => $jid, 'numero' => self::numeroDoJid($jid)];
            }
        }

        $fone = self::telefoneInternacional((string) ($card['telefone_whatsapp'] ?? ''));
        $canal = WhatsAppChannelAccessService::ownChannelId($pdo, $acc);
        if ($fone !== null && $canal && WhatsAppChannelAccessService::check($pdo, $acc, $canal, 'send')) {
            return ['canal_id' => $canal, 'remote_jid' => $fone . '@s.whatsapp.net', 'numero' => $fone];
        }
        return null;
    }

    /** "11 99999-8888" → "5511999998888"; null se não parece telefone. */
    public static function telefoneInternacional(string $bruto): ?string
    {
        $d = ltrim((string) preg_replace('/\D+/', '', $bruto), '0');
        if (strlen($d) === 10 || strlen($d) === 11) return '55' . $d;
        if (str_starts_with($d, '55') && in_array(strlen($d), [12, 13], true)) return $d;
        if (strlen($d) >= 12 && strlen($d) <= 15) return $d;
        return null;
    }

    private static function numeroDoJid(string $jid): string
    {
        return str_ends_with($jid, '@s.whatsapp.net') ? explode('@', $jid)[0] : '';
    }

    /** Agendamentos do lead, do mais próximo ao mais antigo; os já feitos por último. */
    public static function doCard(int $cardId, int $accountId): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT a.id, a.task_id, a.tipo, a.lembrete_min, a.mensagem, a.envio_status, a.envio_erro,
                    a.enviada_em, a.created_at, t.titulo, t.prazo, t.status AS tarefa_status,
                    t.responsavel_id, u.nome AS responsavel_nome
               FROM crm_agendamentos a
               JOIN tasks t        ON t.id = a.task_id
               JOIN task_boards b  ON b.id = t.board_id AND b.account_id = a.account_id
               LEFT JOIN users u   ON u.id = t.responsavel_id
              WHERE a.card_id = ? AND a.account_id = ? AND t.status <> 'arquivada'
              ORDER BY (t.status = 'ativa') DESC, t.prazo ASC, a.id DESC
              LIMIT 50"
        );
        $st->execute([$cardId, $accountId]);
        $agora = TaskEntrega::agoraLocal();
        return array_map(function ($r) use ($agora) {
            $r['id']           = (int) $r['id'];
            $r['task_id']      = (int) $r['task_id'];
            $r['lembrete_min'] = (int) $r['lembrete_min'];
            $r['tipo_rotulo']  = self::TIPOS[$r['tipo']] ?? $r['tipo'];
            $r['atrasada']     = $r['tarefa_status'] === 'ativa' && $r['prazo'] !== null && $r['prazo'] < $agora;
            return $r;
        }, $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * O próximo agendamento em aberto de cada lead, para o card do funil: o de
     * hora mais cedo entre as tarefas ainda ativas (um atrasado vem primeiro).
     * Só lê agendamento da mesma conta do lead; quem chama já filtrou os cards.
     *
     * @param int[] $cardIds
     * @return array<int, array> card_id => tipo, tipo_rotulo, prazo, titulo,
     *                           responsavel_id, responsavel_nome, atrasada, mensagem_programada
     */
    public static function proximas(array $cardIds, ?string $agora = null): array
    {
        $ids = self::ids($cardIds);
        if (!$ids) return [];
        $agora = $agora ?? TaskEntrega::agoraLocal();
        $out = [];
        foreach (array_chunk($ids, 500) as $lote) {
            $in = implode(',', array_fill(0, count($lote), '?'));
            $st = Database::getConnection()->prepare(
                "SELECT a.card_id, a.tipo, a.envio_status, t.prazo, t.titulo, t.responsavel_id, u.nome AS responsavel_nome
                   FROM crm_agendamentos a
                   JOIN cards c       ON c.id = a.card_id AND c.account_id = a.account_id
                   JOIN tasks t       ON t.id = a.task_id AND t.status = 'ativa' AND t.prazo IS NOT NULL
                   JOIN task_boards b ON b.id = t.board_id AND b.account_id = a.account_id AND b.ativo = 1
                   LEFT JOIN users u  ON u.id = t.responsavel_id
                  WHERE a.card_id IN ($in)
                  ORDER BY t.prazo ASC, a.id ASC"
            );
            $st->execute($lote);
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $card = (int) $r['card_id'];
                if (isset($out[$card])) continue;
                $out[$card] = [
                    'tipo'                => $r['tipo'],
                    'tipo_rotulo'         => self::TIPOS[$r['tipo']] ?? $r['tipo'],
                    'prazo'               => (string) $r['prazo'],
                    'titulo'              => (string) $r['titulo'],
                    'responsavel_id'      => $r['responsavel_id'] !== null ? (int) $r['responsavel_id'] : null,
                    'responsavel_nome'    => $r['responsavel_nome'],
                    'atrasada'            => $r['prazo'] < $agora,
                    'mensagem_programada' => $r['envio_status'] === 'pendente',
                ];
            }
        }
        return $out;
    }

    /**
     * As ações do dia da pessoa: tudo que vence hoje e o que ficou atrasado nos
     * últimos 30 dias, de qualquer quadro das contas dela. É o aviso ao entrar.
     */
    public static function doDia(int $userId, array $accountIds, ?string $agora = null): array
    {
        $ids = self::ids($accountIds);
        if ($userId <= 0 || !$ids) return [];
        $agora = $agora ?? TaskEntrega::agoraLocal();
        $hoje  = substr($agora, 0, 10);
        $in    = implode(',', array_fill(0, count($ids), '?'));

        $st = Database::getConnection()->prepare(
            "SELECT t.id, t.titulo, t.prazo, t.prioridade, b.nome AS quadro,
                    a.tipo, a.card_id, a.mensagem, a.envio_status,
                    (SELECT tl.link_id FROM task_links tl WHERE tl.task_id = t.id AND tl.link_type = 'card' ORDER BY tl.id LIMIT 1) AS card_vinculado
               FROM tasks t
               JOIN task_boards b ON b.id = t.board_id AND b.ativo = 1
               LEFT JOIN crm_agendamentos a ON a.task_id = t.id AND a.account_id = b.account_id
              WHERE b.account_id IN ($in)
                AND t.status = 'ativa'
                AND t.prazo IS NOT NULL
                AND (t.responsavel_id = ? OR (t.responsavel_id IS NULL AND t.criado_por_id = ?))
                AND t.prazo >= ? AND t.prazo <= ?
              ORDER BY t.prazo ASC, t.id ASC
              LIMIT 60"
        );
        $desde = (new \DateTimeImmutable($hoje))->modify('-30 days')->format('Y-m-d 00:00:00');
        $st->execute(array_merge($ids, [$userId, $userId, $desde, $hoje . ' 23:59:59']));

        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $card = (int) ($r['card_id'] ?: $r['card_vinculado']);
            $out[] = [
                'task_id'      => (int) $r['id'],
                'titulo'       => (string) $r['titulo'],
                'prazo'        => (string) $r['prazo'],
                'hora'         => substr((string) $r['prazo'], 11, 5),
                'atrasada'     => $r['prazo'] < $agora,
                'de_hoje'      => substr((string) $r['prazo'], 0, 10) === $hoje,
                'quadro'       => (string) $r['quadro'],
                'tipo'         => $r['tipo'] ?: null,
                'tipo_rotulo'  => $r['tipo'] ? (self::TIPOS[$r['tipo']] ?? $r['tipo']) : 'Tarefa',
                'mensagem_programada' => $r['envio_status'] === 'pendente',
                'card_id'      => $card ?: null,
                'url'          => $card ? '/prospeccao.php?open=' . $card : '/tarefas.php?tarefa=' . (int) $r['id'],
                'url_tarefa'   => '/tarefas.php?tarefa=' . (int) $r['id'],
            ];
        }
        return $out;
    }

    // ── Escrita ──────────────────────────────────────────────────────────────

    /**
     * Agenda a próxima interação. Cria a tarefa, vincula o lead e grava o
     * agendamento numa transação só.
     *
     * @param array $dados tipo, quando ("Y-m-d H:i" ou datetime-local), assunto,
     *                     observacao, lembrete_min, board_id, responsavel_id, mensagem
     * @return array{id:int, task_id:int}
     * @throws \InvalidArgumentException com mensagem para a tela
     */
    public static function criar(array $card, array $dados, int $userId, bool $isAdmin, ?string $agora = null): array
    {
        $acc  = (int) $card['account_id'];
        $tipo = (string) ($dados['tipo'] ?? '');
        if (!isset(self::TIPOS[$tipo])) throw new \InvalidArgumentException('Escolha o tipo da interação.');

        $quando = self::momento((string) ($dados['quando'] ?? ''));
        if ($quando === null) throw new \InvalidArgumentException('Informe a data e a hora.');
        $agora = $agora ?? TaskEntrega::agoraLocal();
        if ($quando < substr($agora, 0, 16) . ':00') {
            throw new \InvalidArgumentException('Essa hora já passou. Escolha uma data e hora futuras.');
        }

        $lembrete = (int) ($dados['lembrete_min'] ?? 15);
        if (!in_array($lembrete, self::LEMBRETES, true)) $lembrete = 15;

        $quadros = self::quadros($userId, $acc, $isAdmin);
        $boardId = (int) ($dados['board_id'] ?? 0);
        if ($boardId && !in_array($boardId, array_column($quadros, 'id'), true)) {
            throw new \InvalidArgumentException('Você não pode criar tarefas nesse quadro.');
        }
        if (!$boardId) $boardId = self::quadroPadrao($quadros) ?? 0;

        $resp = (int) ($dados['responsavel_id'] ?? 0);
        if ($resp && !in_array($resp, array_column(self::equipe($acc), 'id'), true)) {
            throw new \InvalidArgumentException('Responsável inválido.');
        }
        if (!$resp) $resp = $userId;

        $mensagem = trim((string) ($dados['mensagem'] ?? ''));
        $destino  = null;
        if ($tipo !== 'mensagem') $mensagem = '';
        if ($mensagem !== '') {
            if (mb_strlen($mensagem) > self::MENSAGEM_MAX) {
                throw new \InvalidArgumentException('A mensagem passou de ' . self::MENSAGEM_MAX . ' caracteres.');
            }
            $destino = self::destinoDoWhatsapp($card);
            if ($destino === null) {
                throw new \InvalidArgumentException('Este lead não tem WhatsApp para a mensagem sair sozinha. Preencha o WhatsApp do lead ou ligue a conversa ao card.');
            }
        }

        $nome    = self::nomeDoLead($card);
        $assunto = trim((string) ($dados['assunto'] ?? ''));
        $titulo  = $assunto !== '' ? $assunto : match ($tipo) {
            'ligacao'  => 'Ligar para ' . $nome,
            'reuniao'  => 'Reunião com ' . $nome,
            'mensagem' => 'Mensagem para ' . $nome,
            default    => 'Retomar contato com ' . $nome,
        };
        $titulo = mb_substr($titulo, 0, 250);

        $descricao = [];
        $obs = trim((string) ($dados['observacao'] ?? ''));
        if ($obs !== '') $descricao[] = $obs;
        $descricao[] = self::TIPOS[$tipo] . ' com o lead ' . $nome
            . (trim((string) $card['telefone_whatsapp']) !== '' ? ' (' . trim((string) $card['telefone_whatsapp']) . ')' : '') . '.';
        if ($mensagem !== '') $descricao[] = "Mensagem programada para sair no WhatsApp na hora marcada:\n" . $mensagem;

        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            if (!$boardId) $boardId = self::criarQuadroDaAgenda($acc, $userId);
            $col = TaskColumn::initialColumn($boardId);
            if (!$col) $col = ['id' => self::criarColunas($boardId)];

            $taskId = Task::create([
                'board_id'       => $boardId,
                'column_id'      => (int) $col['id'],
                'titulo'         => $titulo,
                'descricao'      => implode("\n\n", $descricao),
                'prioridade'     => 'media',
                'prazo'          => $quando,
                'prazo_tipo'     => 'interno',
                'responsavel_id' => $resp,
                'criado_por_id'  => $userId,
            ]);
            TaskLink::add($taskId, 'card', (int) $card['id']);

            $pdo->prepare(
                'INSERT INTO crm_agendamentos
                   (account_id, card_id, task_id, criado_por_id, tipo, lembrete_min,
                    mensagem, canal_id, remote_jid, envio_status)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $acc, (int) $card['id'], $taskId, $userId, $tipo, $lembrete,
                $mensagem !== '' ? $mensagem : null,
                $destino['canal_id'] ?? null, $destino['remote_jid'] ?? null,
                $mensagem !== '' ? 'pendente' : 'nao_se_aplica',
            ]);
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // No histórico do lead, como nota: a tarefa sozinha não aparece lá.
        try {
            Interacao::registrar(
                ['entidade' => 'card', 'id' => (int) $card['id'], 'account_id' => $acc, 'titulo' => $nome],
                ['tipo' => 'nota', 'assunto' => 'Próxima interação agendada',
                 'conteudo' => self::TIPOS[$tipo] . ' em ' . self::dataBr($quando) . ': ' . $titulo
                     . ($mensagem !== '' ? "\nMensagem programada:\n" . $mensagem : ''),
                 'ocorrido_em' => $agora],
                $userId
            );
        } catch (\Throwable $e) {
            error_log('[agenda_crm] nota do agendamento ' . $id . ': ' . $e->getMessage());
        }

        return ['id' => $id, 'task_id' => $taskId, 'board_id' => $boardId];
    }

    /** Busca um agendamento da conta. */
    public static function buscar(int $id, int $accountId): ?array
    {
        $st = Database::getConnection()->prepare(
            'SELECT a.*, t.status AS tarefa_status, t.prazo FROM crm_agendamentos a
               JOIN tasks t ON t.id = a.task_id
              WHERE a.id = ? AND a.account_id = ? LIMIT 1'
        );
        $st->execute([$id, $accountId]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** Desmarca: arquiva a tarefa e cancela a mensagem que ainda não saiu. */
    public static function cancelar(int $id, int $accountId, int $userId): bool
    {
        $a = self::buscar($id, $accountId);
        if (!$a) return false;
        $pdo = Database::getConnection();
        $pdo->prepare("UPDATE crm_agendamentos SET envio_status = 'cancelada'
                        WHERE id = ? AND envio_status IN ('pendente','falhou')")->execute([$id]);
        if ($a['tarefa_status'] === 'ativa') Task::archive((int) $a['task_id'], $userId);
        return true;
    }

    /** Marca como feita (conclui a tarefa). A mensagem que não saiu é cancelada. */
    public static function concluir(int $id, int $accountId, int $userId): bool
    {
        $a = self::buscar($id, $accountId);
        if (!$a) return false;
        Database::getConnection()->prepare("UPDATE crm_agendamentos SET envio_status = 'cancelada'
                        WHERE id = ? AND envio_status IN ('pendente','falhou')")->execute([$id]);
        if ($a['tarefa_status'] === 'ativa') Task::complete((int) $a['task_id'], $userId);
        return true;
    }

    /**
     * Manda de novo uma mensagem que falhou. Sai no próximo minuto se a hora já
     * passou. Só para quem viu a falha e decidiu: nunca automático.
     */
    public static function reenviar(int $id, int $accountId): bool
    {
        $a = self::buscar($id, $accountId);
        if (!$a || $a['envio_status'] !== 'falhou' || $a['tarefa_status'] !== 'ativa') return false;
        Database::getConnection()->prepare(
            "UPDATE crm_agendamentos SET envio_status = 'pendente', envio_erro = NULL WHERE id = ? AND envio_status = 'falhou'"
        )->execute([$id]);
        return true;
    }

    // ── Worker ───────────────────────────────────────────────────────────────

    /** Avisa o responsável `lembrete_min` antes da hora. Devolve quantos avisos saíram. */
    public static function processarLembretes(?string $agora = null): int
    {
        $agora = $agora ?? TaskEntrega::agoraLocal();
        $pdo = Database::getConnection();
        $limite = (new \DateTimeImmutable($agora))->modify('-' . self::LEMBRETE_ATRASO_MAX_MIN . ' minutes')->format('Y-m-d H:i:s');

        $st = $pdo->prepare(
            "SELECT a.id, a.account_id, a.card_id, a.tipo, a.envio_status, a.criado_por_id,
                    t.prazo, t.titulo, t.responsavel_id
               FROM crm_agendamentos a
               JOIN tasks t       ON t.id = a.task_id
               JOIN task_boards b ON b.id = t.board_id AND b.account_id = a.account_id
               JOIN cards c       ON c.id = a.card_id AND c.account_id = a.account_id AND c.deleted_at IS NULL
              WHERE a.lembrete_min >= 0
                AND t.status = 'ativa' AND t.prazo IS NOT NULL
                AND (a.lembrete_enviado_para IS NULL OR a.lembrete_enviado_para <> t.prazo)
                AND DATE_SUB(t.prazo, INTERVAL a.lembrete_min MINUTE) <= ?
                AND t.prazo >= ?
              ORDER BY t.prazo
              LIMIT 200"
        );
        $st->execute([$agora, $limite]);

        $n = 0;
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            // Marca antes de avisar: rodar duas vezes não avisa duas vezes.
            $up = $pdo->prepare('UPDATE crm_agendamentos SET lembrete_enviado_para = ?
                                  WHERE id = ? AND (lembrete_enviado_para IS NULL OR lembrete_enviado_para <> ?)');
            $up->execute([$r['prazo'], $r['id'], $r['prazo']]);
            if ($up->rowCount() !== 1) continue;

            $para = (int) ($r['responsavel_id'] ?: $r['criado_por_id']);
            if ($para <= 0) continue;
            $hora = substr((string) $r['prazo'], 11, 5);
            $hoje = substr((string) $r['prazo'], 0, 10) === substr($agora, 0, 10);
            $quando = $hoje ? 'às ' . $hora : 'em ' . self::dataBr((string) $r['prazo']);
            $msg = $r['envio_status'] === 'pendente'
                ? 'A mensagem programada sai sozinha no WhatsApp ' . $quando . '.'
                : (self::TIPOS[$r['tipo']] ?? 'Interação') . ' agendada ' . $quando . '.';

            Aviso::paraUsuario((int) $r['account_id'], $para, [
                'tipo'         => 'agenda',
                'titulo'       => ($r['prazo'] <= $agora ? 'Agora: ' : ucfirst($quando) . ': ') . $r['titulo'],
                'mensagem'     => $msg,
                'entidade'     => 'card',
                'entidade_id'  => (int) $r['card_id'],
                'url'          => '/prospeccao.php?open=' . (int) $r['card_id'],
                'preferencia'  => 'prazo',
                'chave_dedupe' => 'agenda:' . $r['id'] . ':' . $r['prazo'],
                'janela_min'   => Aviso::JANELA_DIARIA_MIN,
            ]);
            $n++;
        }
        return $n;
    }

    /**
     * Envia as mensagens programadas que chegaram na hora.
     *
     * @param callable|null $enviar fn(array $cfg, string $instancia, string $jid, string $texto): array
     *                              (a resposta da Evolution). Os testes passam um falso.
     * @return array{enviadas:int, falhas:int, canceladas:int, interrompidas:int}
     */
    public static function processarMensagens(?callable $enviar = null, ?string $agora = null): array
    {
        $agora  = $agora ?? TaskEntrega::agoraLocal();
        $enviar = $enviar ?? static fn(array $cfg, string $inst, string $jid, string $txt): array
            => (new EvolutionApiService($cfg))->sendText($inst, $jid, $txt);
        $pdo = Database::getConnection();
        $res = ['enviadas' => 0, 'falhas' => 0, 'canceladas' => 0, 'interrompidas' => 0];

        // 1. Tarefa que saiu da agenda (concluída, arquivada) ou lead excluído: não manda.
        $c = $pdo->prepare(
            "UPDATE crm_agendamentos a
               LEFT JOIN tasks t ON t.id = a.task_id
               LEFT JOIN cards c ON c.id = a.card_id
                SET a.envio_status = 'cancelada'
              WHERE a.envio_status = 'pendente'
                AND (t.id IS NULL OR t.status <> 'ativa' OR c.id IS NULL OR c.deleted_at IS NOT NULL)"
        );
        $c->execute();
        $res['canceladas'] = $c->rowCount();

        // 2. Envio que começou e não terminou (worker morreu no meio): não se sabe se saiu.
        $i = $pdo->prepare(
            "SELECT id, account_id, card_id, criado_por_id FROM crm_agendamentos
              WHERE envio_status = 'enviando' AND updated_at < DATE_SUB(NOW(), INTERVAL " . self::ENVIANDO_MAX_MIN . " MINUTE)"
        );
        $i->execute();
        foreach ($i->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            self::falhou($r, 'O envio foi interrompido. Confira a conversa antes de mandar de novo.');
            $res['interrompidas']++;
        }

        // 3. As que chegaram na hora.
        $st = $pdo->prepare(
            "SELECT a.*, t.prazo, t.titulo
               FROM crm_agendamentos a
               JOIN tasks t       ON t.id = a.task_id AND t.status = 'ativa'
               JOIN task_boards b ON b.id = t.board_id AND b.account_id = a.account_id
              WHERE a.envio_status = 'pendente' AND t.prazo <= ?
              ORDER BY t.prazo
              LIMIT 30"
        );
        $st->execute([$agora]);

        $inst = new WhatsAppInstance();
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $a) {
            // Pega a linha antes de mandar: dois workers juntos não mandam duas vezes.
            $pega = $pdo->prepare("UPDATE crm_agendamentos SET envio_status = 'enviando', envio_tentativas = envio_tentativas + 1
                                    WHERE id = ? AND envio_status = 'pendente'");
            $pega->execute([$a['id']]);
            if ($pega->rowCount() !== 1) continue;

            $acc   = (int) $a['account_id'];
            $canal = (int) $a['canal_id'];
            $jid   = (string) $a['remote_jid'];
            $ch    = WhatsAppChannelAccessService::check($pdo, $acc, $canal, 'send');
            if (!$ch || $jid === '') {
                self::falhou($a, 'O número de WhatsApp da conta não está mais disponível.');
                $res['falhas']++;
                continue;
            }

            try {
                $row = $inst->find($canal);
                $cfg = WhatsAppInstance::aplicarCanal($inst->getSettings((int) $ch['owner_account_id']), is_array($row) ? $row : []);
                $r   = $enviar($cfg, (string) $ch['instance_name'], $jid, (string) $a['mensagem']);
            } catch (\Throwable $e) {
                error_log('[agenda_crm] envio ' . $a['id'] . ': ' . $e->getMessage());
                $r = ['_error' => 'exception'];
            }

            if (!empty($r['_error']) || (int) ($r['_http'] ?? 0) >= 400) {
                error_log('[agenda_crm] falha envio ' . $a['id'] . ' http=' . (int) ($r['_http'] ?? 0)
                    . ' ' . substr((string) ($r['_error'] ?? ($r['message'] ?? '')), 0, 200));
                self::falhou($a, (string) $ch['status'] !== 'open'
                    ? 'O WhatsApp da conta está desconectado.'
                    : 'O WhatsApp não aceitou a mensagem.');
                $res['falhas']++;
                continue;
            }

            $wamid = $r['key']['id'] ?? ($r['id'] ?? null);
            $pdo->prepare("UPDATE crm_agendamentos SET envio_status = 'enviada', enviada_em = NOW(), wamid = ?, envio_erro = NULL WHERE id = ?")
                ->execute([$wamid ? mb_substr((string) $wamid, 0, 120) : null, $a['id']]);
            $res['enviadas']++;
            self::depoisDeEnviar($a, $ch, $wamid);
        }
        return $res;
    }

    /** O que acontece depois de a mensagem sair. Nada aqui desfaz o envio se falhar. */
    private static function depoisDeEnviar(array $a, array $ch, ?string $wamid): void
    {
        $acc   = (int) $a['account_id'];
        $canal = (int) $a['canal_id'];
        $jid   = (string) $a['remote_jid'];
        $autor = (int) $a['criado_por_id'] ?: null;
        $passos = [
            'mensagem' => function () use ($ch, $canal, $jid, $wamid, $a) {
                (new WhatsAppMessage())->save([
                    'account_id'      => (int) $ch['owner_account_id'] ?: null,
                    'instance_id'     => $canal,
                    'wamid'           => $wamid,
                    'remote_jid'      => $jid,
                    'contact_name'    => null,
                    'phone'           => preg_replace('/[^0-9]/', '', explode('@', $jid)[0]),
                    'message_type'    => 'text',
                    'message_content' => (string) $a['mensagem'],
                    'direction'       => 'outbound',
                    'status'          => 'sent',
                    'created_at'      => date('Y-m-d H:i:s'),
                ]);
                (new WhatsAppInstance())->bumpEvents($canal);
            },
            'sdr' => function () use ($ch, $canal, $jid, $autor) {
                $dono = (int) $ch['owner_account_id'];
                if (SdrFleetiflow::contaUsa($dono)) SdrFleetiflow::pessoaAssumiu($dono, $canal, $jid, $autor);
            },
            'interacao' => function () use ($acc, $a, $autor) {
                Interacao::registrar(
                    ['entidade' => 'card', 'id' => (int) $a['card_id'], 'account_id' => $acc, 'titulo' => ''],
                    ['tipo' => 'whatsapp', 'direcao' => 'saida', 'assunto' => 'Mensagem programada enviada',
                     'conteudo' => (string) $a['mensagem'], 'ocorrido_em' => TaskEntrega::agoraLocal()],
                    $autor
                );
            },
            'tarefa' => function () use ($a, $autor) {
                Task::complete((int) $a['task_id'], (int) $autor);
            },
        ];
        foreach ($passos as $nome => $passo) {
            try { $passo(); } catch (\Throwable $e) { error_log("[agenda_crm] depois de enviar ({$nome}) {$a['id']}: " . $e->getMessage()); }
        }
    }

    private static function falhou(array $a, string $motivo): void
    {
        Database::getConnection()->prepare("UPDATE crm_agendamentos SET envio_status = 'falhou', envio_erro = ? WHERE id = ?")
            ->execute([mb_substr($motivo, 0, 255), $a['id']]);
        $para = (int) ($a['criado_por_id'] ?? 0);
        if ($para <= 0) return;
        Aviso::paraUsuario((int) $a['account_id'], $para, [
            'tipo'         => 'agenda',
            'titulo'       => 'A mensagem programada não saiu',
            'mensagem'     => $motivo . ' Abra o lead para mandar de novo.',
            'entidade'     => 'card',
            'entidade_id'  => (int) $a['card_id'],
            'url'          => '/prospeccao.php?open=' . (int) $a['card_id'],
            'chave_dedupe' => 'agenda-falha:' . $a['id'],
            'janela_min'   => 30,
        ]);
    }

    // ── Apoio ────────────────────────────────────────────────────────────────

    /** Quadro criado na primeira vez que a conta agenda sem ter quadro nenhum. */
    private static function criarQuadroDaAgenda(int $accountId, int $userId): int
    {
        $id = TaskBoard::create([
            'nome' => 'Agenda comercial', 'tipo' => 'compartilhado',
            'owner_id' => $userId, 'account_id' => $accountId, 'cor' => '#015DFC',
        ]);
        self::criarColunas($id);
        return $id;
    }

    /** Colunas de um quadro vazio. Devolve a inicial. */
    private static function criarColunas(int $boardId): int
    {
        $inicial = TaskColumn::create(['board_id' => $boardId, 'nome' => 'A fazer', 'cor' => '#94a3b8', 'is_coluna_inicial' => 1]);
        TaskColumn::create(['board_id' => $boardId, 'nome' => 'Em andamento', 'cor' => '#3b82f6']);
        TaskColumn::create(['board_id' => $boardId, 'nome' => 'Concluído', 'cor' => '#22c55e', 'is_coluna_concluido' => 1]);
        return $inicial;
    }

    /** "2026-10-12T14:30" / "2026-10-12 14:30" → "2026-10-12 14:30:00". */
    public static function momento(string $v): ?string
    {
        $v = trim(str_replace('T', ' ', $v));
        foreach (['Y-m-d H:i', 'Y-m-d H:i:s'] as $f) {
            $d = \DateTimeImmutable::createFromFormat('!' . $f, $v);
            if ($d && $d->format($f) === $v) return $d->format('Y-m-d H:i:00');
        }
        return null;
    }

    private static function dataBr(string $dt): string
    {
        return date('d/m', strtotime($dt)) . ' às ' . substr($dt, 11, 5);
    }

    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
    }
}
