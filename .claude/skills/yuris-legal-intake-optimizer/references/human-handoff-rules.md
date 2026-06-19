# Convivência bot ↔ humano e regras de takeover

Bot e atendente usam a **mesma conversa** e a **mesma instância**. Por isso o controle do
**modo da conversa** é explícito e fica no servidor.

## Estado real hoje (nomes reais)

- Coluna: `whatsapp_chats.agent_paused` (TINYINT, default 0). `1` = bot pausado naquela
  conversa.
- Endpoint: `public/api/whatsapp/agent_takeover.php` (POST, sessão + CSRF). Faz toggle por
  conversa, identificada por `chat_id` ou `instance_id`+`remote_jid` ou `remote_jid`.
  Escopo via `AccountContext::getAccessibleAccountIds()` + `getPipelineAccountId()`.
- Gate no webhook: `maybeQueueAgentReply()` consulta `agent_paused` e retorna cedo se a
  conversa estiver pausada (fail-safe: se a coluna faltar, trata como pausado).
- UI: botão "Assumir conversa" no header do chat (`public/chat.php` + `assets/chat.js`).

## Estados conceituais (mapeados para o real)

A v1 implementa o essencial com um booleano (`agent_paused`). A máquina de estados
conceitual completa, para evolução, está em `conversation-state-machine.md`:

| Conceitual | Significado | Hoje |
|---|---|---|
| `bot_active` | bot responde | `agent_paused = 0` + `agent_configs.enabled = 1` + canal `open` |
| `bot_paused` | bot temporariamente parado | `agent_paused = 1` |
| `human_takeover` | humano assumiu | `agent_paused = 1` (registrar quem/quando ao evoluir) |
| `awaiting_human` | bot encaminhou e aguarda humano | `agent_paused = 1` + flag de pendência (a criar) |
| `completed` | pré-atendimento concluído | encerrar sessão (a persistir) |
| `disabled` | bot desligado no canal | `agent_configs.enabled = 0` |

## Quando o humano assume

- **Pausar o bot imediatamente** (`agent_paused = 1`).
- Não enviar respostas automáticas nem continuar perguntas pendentes.
- Manter o estado e o resumo disponíveis.
- Ao evoluir: registrar **quem** assumiu e **data/hora**; permitir retomada só por ação
  autorizada.

## Quando o próprio bot encaminha para humano

- Marcar `awaiting_human` (pausar novas respostas automáticas).
- Notificar o responsável (usuário/equipe da config do agente).
- Preservar a conversa **na mesma instância** (não transferir para outro número, não criar
  conversa artificial).

## Sinal de "humano está atendendo"

- Decisão de produto a confirmar na implementação: tratar uma **mensagem manual enviada
  pelo advogado** na conversa como sinal de takeover e **pausar o bot** automaticamente.
  Hoje o takeover é por **botão** (decisão registrada do projeto). Se ligar o sinal
  automático por mensagem manual, garanta que `fromMe`/mensagens do próprio sistema não
  causem flapping (pausar/despausar em loop).

## Regra de ouro

Em `human_takeover`/`bot_paused`, **nenhuma automação**. O bot só volta quando a pausa é
explicitamente removida por quem tem acesso. Isso evita concorrência de mensagens (humano e
bot respondendo ao mesmo tempo no mesmo número).
