<?php
namespace App\WhatsAppAgente\AiIntake;

require_once __DIR__ . '/AgentEvent.php';

/**
 * IntakeSessionRepository — persistencia de sessoes/mensagens/uso do agente (tabelas ai_*).
 *
 * Encapsula o acesso ao DB para o IntakeEngine. Sempre escopado por account_id/channel_id
 * resolvidos no backend. Faz a correlacao anti-loop (ledger de wamid de origem 'bot').
 */
final class IntakeSessionRepository
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo) { $this->pdo = $pdo; }

    /** Colunas JSON da sessao (encode/decode automatico). */
    private const JSON_COLS = [
        'secondary_areas_json','urgency_reasons_json','asked_questions_json',
        'collected_data_json','mentioned_documents_json',
    ];

    /** Sessao ativa (nao encerrada) mais recente da conversa, ou null. */
    public function findActiveSession(int $channelId, string $remoteJid): ?array
    {
        $st = $this->pdo->prepare(
            "SELECT * FROM ai_intake_sessions
              WHERE channel_id = ? AND remote_jid = ? AND closed_at IS NULL
              ORDER BY id DESC LIMIT 1"
        );
        $st->execute([$channelId, $remoteJid]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    public function createSession(array $f): int
    {
        $f['created_at'] = $f['created_at'] ?? date('Y-m-d H:i:s');
        $f['last_message_at'] = $f['last_message_at'] ?? date('Y-m-d H:i:s');
        foreach (self::JSON_COLS as $c) {
            if (isset($f[$c]) && is_array($f[$c])) $f[$c] = json_encode($f[$c], JSON_UNESCAPED_UNICODE);
        }
        $cols = array_keys($f);
        $ph   = array_map(fn($c) => ':' . $c, $cols);
        $sql  = "INSERT INTO ai_intake_sessions (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")";
        $st   = $this->pdo->prepare($sql);
        foreach ($f as $k => $v) $st->bindValue(':' . $k, $v);
        $st->execute();
        return (int)$this->pdo->lastInsertId();
    }

    public function updateSession(int $id, array $f): void
    {
        if (!$f) return;
        foreach (self::JSON_COLS as $c) {
            if (array_key_exists($c, $f) && is_array($f[$c])) $f[$c] = json_encode($f[$c], JSON_UNESCAPED_UNICODE);
        }
        $sets = implode(', ', array_map(fn($c) => "$c = :$c", array_keys($f)));
        $st = $this->pdo->prepare("UPDATE ai_intake_sessions SET $sets WHERE id = :__id");
        foreach ($f as $k => $v) $st->bindValue(':' . $k, $v);
        $st->bindValue(':__id', $id, \PDO::PARAM_INT);
        $st->execute();
    }

    /** Idempotencia da camada IA: a mensagem inbound (wamid) ja foi processada nesta sessao? */
    public function inboundAlreadyProcessed(int $sessionId, ?string $wamid): bool
    {
        if (!$wamid) return false;
        $st = $this->pdo->prepare(
            "SELECT 1 FROM ai_intake_messages WHERE session_id = ? AND wamid = ? AND direction = 'inbound' LIMIT 1"
        );
        $st->execute([$sessionId, $wamid]);
        return (bool)$st->fetchColumn();
    }

    /**
     * Grava o inbound. IDEMPOTENTE: com o UNIQUE (session_id, wamid, direction) da migration 103,
     * um webhook duplicado do mesmo wamid nesta sessao faz o INSERT falhar e retorna 0 (o engine
     * trata 0 como "ja processado" e nao dispara o LLM de novo). wamid nulo nunca colide (varios NULL).
     */
    public function recordInbound(int $accountId, int $sessionId, ?string $wamid, ?string $content, ?string $type): int
    {
        try {
            return $this->insertMessage([
                'account_id' => $accountId, 'session_id' => $sessionId, 'wamid' => $wamid,
                'direction' => 'inbound', 'origin' => 'unknown', 'content' => $content,
                'message_type' => $type, 'ai_called' => 0,
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000' || stripos($e->getMessage(), 'uk_intake_msg') !== false) return 0;
            throw $e;
        }
    }

    /** 4C (debounce): id da mensagem inbound (usuário) mais recente da sessão. 0 se nenhuma. */
    public function latestUserInboundId(int $sessionId): int
    {
        $st = $this->pdo->prepare("SELECT COALESCE(MAX(id),0) FROM ai_intake_messages WHERE session_id = ? AND direction = 'inbound'");
        $st->execute([$sessionId]);
        return (int)$st->fetchColumn();
    }

    /**
     * 4C (agregação de rajada): concatena o TEXTO das mensagens do usuário ainda não
     * respondidas (desde a última saída do bot) nesta sessão, em ordem. Assim, ao agregar
     * uma rajada, nenhuma mensagem é descartada. null se não houver texto novo.
     */
    public function unansweredUserText(int $sessionId): ?string
    {
        $st = $this->pdo->prepare("SELECT COALESCE(MAX(id),0) FROM ai_intake_messages WHERE session_id = ? AND direction = 'outbound'");
        $st->execute([$sessionId]);
        $lastOut = (int)$st->fetchColumn();
        $st = $this->pdo->prepare(
            "SELECT content FROM ai_intake_messages
              WHERE session_id = ? AND direction = 'inbound' AND id > ?
                AND (message_type = 'text' OR message_type IS NULL)
                AND content IS NOT NULL AND content <> ''
              ORDER BY id ASC"
        );
        $st->execute([$sessionId, $lastOut]);
        $parts = $st->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        if (!$parts) return null;
        return implode("\n", array_map('strval', $parts));
    }

    public function recordBotSent(int $accountId, int $sessionId, ?string $wamid, ?int $waMsgId, ?string $content, ?array $structured, array $usage = [], ?int $latency = null, bool $aiCalled = true): int
    {
        return $this->insertMessage([
            'account_id' => $accountId, 'session_id' => $sessionId, 'wamid' => $wamid,
            'whatsapp_message_id' => $waMsgId, 'direction' => 'outbound', 'origin' => 'bot',
            'content' => $content, 'message_type' => 'text', 'ai_called' => $aiCalled ? 1 : 0,
            'structured_result_json' => $structured ? json_encode($structured, JSON_UNESCAPED_UNICODE) : null,
            'input_tokens' => $usage['input_tokens'] ?? null,
            'output_tokens' => $usage['output_tokens'] ?? null,
            'latency_ms' => $latency,
        ]);
    }

    public function recordSystem(int $accountId, int $sessionId, ?string $content): int
    {
        return $this->insertMessage([
            'account_id' => $accountId, 'session_id' => $sessionId, 'direction' => 'outbound',
            'origin' => 'system_template', 'content' => $content, 'message_type' => 'text', 'ai_called' => 0,
        ]);
    }

    /** Atualiza o wamid/whatsapp_message_id de uma mensagem do bot apos o envio (correlacao). */
    public function attachWamid(int $aiMessageId, ?string $wamid, ?int $waMsgId): void
    {
        $st = $this->pdo->prepare("UPDATE ai_intake_messages SET wamid = ?, whatsapp_message_id = ? WHERE id = ?");
        $st->execute([$wamid, $waMsgId, $aiMessageId]);
    }

    /**
     * Anti-loop: o wamid recebido no webhook foi enviado pelo proprio bot NESTE canal?
     * B6 (auditoria): escopa por channel_id (via sessao) — wamid repetido entre tenants
     * nao gera falso positivo de eco cross-tenant no anti-loop.
     */
    public function isBotEcho(int $channelId, ?string $wamid): bool
    {
        if (!$wamid) return false;
        $st = $this->pdo->prepare(
            "SELECT 1 FROM ai_intake_messages m
               JOIN ai_intake_sessions s ON s.id = m.session_id
              WHERE m.wamid = ? AND m.origin = 'bot' AND s.channel_id = ? LIMIT 1"
        );
        $st->execute([$wamid, $channelId]);
        return (bool)$st->fetchColumn();
    }

    public function recordUsage(array $f): void
    {
        $f['created_at'] = date('Y-m-d H:i:s');
        $f['total_tokens'] = $f['total_tokens'] ?? (($f['input_tokens'] ?? 0) + ($f['output_tokens'] ?? 0));
        $cols = array_keys($f);
        $sql = "INSERT INTO ai_usage_log (" . implode(',', $cols) . ") VALUES (" . implode(',', array_map(fn($c) => ":$c", $cols)) . ")";
        $st = $this->pdo->prepare($sql);
        foreach ($f as $k => $v) $st->bindValue(':' . $k, $v);
        $st->execute();
    }

    /** Custo de IA do mes corrente para a conta (limite mensal). */
    public function monthlyCost(int $accountId): float
    {
        $st = $this->pdo->prepare(
            "SELECT COALESCE(SUM(estimated_cost),0) FROM ai_usage_log
              WHERE account_id = ? AND created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')"
        );
        $st->execute([$accountId]);
        return (float)$st->fetchColumn();
    }

    /**
     * ESCRITOR ÚNICO de whatsapp_chats.agent_paused (+ auditoria de quem/quando). 4C (Onda 4):
     * pauseForHuman, resumeBot e o handoff do engine passam TODOS por aqui, então a suíte de
     * invariantes garante que nada mais escreve agent_paused fora do repositório (fim da
     * dessincronia pausa↔sessão que gerou o P0). $paused=true pausa (grava paused_by/at);
     * false retoma (zera os dois). agent_paused_by/at vieram na migration 107.
     */
    public function setChatPaused(int $channelId, string $remoteJid, bool $paused, ?int $userId = null): void
    {
        try {
            if ($paused) {
                $st = $this->pdo->prepare(
                    "UPDATE whatsapp_chats SET agent_paused = 1, agent_paused_by = ?, agent_paused_at = NOW()
                      WHERE instance_id = ? AND remote_jid = ?"
                );
                $st->execute([$userId, $channelId, $remoteJid]);
            } else {
                $st = $this->pdo->prepare(
                    "UPDATE whatsapp_chats SET agent_paused = 0, agent_paused_by = NULL, agent_paused_at = NULL
                      WHERE instance_id = ? AND remote_jid = ?"
                );
                $st->execute([$channelId, $remoteJid]);
            }
        } catch (\Throwable $_) { /* colunas 107 podem faltar em ambiente antigo */ }
    }

    /**
     * Pausa o bot por atendimento humano (mesma conversa). Seta a sessão para human_takeover
     * e espelha no chat pelo ESCRITOR ÚNICO (setChatPaused, que grava paused_by/at).
     */
    public function pauseForHuman(int $channelId, string $remoteJid, ?int $userId): bool
    {
        $sess = $this->findActiveSession($channelId, $remoteJid);
        $sid  = $sess ? (int)$sess['id'] : null;
        if ($sess) {
            $this->updateSession((int)$sess['id'], [
                'controller_mode' => 'human_takeover',
                'status'          => 'awaiting_human',
                'assigned_user_id'=> $userId ?: $sess['assigned_user_id'],
                'current_state'   => 'human_takeover',
            ]);
        }
        $this->setChatPaused($channelId, $remoteJid, true, $userId);
        AgentEvent::log($this->pdo, 'paused', ['by' => $userId], 'info', null, $sid, $channelId);
        return (bool)$sess;
    }

    /**
     * Devolve a conversa ao bot (inverso de pauseForHuman). Reativa a sessao ativa
     * (controller_mode/current_state) e zera agent_paused via o ESCRITOR ÚNICO. SEM isso,
     * uma conversa que passou por takeover/handoff fica presa em human_takeover/terminal e o
     * bot nunca mais responde. Nunca reabre sessao encerrada (findActiveSession exige closed_at NULL).
     */
    public function resumeBot(int $channelId, string $remoteJid): bool
    {
        $sess = $this->findActiveSession($channelId, $remoteJid);
        $sid  = $sess ? (int)$sess['id'] : null;
        if ($sess && (string)($sess['controller_mode'] ?? '') !== 'bot_active') {
            // Estado nao-terminal para o gating do engine (isPaused=false + isTerminal=false).
            $this->updateSession((int)$sess['id'], [
                'controller_mode' => 'bot_active',
                'status'          => 'active',
                'current_state'   => 'collecting_minimum_data',
            ]);
        }
        $this->setChatPaused($channelId, $remoteJid, false);
        AgentEvent::log($this->pdo, 'resumed', [], 'info', null, $sid, $channelId);
        return (bool)$sess;
    }

    /** A conversa esta pausada (humano assumiu)? Checa sessao + agent_paused. */
    public function isPaused(int $channelId, string $remoteJid): bool
    {
        try {
            $st = $this->pdo->prepare("SELECT agent_paused FROM whatsapp_chats WHERE instance_id = ? AND remote_jid = ? LIMIT 1");
            $st->execute([$channelId, $remoteJid]);
            if ((int)$st->fetchColumn() === 1) return true;
        } catch (\Throwable $_) {}
        $sess = $this->findActiveSession($channelId, $remoteJid);
        return $sess && in_array($sess['controller_mode'] ?? '', ['human_takeover', 'bot_paused'], true);
    }

    private function insertMessage(array $f): int
    {
        $f['created_at'] = date('Y-m-d H:i:s');
        $cols = array_keys($f);
        $sql = "INSERT INTO ai_intake_messages (" . implode(',', $cols) . ") VALUES (" . implode(',', array_map(fn($c) => ":$c", $cols)) . ")";
        $st = $this->pdo->prepare($sql);
        foreach ($f as $k => $v) $st->bindValue(':' . $k, $v);
        $st->execute();
        return (int)$this->pdo->lastInsertId();
    }

    private function hydrate(array $row): array
    {
        foreach (self::JSON_COLS as $c) {
            if (isset($row[$c]) && is_string($row[$c])) {
                $dec = json_decode($row[$c], true);
                $row[$c] = is_array($dec) ? $dec : null;
            }
        }
        return $row;
    }
}
