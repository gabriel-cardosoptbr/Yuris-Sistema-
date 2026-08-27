<?php
namespace App\WhatsAppAgente;

use App\Core\Database;
use App\Core\Crypto;
use App\Usuarios\TotpHelper;

/**
 * WhatsAppAgentBridge — caminho do AGENTE IA (ALTA #5) do webhook da Evolution.
 *
 * Strangler do webhook.php (Onda 4 / 4D, Pass 3 = I3). As 5 funcoes globais do
 * caminho do agente foram movidas VERBATIM para ca:
 *   - maybeQueueAgentReply : gating + enfileira a tarefa em $GLOBALS['__agent_tasks']
 *   - maybeHandleHumanSend : distingue eco do bot de envio manual humano (takeover)
 *   - flushResponse        : fecha a conexao (200) antes de processar o LLM
 *   - decryptAgentApiKey   : decifra a api_key do agente (GCM novo / CBC legado)
 *   - runAgentReply        : chama o LLM + envia via EvolutionApiService::sendText
 *
 * Diferenca em relacao ao original e SO: (a) qualificacao de namespace (\PDO,
 * \WhatsAppInstance, \WhatsAppMessage, \EvolutionApiService sao globais; Database/Crypto/
 * TotpHelper via use; AiIntake\* e AiSettings ja vinham qualificados com \), (b) a chamada
 * interna decryptAgentApiKey -> self::decryptAgentApiKey e (c) os require_once LAZY tiveram
 * o path __DIR__ ajustado (a classe vive em app/WhatsAppAgente/, nao em public/api/whatsapp/).
 * Comportamento neutro.
 *
 * CONTRATOS PRESERVADOS (nao mexer sem reprovar equivalencia):
 *   - $GLOBALS['__agent_tasks'] continua sendo o canal entre maybeQueueAgentReply
 *     (produtor) e o corpo do webhook (consumidor, que chama flushResponse + runAgentReply).
 *     E superglobal: comporta-se identico dentro do metodo estatico.
 *   - flushResponse DEVE rodar ANTES de runAgentReply (a Evolution recebe o 200 e a conexao
 *     e liberada antes do cURL ao LLM). Essa ordem fica no corpo do webhook, intacta.
 *   - Anti-loop/idempotencia (isNewInbound no chamador, ledger ai_intake_messages, isBotEcho)
 *     e a selecao do agente PELA INSTANCIA (1 agente/canal) sao invariantes da skill.
 *
 * Prova de neutralidade: (a) diff normalizado vs HEAD (corpos byte-identicos exceto os 3
 * itens acima); (b) harness de equivalencia — deterministica p/ decryptAgentApiKey e em
 * nivel de banco p/ maybeQueueAgentReply/maybeHandleHumanSend (legado vs novo em transacoes
 * com rollback, comparando $GLOBALS['__agent_tasks'] e o estado de pausa); (c) guarda
 * estrutural em scripts/tests/wa_invariants.php. runAgentReply (LLM + envio reais) NAO e
 * automatizavel sem disparar mensagem de verdade -> validado AO VIVO (checklist no cofre).
 */
class WhatsAppAgentBridge
{
    /**
     * ALTA #5 — avalia se a mensagem recebida deve disparar o Agente IA e, em caso
     * afirmativo, enfileira a tarefa em $GLOBALS['__agent_tasks'] (não envia nada aqui).
     *
     * GUARDRAILS (revisados pelo dono):
     *   (a) responde só CHAT INDIVIDUAL — ignora grupos (@g.us);
     *   (b) fromMe já foi excluído pelo chamador (estamos em !$fromMe) — evita loop:
     *       a própria resposta do agente volta como fromMe=true e não reentra;
     *   (e) respeita o toggle: só dispara se houver agent_config com enabled=1.
     *   + só mensagens de TEXTO com conteúdo não-vazio.
     */
    public static function maybeQueueAgentReply(int $accountId, int $instanceId, ?string $remoteJid, string $msgType, ?string $msgContent, ?string $wamid = null, ?string $pushName = null): void
    {
        try {
            // (a) só chat individual — grupos terminam em @g.us
            if (!$remoteJid || str_ends_with($remoteJid, '@g.us')) return;
            // Ignora JIDs de sistema/broadcast (status@broadcast, newsletter etc.)
            if (str_ends_with($remoteJid, '@broadcast') || str_contains($remoteJid, '@newsletter')) return;
            // texto + midia (a midia vira confirmacao de recebimento no motor, sem chamar IA).
            $allowed = ['text', 'image', 'audio', 'document', 'video', 'sticker'];
            if (!in_array($msgType, $allowed, true)) return;
            $userText = trim((string)$msgContent);
            if ($msgType === 'text' && $userText === '') return;

            $pdo = Database::getConnection();

            // Takeover (botão "Assumir conversa" OU envio manual humano): se a conversa esta
            // pausada, o agente nao responde. agent_paused e por conversa (instance+jid).
            try {
                $stPause = $pdo->prepare(
                    'SELECT agent_paused FROM whatsapp_chats WHERE instance_id = ? AND remote_jid = ? LIMIT 1'
                );
                $stPause->execute([$instanceId, $remoteJid]);
                if ((int)$stPause->fetchColumn() === 1) return; // conversa assumida por humano
            } catch (\Throwable $_p) {
                error_log('[whatsapp/agent] agent_paused indisponível (migration 082?): ' . $_p->getMessage());
                return;
            }

            // FONTE ÚNICA DA VERDADE: seleciona o agente PELA INSTÂNCIA (1 agente por canal),
            // so dispara com o canal conectado (status=open). Carrega o config COMPLETO (config
            // rica da migration 097) para o motor de pre-atendimento.
            $st  = $pdo->prepare(
                'SELECT ac.*
                   FROM agent_configs ac
                   JOIN whatsapp_instances wi ON wi.id = ac.whatsapp_instance_id
                  WHERE ac.whatsapp_instance_id = ? AND ac.enabled = 1
                    AND wi.status = "open"
                  LIMIT 1'
            );
            $st->execute([$instanceId]);
            $cfg = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$cfg) return; // sem agente ativo conectado para este canal → não responde

            // Provider: o do agente, ou OpenAI por padrao (a chave OpenAI e GLOBAL, no Master).
            $provider = strtolower(trim((string)($cfg['provider'] ?? ''))) ?: 'openai';
            if (!in_array($provider, ['openai', 'anthropic'], true)) {
                error_log('[whatsapp/agent] provider não suportado para auto-resposta: ' . $provider);
                return;
            }

            // Chave: a do canal (agent_configs.api_key_enc) tem prioridade; senao, para OpenAI,
            // usa a Security Key GLOBAL cadastrada no Painel Master (vale p/ todas as instancias).
            $apiKey = self::decryptAgentApiKey($cfg['api_key_enc'] !== null ? (string)$cfg['api_key_enc'] : null);
            if (($apiKey === null || $apiKey === '') && $provider === 'openai') {
                require_once __DIR__ . '/../Master/AiSettings.php';
                $apiKey = \App\Master\AiSettings::openAiKey($pdo);
            }
            if ($apiKey === null || $apiKey === '') {
                error_log('[whatsapp/agent] sem chave de IA (nem por canal, nem global OpenAI) — auto-resposta abortada');
                return;
            }
            $cfg['provider'] = $provider; // provider resolvido segue no task

            // O trabalho pesado (1 chamada de IA + envio + efeitos) roda DEPOIS do 200, em
            // runAgentReply() -> IntakeEngine. Aqui so enfileiramos.
            $GLOBALS['__agent_tasks'][] = [
                'cfg'         => $cfg,            // config completa do agente (dono do canal)
                'api_key'     => $apiKey,
                'provider'    => $provider,
                'account_id'  => $accountId,
                'instance_id' => $instanceId,
                'remote_jid'  => $remoteJid,
                'user_text'   => $userText,
                'msg_type'    => $msgType,
                'wamid'       => $wamid,
                'push_name'   => $pushName,
            ];
        } catch (\Throwable $e) {
            // Nunca deixa a avaliação do agente derrubar o webhook.
            error_log('[whatsapp/agent] maybeQueueAgentReply falhou: ' . $e->getMessage());
        }
    }

    /**
     * Mensagem fromMe (saida): distingue o ECO do proprio bot do ENVIO MANUAL humano.
     *  - eco do bot (wamid no ledger ai_intake_messages.origin='bot', ou conteudo == ultima
     *    resposta do bot): ignora (anti-loop; nao reprocessa, nao pausa).
     *  - envio manual de um humano numa conversa com sessao ativa do agente: pausa o bot
     *    (human takeover na mesma conversa, mesma instancia).
     * NUNCA usa apenas fromMe para decidir (fromMe da Evolution e historicamente nao confiavel).
     */
    public static function maybeHandleHumanSend(int $accountId, int $instanceId, ?string $remoteJid, ?string $wamid, ?string $msgContent): void
    {
        try {
            if (!$remoteJid || str_ends_with($remoteJid, '@g.us') || str_ends_with($remoteJid, '@broadcast') || str_contains($remoteJid, '@newsletter')) return;
            require_once __DIR__ . '/AiIntake/IntakeSessionRepository.php';
            $pdo  = Database::getConnection();
            $repo = new \App\WhatsAppAgente\AiIntake\IntakeSessionRepository($pdo);

            // 1) eco do proprio bot por wamid (escopado por instancia) -> ignora
            if ($repo->isBotEcho($instanceId, $wamid)) return;

            // ha sessao ativa do agente nesta conversa?
            $sess = $repo->findActiveSession($instanceId, $remoteJid);
            if (!$sess) return;

            // 2) defesa extra (A2): o eco do bot pode chegar ANTES do attachWamid gravar o wamid
            //    (corrida entre o webhook do eco e o runAgentReply). Comparamos o texto com as
            //    ultimas respostas do bot por PREFIXO normalizado — o eco pode vir truncado em
            //    4096 e/ou com espacos normalizados; o match exato de antes falhava nesses casos.
            $norm = static fn($s) => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string)$s)));
            $b = $norm($msgContent);
            if ($b !== '') {
                $st = $pdo->prepare("SELECT content FROM ai_intake_messages WHERE session_id = ? AND origin = 'bot' ORDER BY id DESC LIMIT 3");
                $st->execute([(int)$sess['id']]);
                foreach (($st->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $last) {
                    $a = $norm($last);
                    if ($a === '') continue;
                    $min = min(mb_strlen($a), mb_strlen($b));
                    if ($min >= 24 && mb_substr($a, 0, $min) === mb_substr($b, 0, $min)) return; // eco do bot
                }
            }

            // 3) envio manual humano -> pausa o bot (se ainda nao pausado)
            if (!in_array($sess['controller_mode'] ?? '', ['human_takeover', 'bot_paused'], true)) {
                $repo->pauseForHuman($instanceId, $remoteJid, null);
                error_log('[whatsapp/agent] envio manual humano detectado -> bot pausado em ' . preg_replace('/\d{5,}/', '*****', (string)$remoteJid)); // F4: mascara telefone no log
            }
        } catch (\PDOException $e) {
            // A3 (auditoria): falha de banco (conectividade) NAO pode ser confundida com "sem
            // sessao ativa". Aqui o bot pode nao ter pausado -> risco de responder por cima do
            // humano. Loga com tag distinta (metrica/alerta), sem engolir silencioso.
            error_log('[whatsapp/agent] maybeHandleHumanSend ERRO DE BANCO (takeover pode nao ter pausado): ' . $e->getMessage());
        } catch (\Throwable $e) {
            error_log('[whatsapp/agent] maybeHandleHumanSend falhou: ' . $e->getMessage());
        }
    }

    /**
     * Garante que a resposta HTTP (200) já enviada chegue à Evolution e a conexão
     * seja LIBERADA antes de processarmos o LLM — para o webhook nunca travar (d).
     *
     *  • PHP-FPM: fastcgi_finish_request() devolve a resposta e libera o worker para
     *    o que vier depois (a Evolution recebe o 200 na hora).
     *  • mod_php / servidor embutido: fecha os buffers de saída na marra e marca
     *    ignore_user_abort(true) para o script continuar mesmo se a Evolution já
     *    tiver desconectado. set_time_limit(0) evita matar o processo no meio do
     *    envio (a chamada cURL tem timeout próprio e curto).
     */
    public static function flushResponse(): void
    {
        ignore_user_abort(true);
        @set_time_limit(0); // o cURL ao LLM tem timeout próprio e curto (15s)

        // Sinaliza fim de resposta ANTES de fechar — só funciona se headers não saíram.
        if (!headers_sent()) {
            @header('Connection: close');
            @header('Content-Length: ' . (string)ob_get_length());
        }

        if (function_exists('fastcgi_finish_request')) {
            // PHP-FPM: descarrega o buffer na resposta e libera o worker.
            @fastcgi_finish_request();
            return;
        }
        // Fallback sem FPM (mod_php / servidor embutido): empurra o buffer e fecha o que der.
        while (ob_get_level() > 0) { @ob_end_flush(); }
        @flush();
    }

    /**
     * Decifra a api_key do agente com compatibilidade de chave (espelha BAIXA #2 do
     * agent_settings.php): tenta o formato NOVO (Crypto / AES-256-GCM, "v1:…") e, se
     * falhar, cai para o LEGADO (TotpHelper / AES-256-CBC). Retorna null se nenhum
     * decifrar — o chamador então NÃO responde (em vez de quebrar).
     */
    public static function decryptAgentApiKey(?string $enc): ?string
    {
        if ($enc === null || $enc === '') return null;
        try {
            return Crypto::decrypt($enc);
        } catch (\Throwable $_) {
            try {
                return TotpHelper::decryptSecret($enc);
            } catch (\Throwable $_e) {
                return null;
            }
        }
    }

    /**
     * Executa a resposta automática: chama o LLM e, se obtiver texto, envia via
     * EvolutionApiService::sendText. TUDO em try/catch — guardrail (c)/(d): se o LLM
     * falhar/demorar, apenas loga e segue; nunca propaga exceção (já estamos depois
     * do 200, mas mantemos o processo saudável).
     *
     * @param array{account_id:int,instance_id:int,remote_jid:string,provider:string,api_key:string,prompt:string,user_text:string} $task
     */
    public static function runAgentReply(array $task): void
    {
        try {
            require_once __DIR__ . '/AiIntake/IntakeEngine.php';
            require_once __DIR__ . '/AiIntake/OpenAiProvider.php';
            require_once __DIR__ . '/AiIntake/AnthropicProvider.php';

            $apiKey   = (string)($task['api_key'] ?? '');
            $provider = ($task['provider'] === 'anthropic')
                ? new \App\WhatsAppAgente\AiIntake\AnthropicProvider($apiKey)
                : new \App\WhatsAppAgente\AiIntake\OpenAiProvider($apiKey);

            $pdo    = Database::getConnection();
            $engine = new \App\WhatsAppAgente\AiIntake\IntakeEngine($pdo, $provider);
            $cfg    = is_array($task['cfg'] ?? null) ? $task['cfg'] : [];

            // 1 chamada de IA + controle deterministico + efeitos (handoff) ficam no motor.
            $result = $engine->handleInbound(
                $cfg,
                (int)$task['instance_id'],
                (string)$task['remote_jid'],
                null,
                (string)($task['user_text'] ?? ''),
                (string)($task['msg_type'] ?? 'text'),
                $task['wamid'] ?? null
            );

            if (empty($result['should_send']) || empty($result['reply'])) return;
            $reply = (string)$result['reply'];
            if (mb_strlen($reply) > 4096) $reply = mb_substr($reply, 0, 4096);

            // Envia pela MESMA instancia — credenciais do DONO do canal (account do agent_config).
            $ownerAcc  = (int)($cfg['account_id'] ?? $task['account_id']);
            $instModel = new \WhatsAppInstance();
            $sett      = $instModel->getSettings($ownerAcc);
            $evo       = new \EvolutionApiService($sett);
            $name      = $sett['evolution_instance'] ?? 'yuris-crm';
            $resp      = $evo->sendText($name, (string)$task['remote_jid'], $reply);
            if (!empty($resp['_error'])) {
                error_log('[whatsapp/agent] sendText falhou: ' . $resp['_error']);
                return;
            }

            // Anti-loop: registra o wamid que o BOT acabou de enviar (ledger). Quando o eco
            // (fromMe) voltar pelo webhook, isBotEcho() reconhece e ignora.
            $wamidOut = $resp['key']['id'] ?? ($resp['id'] ?? null);
            if (!empty($result['ai_message_id']) && $wamidOut) {
                try { $engine->repo()->attachWamid((int)$result['ai_message_id'], (string)$wamidOut, null); }
                catch (\Throwable $_) {}
            }

            // TEMPO REAL: persiste a resposta do bot em whatsapp_messages JA (igual ao envio
            // manual em send.php), em vez de esperar o eco fromMe voltar pelo webhook. Isso faz
            // a mensagem do robo aparecer NA HORA no preview da lista (upsertChat) e dentro da
            // conversa aberta. Dedup por (instance_id, wamid): quando o eco chegar, o save()
            // encontra a linha e so atualiza o status (nao duplica).
            // created_at do outbound: usa o messageTimestamp da Evolution (mesmo relogio do
            // inbound) quando disponivel. Sem isso, o relogio do servidor (se adiantado)
            // gravaria a msg do bot "no futuro" e empurraria o cursor do poll a frente,
            // escondendo a proxima msg do cliente ate reentrar. Fallback: hora local.
            $evoTs     = $resp['messageTimestamp'] ?? null;
            $createdAt = (is_numeric($evoTs) && (int)$evoTs > 0) ? date('Y-m-d H:i:s', (int)$evoTs) : date('Y-m-d H:i:s');
            try {
                require_once __DIR__ . '/WhatsAppMessage.php';
                (new \WhatsAppMessage())->save([
                    'account_id'      => $ownerAcc,
                    'instance_id'     => (int)$task['instance_id'],
                    'wamid'           => $wamidOut,
                    'remote_jid'      => (string)$task['remote_jid'],
                    'message_type'    => 'text',
                    'message_content' => $reply,
                    'direction'       => 'outbound',
                    'status'          => 'sent',
                    'created_at'      => $createdAt,
                ]);
            } catch (\Throwable $_) { /* espelho local nao pode derrubar o envio ja feito */ }
        } catch (\Throwable $e) {
            // Não vaza detalhe; só log server-side (LGPD). Ja estamos depois do 200.
            error_log('[whatsapp/agent] runAgentReply falhou: ' . $e->getMessage());
        }
    }
}
