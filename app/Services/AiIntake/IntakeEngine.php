<?php
namespace App\Services\AiIntake;

require_once __DIR__ . '/IntakeSchema.php';
require_once __DIR__ . '/Taxonomy.php';
require_once __DIR__ . '/IntakeStateMachine.php';
require_once __DIR__ . '/IntakeSessionRepository.php';
require_once __DIR__ . '/LlmProviderInterface.php';
require_once __DIR__ . '/HandoffService.php';

/**
 * IntakeEngine — orquestrador do pre-atendimento. UMA chamada de IA por mensagem.
 *
 * Divisao de responsabilidade (secao 9 do prompt mestre):
 *  - MODELO (provider): intencao, classificacao de area, extracao, urgencia, sugestao da
 *    proxima chave de pergunta, flags de handoff, resumo (Structured Output).
 *  - BACKEND (este engine): autoriza, resolve sessao, monta o prompt, valida a saida,
 *    controla a maquina de estados, escolhe o TEXTO da proxima pergunta, NAO repete,
 *    aplica o limite, decide o handoff efetivo, monta a resposta, registra uso e audita.
 *
 * NAO cria conexao/credencial/QR. A resposta sai pela MESMA instancia (o webhook envia via
 * EvolutionApiService e devolve o wamid para correlacao anti-loop).
 */
final class IntakeEngine
{
    private \PDO $pdo;
    private LlmProviderInterface $provider;
    private IntakeSessionRepository $repo;
    private ?string $promptTemplate = null;

    /** Perguntas POR AREA (2o mundo), carregadas sob demanda da tabela ai_area_questions. */
    private array  $areaQ      = [];   // ['area:<key>' => 'texto da pergunta']
    private array  $areaQOrder = [];   // ['area:<key>', ...] na ordem (sort)
    private ?string $areaQFor  = null; // area_code ja carregado (cache de 1 area por request)

    /** Preco estimado por 1M tokens (input, output) — atualizavel. Valores aproximados. */
    private const COST = [
        // OpenAI
        'gpt-4o-mini'  => [0.15, 0.60],
        'gpt-4.1-mini' => [0.40, 1.60],
        'gpt-5.4-mini' => [0.75, 4.50],
        'gpt-5.4-nano' => [0.20, 1.25],
        // Anthropic (D3: sem estes, um modelo Anthropic caia no preco do gpt-4o-mini e
        // subestimava o custo 5x-100x, corrompendo o circuit breaker mensal de custo).
        'claude-3-5-haiku-20241022'  => [0.80, 4.00],
        'claude-3-5-haiku'           => [0.80, 4.00],
        'claude-haiku-4-5'           => [1.00, 5.00],
        'claude-3-5-sonnet-20241022' => [3.00, 15.00],
        'claude-3-5-sonnet'          => [3.00, 15.00],
        'claude-sonnet-4-5'          => [3.00, 15.00],
    ];

    /** Banco de perguntas (texto deterministico por chave; copy Yuris, sem travessao). */
    private const QUESTIONS = [
        'nome'       => 'Qual o seu nome, por favor?',
        'motivo'     => 'Poderia me contar, brevemente, o que aconteceu?',
        'quando'     => 'Quando isso aconteceu?',
        'processo'   => 'Já existe algum processo sobre esse assunto?',
        'prazo'      => 'Existe algum prazo, intimação ou audiência marcada?',
        'documentos' => 'Você tem algum documento relacionado ao caso?',
        'cidade'     => 'Em qual cidade isso aconteceu?',
        'area'       => 'Sobre qual área jurídica você precisa de ajuda?',
    ];

    public function __construct(\PDO $pdo, LlmProviderInterface $provider)
    {
        $this->pdo = $pdo;
        $this->provider = $provider;
        $this->repo = new IntakeSessionRepository($pdo);
    }

    public function repo(): IntakeSessionRepository { return $this->repo; }

    /**
     * Processa uma mensagem inbound do cliente. Retorna a decisao + texto a enviar.
     * O webhook envia a resposta pela MESMA instancia e chama repo()->attachWamid(...).
     */
    public function handleInbound(array $cfg, int $channelId, string $remoteJid, ?int $contactId, string $userText, string $messageType, ?string $wamid): array
    {
        $accountId = (int)$cfg['account_id'];
        $agentId   = (int)$cfg['id'];
        $maxQ      = max(3, min(8, (int)($cfg['max_questions'] ?? 6)));

        // 1) Sessao ativa (ou cria)
        $session = $this->repo->findActiveSession($channelId, $remoteJid);
        $firstTurn = false;
        if (!$session) {
            $this->repo->createSession([
                'account_id' => $accountId, 'agent_id' => $agentId, 'channel_id' => $channelId,
                'remote_jid' => $remoteJid, 'contact_id' => $contactId, 'status' => 'active',
                'controller_mode' => 'bot_active', 'current_state' => 'new', 'question_count' => 0,
                'collected_data_json' => IntakeSchema::defaults()['extracted_data'],
                'asked_questions_json' => [],
            ]);
            $session = $this->repo->findActiveSession($channelId, $remoteJid);
            $firstTurn = true;
        }
        $sid = (int)$session['id'];

        // 2) Pausado (humano assumiu) ou terminal -> bot silencioso (so registra)
        if ($this->repo->isPaused($channelId, $remoteJid) || IntakeStateMachine::isTerminal((string)($session['current_state'] ?? ''))) {
            $this->repo->recordInbound($accountId, $sid, $wamid, $userText, $messageType);
            return $this->silent($sid, 'paused_or_terminal');
        }

        // 3) Idempotencia ATOMICA da camada IA: grava o inbound; se o wamid ja foi processado
        //    nesta sessao, o UNIQUE (uk_intake_msg, migration 103) faz o INSERT retornar 0 e o
        //    bot fica silencioso — sem disparar o LLM 2x sob webhooks concorrentes/replay.
        $inboundId = $this->repo->recordInbound($accountId, $sid, $wamid, $userText, $messageType);
        if ($inboundId === 0 && $wamid !== null && $wamid !== '') {
            return $this->silent($sid, 'duplicate');
        }

        $collected = is_array($session['collected_data_json'] ?? null)
            ? $session['collected_data_json'] : IntakeSchema::defaults()['extracted_data'];
        $asked  = is_array($session['asked_questions_json'] ?? null) ? $session['asked_questions_json'] : [];
        $qcount = (int)($session['question_count'] ?? 0);

        // 4) Midia (nao-texto): confirma recebimento, NAO chama IA, nao envia a OpenAI (secao 22)
        if ($messageType !== 'text') {
            $collected['has_documents'] = true;
            $collected['mentioned_documents'] = array_values(array_unique(array_merge($collected['mentioned_documents'] ?? [], [$messageType])));
            $reply = ($messageType === 'audio')
                ? 'Recebi o seu áudio. Por aqui não consigo analisar o conteúdo, mas vou registrá-lo para a equipe avaliar. Se possível, descreva por escrito do que se trata.'
                : 'Recebi o seu arquivo e vou registrá-lo para a equipe analisar.';
            $this->repo->updateSession($sid, ['collected_data_json' => $collected, 'last_message_at' => date('Y-m-d H:i:s')]);
            $aiMsgId = $this->repo->recordSystem($accountId, $sid, $reply);
            return $this->reply($sid, $aiMsgId, $reply, false, null, 'media_ack');
        }

        // 5) Circuit breaker de custo mensal
        $limits = $this->jdec($cfg['usage_limits_json'] ?? null);
        $monthLimit = (float)($limits['monthly_cost_limit'] ?? 0);
        if ($monthLimit > 0 && $this->repo->monthlyCost($accountId) >= $monthLimit) {
            return $this->doHandoff($cfg, $session, $collected, IntakeSchema::defaults(), $contactId, $remoteJid, $channelId, true, 'limit_reached');
        }

        // 6) Monta prompt + chama o modelo (1 chamada)
        $enabled = $this->enabledAreas($agentId);
        $system  = $this->buildSystemPrompt($cfg, $session, $enabled, $collected, $asked, $userText);
        $opts = [
            'max_tokens' => (int)($limits['max_tokens'] ?? 800),
            'timeout'    => (int)($limits['timeout'] ?? 45),
            'temperature'=> 0,
            'context'    => [ // usado pelo FakeProvider em testes
                'collected' => $collected, 'asked' => $asked, 'question_count' => $qcount,
                'max_questions' => $maxQ, 'enabled_areas' => array_column($enabled, 'code'),
            ],
        ];
        $model = (string)($cfg['model'] ?: 'gpt-4o-mini');
        // Prompt caching: chave de roteamento estavel por agente+modelo (o prefixo do prompt e
        // identico entre as mensagens do mesmo agente). Sem dado dinamico na chave.
        $opts['cache_key'] = 'yuris:' . $model . ':a' . $agentId;
        $res = $this->provider->complete($model, $system, $userText, IntakeSchema::responseFormat(), $opts);

        // registra uso (sempre) — custo ja credita os tokens servidos do cache da OpenAI
        $cost = $this->cost($model, $res['usage']['input_tokens'] ?? 0, $res['usage']['output_tokens'] ?? 0, $res['usage']['cached_input_tokens'] ?? 0);
        $this->repo->recordUsage([
            'account_id' => $accountId, 'agent_id' => $agentId, 'session_id' => $sid,
            'provider' => $this->provider->name(), 'model' => $model, 'operation' => 'intake',
            'input_tokens' => $res['usage']['input_tokens'] ?? 0, 'output_tokens' => $res['usage']['output_tokens'] ?? 0,
            'cached_input_tokens' => $res['usage']['cached_input_tokens'] ?? 0,
            'estimated_cost' => $cost, 'success' => $res['ok'] ? 1 : 0,
            'error' => $res['error'] ? substr($res['error'], 0, 240) : null, 'latency_ms' => $res['latency_ms'] ?? null,
        ]);

        // 7) Falha/recusa -> fallback handoff (nao trava o cliente)
        if (!$res['ok']) {
            if ($res['error']) error_log('[ai_intake] provider error: ' . $res['error']);
            return $this->doHandoff($cfg, $session, $collected, IntakeSchema::defaults(), $contactId, $remoteJid, $channelId, false, $res['refused'] ? 'refused' : 'provider_error');
        }

        // 8) Valida + coerce
        $structured = IntakeSchema::coerce($res['data']);
        $errs = IntakeSchema::validate($structured);
        if ($errs) error_log('[ai_intake] schema warnings: ' . implode('; ', array_slice($errs, 0, 5)));

        // 9) Override server-side de urgencia critica (defesa)
        $srv = Taxonomy::detectUrgency($userText);
        if ($srv['level'] === 'critical' && $structured['urgency_level'] !== 'critical') {
            $structured['urgency_level'] = 'critical';
            $structured['urgency_reasons'] = array_values(array_unique(array_merge($structured['urgency_reasons'], $srv['reasons'])));
            $structured['should_handoff_immediately'] = true;
            $structured['enough_for_handoff'] = true;
        }

        // 10) Mescla dados coletados (guarda se ja havia relato/nome, p/ empatia e nome so 1x)
        $hadReport = !empty($collected['main_report']);
        $hadName   = !empty($collected['name']);
        $collected = $this->mergeExtracted($collected, $structured['extracted_data']);

        // Area classificada -> carrega o 2o mundo (perguntas especificas da area), 1x por request.
        $areaCode = $structured['primary_practice_area'] ?? null;
        $this->ensureAreaQuestions($areaCode);

        // 11) Decisao de controle (BACKEND)
        $intent   = $structured['intent'];
        $critical = $structured['urgency_level'] === 'critical';
        $existingCase = ($intent === 'existing_case');
        $humanReq     = ($intent === 'human_request');
        $limitReached = $qcount >= $maxQ;

        // PRE-QUALIFICACAO MINIMA antes de encaminhar: o pre-atendimento faz ao menos ~3
        // perguntas (sem exagerar) p/ qualificar o caso (o que houve, area provavel, quando...).
        // Sinais "soft" (o modelo achou suficiente OU a pessoa pediu humano de forma generica)
        // so encaminham DEPOIS dessa qualificacao, ou quando nao ha mais nada essencial a
        // perguntar. Urgencia critica, cliente existente e limite de perguntas SEMPRE encaminham.
        $qualMin   = max(2, min(3, $maxQ));
        $qualified = ($qcount >= $qualMin) || ($this->pickNextKey($collected, $asked, $areaCode) === null);
        $softHandoff = $humanReq
            || !empty($structured['should_handoff_immediately'])
            || !empty($structured['enough_for_handoff']);
        $doHandoff = $critical || $existingCase || $limitReached || ($softHandoff && $qualified);

        $baseUpd = [
            'collected_data_json' => $collected,
            'intent' => $intent,
            'primary_area' => $structured['primary_practice_area'],
            'secondary_areas_json' => $structured['secondary_practice_areas'],
            'classification_confidence' => $structured['classification_confidence'],
            'urgency_level' => $structured['urgency_level'],
            'urgency_reasons_json' => $structured['urgency_reasons'],
            'summary' => $structured['summary'],
            'last_message_at' => date('Y-m-d H:i:s'),
        ];

        // Spam / nao-juridico -> encerra com cordialidade
        if ($intent === 'non_legal') {
            $reply = 'Agradeço o seu contato. Este canal é destinado ao atendimento jurídico do escritório. Caso precise de ajuda jurídica, estou à disposição.';
            $this->repo->updateSession($sid, $baseUpd + ['status' => 'completed', 'current_state' => 'completed', 'closed_at' => date('Y-m-d H:i:s')]);
            $aiMsgId = $this->repo->recordBotSent($accountId, $sid, null, null, $reply, $structured, $res['usage'] ?? [], $res['latency_ms'] ?? null, true);
            return $this->reply($sid, $aiMsgId, $reply, false, $structured, 'out_of_scope');
        }

        // Informacoes do escritorio (sem handoff) -> responde com o cadastrado + convite
        if ($intent === 'office_information' && !$doHandoff) {
            $reply = $this->composeOfficeInfo($cfg);
            $this->repo->updateSession($sid, $baseUpd + ['current_state' => 'identifying_intent']);
            $aiMsgId = $this->repo->recordBotSent($accountId, $sid, null, null, $reply, $structured, $res['usage'] ?? [], $res['latency_ms'] ?? null, true);
            return $this->reply($sid, $aiMsgId, $reply, false, $structured, 'office_info');
        }

        // Handoff (imediato/critico/limite/suficiente/pedido humano), so apos a pre-qualificacao
        if ($doHandoff) {
            $reason = $limitReached ? 'limit_reached'
                    : ($existingCase ? 'existing_case'
                    : ($humanReq ? 'human_request'
                    : ($critical ? 'urgent' : 'qualified')));
            return $this->doHandoff($cfg, $session, $collected, $structured, $contactId, $remoteJid, $channelId, $critical, $reason, $res);
        }

        // Caso geral: no 1o turno SEMPRE se apresenta (escritorio + agente) e pede o nome.
        // Depois personaliza pelo primeiro nome do lead e nao repete a empatia.
        $leadFirst = $this->firstName($collected['name'] ?? '');
        $firstReportNow = empty($hadReport) && !empty($collected['main_report']); // empatia so 1x
        $nameJustGiven  = empty($hadName)   && !empty($collected['name']);        // nome citado so 1x

        if ($firstTurn) {
            // Abre com a saudacao do advogado (verbatim, se cadastrada) e JA engaja a
            // conversa (pede o nome ou acolhe o relato) — nunca deixa no vacuo apos o "oi".
            $key = empty($collected['name'])
                ? 'nome'
                : ($structured['suggested_next_question_key'] ?: $this->pickNextKey($collected, $asked, $areaCode));
            $reply = $this->composeFirstTurn($cfg, $collected, $leadFirst, $firstReportNow, $key);
            if ($key) { $asked[] = $key; } // marca; nao conta no limite no 1o turno
            $state = 'greeting';
        } else {
            // BACKEND decide a ordem (genericas + especificas da area, sem repetir). A sugestao do
            // modelo so entra como fallback quando ja nao ha nada essencial a perguntar.
            $key = $this->pickNextKey($collected, $asked, $areaCode);
            if ($key === null) {
                $sug = $structured['suggested_next_question_key'] ?: null;
                if ($sug && !in_array($sug, $asked, true) && $this->questionText($sug, '') !== '') $key = $sug;
            }
            if ($key) { $asked[] = $key; $qcount++; }
            $reply = $this->composeQuestion($cfg, $structured, $key, $collected, $leadFirst, $firstReportNow, $nameJustGiven);
            $state = IntakeStateMachine::decide((string)$session['current_state'], $structured, ['will_ask' => true]);
        }

        $this->repo->updateSession($sid, $baseUpd + [
            'asked_questions_json' => $asked, 'question_count' => $qcount,
            'current_state' => $state, 'current_question' => $key,
        ]);
        $aiMsgId = $this->repo->recordBotSent($accountId, $sid, null, null, $reply, $structured, $res['usage'] ?? [], $res['latency_ms'] ?? null, true);
        return $this->reply($sid, $aiMsgId, $reply, false, $structured, 'question');
    }

    // ─────────────────────────── Handoff ───────────────────────────
    private function doHandoff(array $cfg, array $session, array $collected, array $structured, ?int $contactId, string $remoteJid, int $channelId, bool $critical, string $reason, ?array $res = null): array
    {
        $accountId = (int)$cfg['account_id'];
        $sid = (int)$session['id'];

        // Efeitos (contato + card + tarefa + notificacao) idempotentes por sessao.
        $hand = HandoffService::run($this->pdo, $cfg, [
            'session_id' => $sid, 'channel_id' => $channelId, 'remote_jid' => $remoteJid,
            'contact_id' => $contactId, 'collected' => $collected, 'structured' => $structured,
            'reason' => $reason, 'existing_prospect_id' => $session['prospect_id'] ?? null,
            'existing_task_id' => $session['task_id'] ?? null,
        ]);

        $hOff = $this->firstName($collected['name'] ?? '');
        $hNm  = $hOff !== '' ? (', ' . $hOff) : '';
        $reply = $critical
            ? ($cfg['urgency_message'] ?: ('Entendi a urgência' . $hNm . '. Vou registrar com prioridade e já encaminhar para a nossa equipe. 🙏'))
            : ($cfg['handoff_message'] ?: 'Perfeito! 👍 Registrei as informações iniciais e vou encaminhar o seu atendimento para a nossa equipe dar continuidade.');
        // Fecho com o nome p/ conexao: so 1x, so em handoff NAO urgente, e se o nome ainda nao
        // aparecer no texto. Quebra de frase limpa (evita "." colado em emoji).
        if (!$critical && $hOff !== '' && mb_stripos($reply, $hOff) === false) {
            $reply = rtrim($reply);
            $sep = preg_match('/[\p{L}\p{N}]$/u', $reply) ? '. ' : ' ';
            $reply .= $sep . 'Obrigado pela preferência, ' . $hOff . '.';
        }

        $this->repo->updateSession($sid, [
            'collected_data_json' => $collected,
            'intent' => $structured['intent'] ?? ($session['intent'] ?? null),
            'primary_area' => $structured['primary_practice_area'] ?? ($session['primary_area'] ?? null),
            'urgency_level' => $structured['urgency_level'] ?? 'normal',
            'summary' => $structured['summary'] ?? ($session['summary'] ?? null),
            'status' => 'awaiting_human', 'controller_mode' => 'awaiting_human',
            'current_state' => 'awaiting_human',
            'assigned_user_id' => $hand['user_id'] ?? ($session['assigned_user_id'] ?? null),
            'prospect_id' => $hand['prospect_id'] ?? ($session['prospect_id'] ?? null),
            'task_id' => $hand['task_id'] ?? ($session['task_id'] ?? null),
            'last_message_at' => date('Y-m-d H:i:s'),
        ]);
        // pausa o bot na conversa (mesma instancia; humano segue) — espelha agent_paused
        try {
            $st = $this->pdo->prepare("UPDATE whatsapp_chats SET agent_paused = 1 WHERE instance_id = ? AND remote_jid = ?");
            $st->execute([$channelId, $remoteJid]);
        } catch (\Throwable $_) {}

        $aiMsgId = $this->repo->recordBotSent($accountId, $sid, null, null, $reply, $structured, $res['usage'] ?? [], $res['latency_ms'] ?? null, true);
        return $this->reply($sid, $aiMsgId, $reply, true, $structured, 'handoff:' . $reason);
    }

    // ─────────────────────────── Helpers de resposta ───────────────────────────
    /**
     * 1o turno SEM mensagem inicial cadastrada: saudacao automatica acolhedora (escritorio +
     * agente, com emoji) e pede o nome do lead. Se houver mensagem inicial do advogado, ela e
     * usada como esta (no handleInbound) e este metodo nao roda — preserva a liberdade dele.
     */
    private function composeFirstTurn(array $cfg, array $collected, string $leadFirst, bool $firstReportNow, ?string $key): string
    {
        $office = $cfg['office_name'] ?: 'nosso escritorio';
        $agent  = $this->firstName($cfg['name'] ?? '');
        // Abertura: a saudacao do advogado (verbatim) tem prioridade — nao alteramos o texto
        // dele. So quando NAO ha saudacao cadastrada montamos a automatica (escritorio + agente).
        if (!empty($cfg['initial_message'])) {
            $open = rtrim(trim((string)$cfg['initial_message']));
        } else {
            $open = 'Olá! 👋 Seja bem-vindo(a) ao ' . $office . '.'
                  . ($agent !== '' ? (' Aqui é ' . $agent . ', do pré-atendimento. 🙂') : '');
        }
        // Engajamento: SEMPRE puxa a conversa em seguida (pede o nome ou acolhe o relato).
        if (empty($collected['name'])) {
            return $open . ' Para começar, qual o seu nome, por favor?';
        }
        $nm = $leadFirst !== '' ? (', ' . $leadFirst) : '';
        if ($firstReportNow) {
            return $open . ' Sinto muito' . $nm . '. Imagino o quanto essa situação é difícil. 🙏 ' . $this->questionText($key, 'Poderia me contar o que aconteceu?');
        }
        return $open . ' Para começar' . $nm . ', poderia me contar o que aconteceu?';
    }

    /** Demais turnos: ack curto e personalizado (empatia so 1x), depois a pergunta. */
    private function composeQuestion(array $cfg, array $structured, ?string $key, array $collected, string $leadFirst = '', bool $firstReportNow = false, bool $nameJustGiven = false): string
    {
        $q      = $this->questionText($key, 'Poderia me dar mais detalhes sobre a sua situação?');
        $first  = $leadFirst;
        $urgent = (($structured['urgency_level'] ?? 'normal') !== 'normal');
        // O nome do lead e citado UMA unica vez (no turno em que ele se identifica) e a empatia
        // tambem so 1x (no turno do relato). No meio da conversa: SO a pergunta, sem "certo" e
        // sem repetir o nome — objetividade. O fecho com nome volta no handoff.
        if ($nameJustGiven && $first !== '') {
            $ack = $firstReportNow
                ? ('Sinto muito, ' . $first . '. Imagino o quanto essa situação é difícil. 🙏 ')  // nome + empatia, 1x
                : ('Perfeito, ' . $first . '! ');
        } elseif ($urgent) {
            $ack = 'Entendi a situação. ';
        } elseif ($firstReportNow) {
            $ack = 'Sinto muito. Imagino o quanto essa situação é difícil. 🙏 ';
        } else {
            $ack = '';
        }
        return trim($ack . $q);
    }

    private function questionText(?string $key, string $fallback): string
    {
        if ($key === null || $key === '') return $fallback;
        if (isset(self::QUESTIONS[$key])) return self::QUESTIONS[$key];   // generica
        if (isset($this->areaQ[$key]))    return $this->areaQ[$key];      // especifica da area
        return $fallback;
    }

    // ─────────────────────────── Perguntas por area (2o mundo) ───────────────────────────
    /**
     * Carrega (1x por request, cache por area) as perguntas especificas da area classificada.
     * Best-effort: se a tabela nao existir ou a area nao tiver perguntas, o motor segue so com
     * as perguntas genericas. As chaves sao prefixadas com "area:" p/ nao colidir com as genericas.
     */
    private function ensureAreaQuestions(?string $areaCode): void
    {
        $areaCode = $areaCode !== null ? trim($areaCode) : '';
        if ($this->areaQFor === $areaCode) return; // ja carregado p/ essa area
        $this->areaQFor   = $areaCode;
        $this->areaQ      = [];
        $this->areaQOrder = [];
        if ($areaCode === '') return;
        try {
            $st = $this->pdo->prepare(
                "SELECT question_key, question_text FROM ai_area_questions
                  WHERE area_code = ? AND active = 1 ORDER BY sort ASC, id ASC"
            );
            $st->execute([$areaCode]);
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $k = 'area:' . $row['question_key'];
                $this->areaQ[$k]      = (string)$row['question_text'];
                $this->areaQOrder[]   = $k;
            }
        } catch (\Throwable $_) { /* tabela ausente: sem perguntas de area */ }
    }

    /** Chaves (prefixadas) das perguntas da area, na ordem; [] se area desconhecida. */
    private function areaQuestionKeys(?string $areaCode): array
    {
        $this->ensureAreaQuestions($areaCode);
        return $this->areaQOrder;
    }

    /** Primeiro nome do lead, capitalizado, para personalizar a conversa. */
    private function firstName(?string $full): string
    {
        $full = trim((string)$full);
        if ($full === '') return '';
        $parts = preg_split('/\s+/', $full);
        $f = $parts[0] ?? '';
        return $f === '' ? '' : (mb_strtoupper(mb_substr($f, 0, 1)) . mb_substr($f, 1));
    }

    private function composeOfficeInfo(array $cfg): string
    {
        $info = $this->jdec($cfg['office_information_json'] ?? null);
        $parts = [];
        if (!empty($cfg['office_description'])) $parts[] = (string)$cfg['office_description'];
        if (!empty($info['horario']))  $parts[] = 'Horário de atendimento: ' . $info['horario'] . '.';
        if (!empty($info['telefone'])) $parts[] = 'Telefone: ' . $info['telefone'] . '.';
        if (!empty($info['endereco'])) $parts[] = 'Endereço: ' . $info['endereco'] . '.';
        if (!empty($info['site']))     $parts[] = 'Site: ' . $info['site'] . '.';
        $txt = $parts ? implode(' ', $parts) : 'Posso ajudar com o pré-atendimento jurídico do escritório.';
        return $txt . ' Existe alguma situação jurídica em que possamos ajudar?';
    }

    // ─────────────────────────── Prompt / contexto ───────────────────────────
    private function getPromptTemplate(): string
    {
        if ($this->promptTemplate !== null) return $this->promptTemplate;
        try {
            $st = $this->pdo->query("SELECT template FROM ai_prompts WHERE name='pre_atendimento_universal' AND active=1 ORDER BY id DESC LIMIT 1");
            $tpl = $st ? $st->fetchColumn() : '';
        } catch (\Throwable $_) { $tpl = ''; }
        $this->promptTemplate = $tpl ?: 'Voce e {{agent_name}}, assistente virtual de pre-atendimento de {{office_name}}. Faca triagem, nao advogue. Responda somente com o JSON do schema.';
        return $this->promptTemplate;
    }

    private function buildSystemPrompt(array $cfg, array $session, array $enabled, array $collected, array $asked, string $userText): string
    {
        $tpl = $this->getPromptTemplate();
        $areaNames = implode(', ', array_map(fn($a) => $a['name'], $enabled)) ?: 'todas as areas do direito';
        $vars = [
            '{{agent_name}}'      => $cfg['name'] ?: 'Assistente',
            '{{office_name}}'     => $cfg['office_name'] ?: 'o escritorio',
            '{{max_questions}}'   => (string)max(3, min(8, (int)($cfg['max_questions'] ?? 6))),
            '{{enabled_areas}}'   => $areaNames,
            '{{office_information}}' => json_encode($this->jdec($cfg['office_information_json'] ?? null) ?: new \stdClass(), JSON_UNESCAPED_UNICODE),
            '{{session_state}}'   => (string)($session['current_state'] ?? 'new'),
            '{{asked_questions}}' => json_encode($asked, JSON_UNESCAPED_UNICODE),
            '{{collected_data}}'  => json_encode($collected, JSON_UNESCAPED_UNICODE),
            '{{current_question}}'=> (string)($session['current_question'] ?? ''),
            '{{user_message}}'    => $userText,
        ];
        return strtr($tpl, $vars);
    }

    private function enabledAreas(int $agentId): array
    {
        try {
            $st = $this->pdo->prepare("SELECT code, COALESCE(name, code) AS name FROM ai_intake_areas WHERE agent_id = ? AND enabled = 1 ORDER BY priority DESC, name ASC");
            $st->execute([$agentId]);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $_) { $rows = []; }
        if ($rows) return $rows;
        // fallback: todas as areas do catalogo
        return array_map(fn($a) => ['code' => $a['code'], 'name' => $a['name']], Taxonomy::areas());
    }

    // ─────────────────────────── util ───────────────────────────
    /**
     * Proxima chave de pergunta (DOIS MUNDOS): primeiro o relato (motivo), depois as perguntas
     * ESPECIFICAS da area classificada (qualificam o caso para o advogado) e por fim os demais
     * fatos genericos. Nunca repete uma pergunta ja feita ($asked). Sem area conhecida, cai so
     * nas genericas. O limite/pre-qualificacao continua sendo aplicado por quem chama.
     */
    private function pickNextKey(array $collected, array $asked, ?string $areaCode = null): ?string
    {
        $need = [];
        if (empty($collected['main_report']))         $need[] = 'motivo';
        // 2o mundo: perguntas da area, logo apos entender o que aconteceu.
        foreach ($this->areaQuestionKeys($areaCode) as $ak) $need[] = $ak;
        if (empty($collected['event_date']))          $need[] = 'quando';
        if (($collected['has_existing_case'] ?? null) === null) $need[] = 'processo';
        if (($collected['has_deadline'] ?? null) === null)     $need[] = 'prazo';
        if (($collected['has_documents'] ?? null) === null)    $need[] = 'documentos';
        if (empty($collected['city']))                $need[] = 'cidade';
        foreach ($need as $k) if (!in_array($k, $asked, true)) return $k;
        return null;
    }

    private function mergeExtracted(array $base, array $new): array
    {
        foreach ($new as $k => $v) {
            if ($k === 'mentioned_documents') {
                $base[$k] = array_values(array_unique(array_merge($base[$k] ?? [], is_array($v) ? $v : [])));
                continue;
            }
            if ($v !== null && $v !== '' && $v !== []) $base[$k] = $v;
        }
        return $base;
    }

    private function cost(string $model, int $in, int $out, int $cached = 0): float
    {
        $price = self::COST[$model] ?? null;
        if ($price === null) {
            // D3: preco desconhecido -> usa fallback, mas LOGA (1x por modelo). Sem isso o
            // custo era subestimado em silencio e o circuit breaker mensal ficava corrompido.
            static $warned = [];
            if (!isset($warned[$model])) {
                error_log('[ai_intake] preco desconhecido para o modelo "' . $model . '" — usando fallback gpt-4o-mini; adicione em IntakeEngine::COST');
                $warned[$model] = true;
            }
            $price = self::COST['gpt-4o-mini'];
        }
        [$pin, $pout] = $price;
        // OpenAI cobra input em cache pela METADE. Credita o que veio do cache.
        $cached   = max(0, min($cached, $in));
        $uncached = $in - $cached;
        return round($uncached / 1e6 * $pin + $cached / 1e6 * ($pin * 0.5) + $out / 1e6 * $pout, 6);
    }

    private function jdec($v): array
    {
        if (is_array($v)) return $v;
        if (is_string($v) && $v !== '') { $d = json_decode($v, true); return is_array($d) ? $d : []; }
        return [];
    }

    private function silent(int $sid, string $note): array
    {
        return ['should_send' => false, 'reply' => null, 'session_id' => $sid, 'ai_message_id' => null, 'handoff' => false, 'structured' => null, 'note' => $note];
    }

    private function reply(int $sid, ?int $aiMsgId, string $text, bool $handoff, ?array $structured, string $note): array
    {
        return ['should_send' => true, 'reply' => $text, 'session_id' => $sid, 'ai_message_id' => $aiMsgId, 'handoff' => $handoff, 'structured' => $structured, 'note' => $note];
    }
}
