# Máquina de estados da conversa

Define os estados do pré-atendimento e as transições. **O estado é do BACKEND, não do modelo.**

**Fonte de verdade (código):**
- `app/WhatsAppAgente/AiIntake/IntakeStateMachine.php` — lista de estados (`STATES`), terminais
  (`TERMINAL`) e a transição `decide()`.
- `app/WhatsAppAgente/AiIntake/IntakeEngine.php` — orquestra o turno e grava o estado.
- `app/WhatsAppAgente/AiIntake/IntakeSessionRepository.php` — persistência (`ai_intake_sessions.current_state`,
  `controller_mode`) + pausa/takeover.

> O modelo **não** emite um campo de estado (não existe `conversation_state` no schema, ver
> `structured-output-schema.md`). O modelo fornece **sinais** (`intent`, `urgency_level`,
> `enough_for_handoff`, `should_handoff_immediately`, `should_stop_bot`, `extracted_data`,
> `primary_practice_area`, `suggested_next_question_key`); o `IntakeEngine` mapeia esses sinais
> para um estado **válido** e o grava em `ai_intake_sessions.current_state`. Transições críticas
> (handoff, encerramento, pausa) são decididas pelo backend.

## Estados (`IntakeStateMachine::STATES`)

| Estado | Significado |
|---|---|
| `new` | sessão recém-criada, antes do primeiro turno processado |
| `greeting` | primeiro contato; saudação/identificação como assistente virtual |
| `identifying_intent` | descobrindo a intenção (ex.: após info do escritório) |
| `collecting_initial_report` | coletando o relato inicial (`main_report` ainda vazio) |
| `identifying_area` | relato existe, mas falta classificar a área (`primary_practice_area`) |
| `collecting_minimum_data` | coletando os dados mínimos restantes (uma pergunta por vez) |
| `checking_urgency` | urgência `high`/`critical` detectada; priorizando |
| `ready_for_handoff` | estado definido para evolução; na v1 o engine vai direto a `awaiting_human` |
| `awaiting_human` | encaminhado; aguardando atendente (bot pausado, `agent_paused=1`) |
| `human_takeover` | humano assumiu a conversa (botão "Assumir conversa") |
| `completed` | pré-atendimento concluído / fora de escopo encerrado |
| `paused` | bot pausado por outro motivo |
| `expired` | sessão expirada |
| `disabled` | agente desligado no canal |
| `error` | falha tratada |

**Terminais** (`TERMINAL` — bot não atua mais sem ação explícita):
`awaiting_human`, `human_takeover`, `completed`, `disabled`, `expired`.

## Transição do backend (`IntakeStateMachine::decide`)

`decide($current, $structured, $decision)` resolve o próximo estado a partir do estado atual, dos
sinais do modelo (`$structured`) e da decisão do backend (`$decision`), nesta ordem:

```
out_of_scope (decisão backend)            → completed
handoff (decisão backend)                 → awaiting_human
intent == 'office_information' / office_info → identifying_intent
urgency_level != 'normal'                 → checking_urgency
extracted_data.main_report vazio          → collecting_initial_report
primary_practice_area vazio               → identifying_area
caso geral                                → collecting_minimum_data
```

## Fluxo por turno (`IntakeEngine::handleInbound`)

```
(sem sessão)            → cria sessão  current_state = 'new'
isPaused() OU terminal  → bot silencioso (só registra a inbound; não responde)
inbound duplicada       → silencioso (idempotência da camada IA)
mídia/áudio (não-texto) → confirma recebimento, marca has_documents; NÃO chama o modelo

(mensagem de texto, bot ativo) → 1 chamada ao modelo, depois:
  intent == 'non_legal'                  → completed   (encerra cordial; closed_at)
  intent == 'office_information' (s/ handoff) → identifying_intent (responde dados do escritório)
  handoff efetivo                        → awaiting_human + agent_paused = 1
  primeiro turno, sem relato, intent unknown → greeting (só saudação)
  caso geral                             → faz a próxima pergunta; estado = decide(...)
```

**Handoff efetivo** = `urgency_level == 'critical'` **ou** `intent ∈ {human_request,
existing_case}` **ou** `should_handoff_immediately` **ou** `enough_for_handoff` **ou** limite de
perguntas atingido (`max_questions`, 3..8). Detalhe e invariantes em `structured-output-schema.md`.

## Pausa e takeover (`controller_mode` + `agent_paused`)

A coluna `controller_mode` da sessão acompanha o estado: `bot_active`, `bot_paused`,
`awaiting_human`, `human_takeover`. O takeover humano (botão "Assumir conversa") chama
`pauseForHuman()`: grava `controller_mode = 'human_takeover'`, `current_state = 'human_takeover'`,
`status = 'awaiting_human'` e **espelha** `whatsapp_chats.agent_paused = 1` (gating legado do
webhook). Ver `human-handoff-rules.md`.

## Regras de transição

- **Uma pergunta por vez.** Cada turno em coleta faz no máximo uma pergunta; `question_count`
  incrementa e nunca passa de `max_questions` (3..8). Ao atingir o limite sem dados suficientes, o
  backend força o handoff (`awaiting_human`).
- **Não repetir pergunta** já feita: o engine checa `asked_questions`/`collected_data` e, se a
  `suggested_next_question_key` já foi usada, escolhe a próxima essencial (`pickNextKey`).
- **Urgência `high`/`critical`** acelera para `checking_urgency`/handoff mesmo sem todos os dados;
  `critical` pode ser **forçada no servidor** (`Taxonomy::detectUrgency`).
- **Encerramento**: `intent == 'non_legal'` → `completed`. Estados terminais e pausa fazem o bot
  ficar silencioso no próximo turno (só registra a mensagem).
- **Takeover** (humano assume a qualquer momento): `agent_paused = 1`; o modelo não gera novas
  respostas até a pausa ser removida por quem tem acesso.
- **Idempotência de efeitos**: criação de card/lead a partir do handoff acontece **uma vez por
  sessão** (`HandoffService` reaproveita `prospect_id`/`task_id`); inbound duplicada (mesmo `wamid`)
  não reprocessa.

## Persistência (já implementada)

O estado e o resumo são persistidos **por sessão** na tabela `ai_intake_sessions`
(`current_state`, `controller_mode`, `summary`, `collected_data_json`, `asked_questions_json`,
`question_count`, `assigned_user_id`, etc.), referenciando o canal (`channel_id`) e a conversa
(`remote_jid`). Isso permite retomar após takeover e evitar recomeçar do zero, respeitando a
premissa de **instância única**.
