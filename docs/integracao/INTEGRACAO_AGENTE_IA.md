# Assistente Virtual de Pré-Atendimento Jurídico — Documentação

Implementação do agente de IA que faz a recepção e triagem de contatos no WhatsApp,
**reutilizando a única instância da Evolution já conectada** (premissa absoluta). Branch
`feature/ai-intake-agent`. Migration **097**. NÃO deployado (aguarda aprovação).

> Skill de desenvolvimento relacionada: `.claude/skills/yuris-legal-intake-optimizer/`.

---

## 1. Premissa ZERO — uma única instância da Evolution

O agente é uma **camada de automação** sobre a conexão WhatsApp existente. Não cria
instância, QR, token, webhook nem tabela de credenciais próprios. Ele referencia o canal por
`agent_configs.whatsapp_instance_id → whatsapp_instances.id`. As credenciais da Evolution
continuam em `whatsapp_settings` (por conta), geridas só pelo módulo WhatsApp/Master.

Evidência no código: o painel (`public/agente.php` + `public/api/agent_settings.php`) **não**
tem campo de URL/Admin Key/API Key da instância/QR/criar instância. O scanner
`.claude/skills/yuris-legal-intake-optimizer/scripts/validate_evolution_architecture.py`
falha (exit≠0) se algum desses anti-padrões aparecer no fluxo do agente.

## 2. Fluxo

### Entrada
```
Cliente → WhatsApp → instância Evolution existente
  → public/api/whatsapp/webhook.php (messages.upsert)
      • resolve conta/canal (WhatsAppInstance::findAccountByApiKey → findOrCreate)
      • idempotência: UNIQUE (instance_id, wamid) + checagem de novo inbound
      • ignora fromMe, grupos, broadcast, reações, status
      • persiste em whatsapp_messages + atualiza whatsapp_chats
  → maybeQueueAgentReply()  (só enfileira: carrega agent_config do canal, decifra a chave)
  → [HTTP 200 + flushResponse()]
  → runAgentReply()  →  App\WhatsAppAgente\AiIntake\IntakeEngine::handleInbound()
```

### Saída
```
IntakeEngine (1 chamada de IA) → reply text
  → EvolutionApiService::sendText (settings do DONO do canal, MESMA instância)
  → grava o wamid enviado no ledger (ai_intake_messages.origin='bot') p/ anti-loop
  → WhatsApp → Cliente
```

### Atendimento humano (takeover)
```
Humano envia manual (Yuris/celular)  OU  clica "Assumir conversa"
  → whatsapp_chats.agent_paused = 1 + ai_intake_sessions.controller_mode='human_takeover'
  → bot silencioso na conversa (mesma instância, mesma conversa, não transfere número)
```

## 3. Divisão de responsabilidade (motor)

- **Modelo (OpenAI)**: intenção, classificação de área, extração, urgência, sugestão da
  próxima chave de pergunta, flags de handoff, resumo — em **Structured Output** (JSON).
- **Backend (`IntakeEngine`)**: autoriza, resolve sessão, monta prompt, valida saída,
  controla a máquina de estados, escolhe o **texto** da próxima pergunta (não repete),
  aplica o limite (3 a 8), decide o handoff efetivo, monta a resposta, cria card/tarefa/
  notificação, registra uso e audita.

`app/WhatsAppAgente/AiIntake/`: `IntakeEngine`, `IntakeSchema`, `Taxonomy`, `IntakeStateMachine`,
`IntakeSessionRepository`, `HandoffService`, `LlmProviderInterface`, `OpenAiProvider`,
`AnthropicProvider`, `FakeProvider` (testes).

## 4. Banco de dados (migration 097)

- `agent_configs` **estendido**: `status, model, max_questions, office_name,
  office_description, office_information_json, behavior_json, handoff_config_json,
  usage_limits_json, initial_message, closing_message, urgency_message, handoff_message,
  retention_days, prompt_version_id`. (Continua 1 agente por canal: UNIQUE
  `whatsapp_instance_id`.)
- `ai_area_catalog` — catálogo global de áreas (35 áreas, seed CNJ/OAB). Expansível.
- `ai_intake_areas` — áreas habilitadas por agente (+ responsável + prioridade).
- `ai_intake_sessions` — uma sessão por conversa (estado, intent, área, urgência,
  collected_data, summary, prospect_id, task_id, expires_at...).
- `ai_intake_messages` — metadados + **ledger de origem** (bot/human_user/system/...) +
  wamid para correlação anti-loop.
- `ai_intake_handoffs` — registros de encaminhamento.
- `ai_usage_log` — tokens/custo por chamada (limite mensal + relatório).
- `ai_prompts` — prompts versionados (global ou por conta). Seed v1
  `pre_atendimento_universal`.

Aplicar local: `C:\xampp\php\php.exe database/migrations/run_097.php` (idempotente).
Prod: `docker exec -i yuris_app php /var/www/html/database/migrations/run_097.php`.

## 5. Endpoints

- `public/api/whatsapp/webhook.php` — **reutilizado** (único). Encaminha ao motor.
- `public/api/agent_settings.php` — GET (config + catálogo + áreas do agente) / POST (salva
  config rica + sincroniza áreas). owner/admin + CSRF. NÃO expõe credenciais.
- `public/api/whatsapp/agent_instances.php` — lista canais vinculáveis (read-only, sem
  credenciais).
- `public/agente.php` + `public/assets/agent.js` — painel (seleciona canal existente,
  define identidade/escritório/comportamento/áreas/encaminhamento/IA). Sem conexão Evolution.

## 6. Structured Output (schema)

Fonte de verdade: `App\WhatsAppAgente\AiIntake\IntakeSchema::jsonSchema()` (strict da OpenAI:
`additionalProperties:false`, todos os campos em `required`, sem min/max — validados no
backend). Campos: `intent, primary_practice_area, secondary_practice_areas,
classification_confidence, answer_relevant_to_current_question, extracted_data{...},
urgency_level, urgency_reasons, risk_flags, missing_essential_fields,
suggested_next_question_key, enough_for_handoff, should_handoff_immediately, should_stop_bot,
summary`. Sem Chain-of-Thought.

## 7. Prompt de produção

`ai_prompts.template` (v1, TIDD-EC). O backend injeta só: config do tenant, áreas
habilitadas, estado resumido, perguntas feitas, dados coletados, pergunta atual, mensagem
nova, schema. NÃO envia: histórico completo, todas as áreas, skills, CoT, credenciais,
dados de outros clientes. A parte estável vai primeiro (prompt caching automático da OpenAI).

## 8. OpenAI

`OpenAiProvider`: Chat Completions + `response_format` `json_schema` `strict:true`. Default
**gpt-4o-mini** (econômico, suporta Structured Outputs). temperatura 0, max_tokens ~800,
timeout 45s, retry com backoff em 429/5xx, checa `message.refusal` antes de parsear. Chave do
LLM: `agent_configs.api_key_enc` cifrada AES-256-GCM (`APP_ENCRYPTION_KEY`), nunca devolvida.
Sem chave global no `.env`; é por tenant. (Gemini fica fora: não responde via Structured
Outputs por cURL puro.)

## 9. Evolution v2

Eventos relevantes confirmados na doc v2. `messages.upsert` é o gatilho de mensagem nova;
`messages.update` (status), `connection.update`, `qrcode.updated` (módulo WhatsApp),
`contacts.update`, `chats.upsert` NÃO acionam o agente. Texto vem em
`message.conversation` ou `message.extendedTextMessage.text`. `sendText` devolve `key.id`,
usado para correlacionar a saída do bot (anti-loop). `fromMe` da Evolution é historicamente
não confiável → a filtragem é feita na aplicação (ledger por wamid + comparação de conteúdo).

## 10. Anti-loop e idempotência

- Inbound dedup: UNIQUE `(instance_id, wamid)` em `whatsapp_messages` + `inboundAlreadyProcessed`
  na camada IA (`ai_intake_messages`).
- Saída do bot: grava o `wamid` enviado com `origin='bot'`. Quando o eco (fromMe) volta,
  `isBotEcho(wamid)` reconhece e ignora (não reprocessa, não pausa, não responde de novo).
- Envio manual humano (fromMe, não-eco): pausa o bot (human takeover).
- Efeitos do handoff (card/tarefa) idempotentes por sessão (reusa `prospect_id`/`task_id`).

## 11. Matriz / filial / grants

O agente é selecionado **pela instância** (1 por canal). O bot responde sempre como o **dono
do canal** (número conectado). Escopo de canais vinculáveis no painel:
`getAccessibleAccountIds() ∪ getPipelineAccountId()`. Compartilhamento matriz→filial respeita
`WhatsAppChannelAccessService` + flag `WHATSAPP_SHARED_CHANNELS_ENABLED` (camada já existente).

## 12. Permissões

O Yuris modela acesso por **role** (`users.role`: owner/admin/manager/user/viewer), sem
tabela de abilities. Configurar/ativar o agente = owner/admin (`isOwnerOrAdmin`). Ver sessões/
receber handoff = responsável atribuído + escopo do tenant. (As "permissões ai_intake.*" do
prompt mestre mapeiam para esses papéis; não foi criada tabela de permissões para não criar um
sistema paralelo.)

## 13. LGPD e segurança

- Minimização: coleta só o necessário para triagem; mídia não é enviada à OpenAI (só
  confirma recebimento).
- Sem CoT armazenado. Chave do LLM cifrada e mascarada. Erros logados server-side
  (ErrorReporter), genéricos ao cliente.
- Isolamento multi-tenant: tudo escopado por conta/canal resolvidos no backend.
- Auditoria: `Account::audit` em config (`agent_settings.updated`) e handoff
  (`ai_intake.handoff`). Uso de IA em `ai_usage_log`.
- Anti prompt-injection: mensagens do cliente são dados, não comandos; o bot não revela
  prompt/regras, não muda de papel nem o formato de saída.
- `retention_days` em `agent_configs` (default 180) para retenção de sessões. **Pendência:**
  cobrir `ai_intake_sessions/messages` no Anonymizer e num tick de retenção (ver Pendências).

## 14. Custos (estimativa)

gpt-4o-mini ~US$0,15/1M input e US$0,60/1M output. Com prompt ~1.7k tokens input + ~350
output por mensagem e ~6 interações/atendimento: ~US$3 por 1.000 atendimentos. Limite mensal
por tenant em `usage_limits_json.monthly_cost_limit` (circuit breaker → handoff). Valores são
estimativa; ajustar conforme a tabela vigente.

## 15. Erros / fallback

Provider falho/refusal/JSON inválido → o motor encaminha para humano (handoff) com mensagem
configurada; nunca trava o cliente; registra `ai_usage_log.success=0`. Canal desconectado →
agente não ativa (409 `CHANNEL_DISCONNECTED` no painel) e não responde (gating por
`wi.status='open'`).

## 16. Testes

- `scripts/ai_intake_smoke.php` — 22 checagens de mecânica (sessão, estados, anti-loop,
  idempotência, takeover, mídia, injection, handoff idempotente, card real criado e limpo).
- `scripts/ai_intake_eval.php` — 56 cenários de conversa (todas as áreas, urgências, humano,
  spam, injection, extração, mídia) + modos de falha (provider, limite) — 180/180 asserts.
  Rodam contra o **motor real** com `FakeProvider` (determinístico, sem rede).
- Skill: `scripts/run_conversation_evals.py` (sim Evolution) + validadores de prompt/schema.

Rodar: `C:\xampp\php\php.exe scripts/ai_intake_smoke.php && C:\xampp\php\php.exe scripts/ai_intake_eval.php`.

## 17. Deploy (NÃO executado — requer aprovação)

1. `git push origin feature/ai-intake-agent` e merge em `main` (após revisão).
2. `git -C /home/ubuntu/Yuris-Sistema- pull --ff-only`.
3. Migration: `docker exec -i yuris_app php /var/www/html/database/migrations/run_097.php`.
4. `docker exec yuris_app apache2ctl graceful`.
5. Configurar um agente: página **Agente de IA** → escolher canal conectado → provider
   `openai` + chave + áreas + comportamento → Ativo. (A chave do LLM é do cliente.)

Containers Evolution/n8n **não são tocados**. Nenhuma segunda instância é criada.

## 18. Rollback

- Código: `git checkout <commit-anterior> -- public/api/whatsapp/webhook.php
  public/api/agent_settings.php public/agente.php public/assets/agent.js` + `apache2ctl graceful`.
- Dados: as tabelas `ai_*` e as colunas novas de `agent_configs` são aditivas; podem ficar
  (inertes) ou ser removidas com um runner de rollback. Desativar rápido: `UPDATE agent_configs
  SET enabled=0` (o webhook não dispara o agente sem `enabled=1`).

## 19. Troubleshooting

- Bot não responde: confira `agent_configs.enabled=1`, `whatsapp_instances.status='open'`,
  `api_key_enc` decifrável (provider openai/anthropic), conversa não `agent_paused`.
- Loop de mensagens: verifique se `attachWamid` está gravando o `key.id` do envio
  (ai_intake_messages.origin='bot').
- Sem card no handoff: a conta precisa de pelo menos uma coluna em `pipeline_columns`
  (ou `handoff_config.column_id`); senão o card é pulado (logado), o handoff/notificação
  seguem.
- Custo alto: ajuste `usage_limits_json` (max_tokens, monthly_cost_limit) por agente.

## 20. Pendências (próxima iteração)

- E2E real com OpenAI (chave) + Evolution viva (não exercitável localmente; lógica delegada
  testada com FakeProvider).
- Anonymizer + tick de retenção para `ai_intake_sessions/messages` (campo `retention_days` já
  existe).
- Transcrição de áudio (hoje a mídia só é confirmada; o áudio não é transcrito).
- UI de leitura de sessões/handoffs e relatório de consumo no painel (dados já persistidos em
  `ai_intake_sessions`/`ai_usage_log`).
