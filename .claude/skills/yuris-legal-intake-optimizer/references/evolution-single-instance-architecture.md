# Arquitetura: UMA ÚNICA INSTÂNCIA DA EVOLUTION (regra permanente)

Este documento registra a **premissa ZERO** do Assistente de Pré-Atendimento do Yuris. É
regra de projeto, não preferência. Leia antes de qualquer integração de canal.

## Regras permanentes

- **Uma instância por canal/número.** Cada conta conecta o WhatsApp uma vez, no módulo
  WhatsApp. Essa instância já conectada responde por toda a comunicação daquele canal.
- **Bot e humano usam a MESMA instância.** O mesmo número/sessão recebe e envia tanto o
  atendimento humano quanto as respostas automáticas.
- **O bot não tem credenciais próprias.** Sem token, sem URL, sem API key do agente. As
  credenciais ficam em `whatsapp_settings` (por `account_id`).
- **O bot não cria instância** e **não gera QR Code.** Criar/conectar/reconectar é do
  módulo WhatsApp (e, para provisionamento, do Painel Master). Nunca da tela do agente.
- **Webhook existente é reutilizado** (`public/api/whatsapp/webhook.php`). Não criar um
  webhook só para o agente.
- **Serviço de envio existente é reutilizado** (`app/WhatsAppAgente/EvolutionApiService.php`).
- **Serviço de mídia existente é reutilizado** (`public/api/whatsapp/media.php`).
- **Mensagens próprias são ignoradas** (`key.fromMe`), para não criar loop.
- **Eventos são idempotentes** (UNIQUE `(instance_id, wamid)` + checagem no webhook).
- **O bot é pausado no atendimento humano** (`whatsapp_chats.agent_paused`).
- **O canal é resolvido no backend** (`WhatsAppChannelAccessService`), nunca pelo front.
- **Matriz e filial dependem de autorização real** (grant ativo + vínculo), não de ID.
- **Credenciais nunca são expostas** (a API do agente nunca devolve api_key/url/token/QR).
- **A conexão do WhatsApp permanece administrada no módulo WhatsApp.**

## O que o agente PODE guardar

Apenas referência segura, em `agent_configs`:
`whatsapp_instance_id` (FK → `whatsapp_instances.id`, UNIQUE por canal), `provider`,
`api_key_enc` (chave do LLM, cifrada; **não** é credencial de WhatsApp), `prompt`,
`enabled`, `branch_id`, `updated_by`. Conceito:

```
agent_configs.whatsapp_instance_id
  → whatsapp_instances.id            (o canal)
  → whatsapp_settings (do dono)      (evolution_base_url / evolution_api_key / evolution_instance)
  → Evolution API                    (transporte)
```

## É PROIBIDO criar

- instância separada "do bot" ou "de automação";
- segundo QR Code / segunda sessão / segundo token para o mesmo número;
- segundo registro de instância só para o bot;
- conexão paralela com a Evolution;
- webhook duplicado para as mesmas mensagens;
- tabelas como `bot_whatsapp_instances`, `ai_whatsapp_instances`,
  `agent_evolution_instances`, `bot_evolution_credentials`, `bot_whatsapp_connections`;
- qualquer credencial da Evolution dentro da configuração do agente.

`scripts/validate_evolution_architecture.py` procura esses anti-padrões e falha (exit ≠ 0)
em risco crítico.

## Diagramas de fluxo

### Entrada (cliente → Yuris)

```
Cliente
  → WhatsApp
  → Instância Evolution já existente
  → Webhook Yuris (public/api/whatsapp/webhook.php)
       • valida evento (messages.upsert / send_message)
       • resolve conta+canal (findAccountByApiKey → findOrCreate)
       • idempotência (UNIQUE instance_id+wamid; ignora duplicado)
       • ignora fromMe e eventos de status/sync/reação
       • persiste a mensagem (whatsapp_messages) e atualiza a conversa (whatsapp_chats)
  → Dispatcher interno (maybeQueueAgentReply)
       • só mensagem individual de texto, inbound, nova
       • só se NÃO estiver em human_takeover (agent_paused = 0)
       • seleciona agente PELA instância (enabled=1 AND wi.status='open')
  → Motor do agente (enfileirado em $GLOBALS['__agent_tasks'])
  → Estado da conversa
```

### Saída (agente → cliente)

```
Motor do agente (runAgentReply, após HTTP 200 + flushResponse)
  → LLM (OpenAI/Anthropic) com prompt universal + estado + schema
  → Serviço de envio existente (EvolutionApiService::sendText)
  → Instância Evolution já existente (settings do dono, mesma do canal)
  → WhatsApp
  → Cliente
```

### Atendimento humano (takeover)

```
Usuário humano assume a conversa (botão "Assumir conversa")
  → POST public/api/whatsapp/agent_takeover.php
  → whatsapp_chats.agent_paused = 1
  → Bot pausado (maybeQueueAgentReply retorna cedo)
  → Respostas do humano saem pelo MESMO serviço de envio e MESMA instância
  → Mesma conversa (não transfere para outro número, não cria conversa nova)
  → Liberar: agent_paused = 0 (somente por ação autorizada)
```

## Resultado arquitetural esperado

Uma conta conecta o número **uma vez**. A instância já conectada responde por todo o canal.
O administrador ativa o assistente e **escolhe esse canal existente**. Quando um cliente
escreve, o webhook atual recebe e o Yuris decide: manter no humano, iniciar/continuar
pré-atendimento automático, encaminhar para humano, ou ignorar. As respostas do bot saem
pela **mesma instância**. Quando o humano assume, o bot pausa. Em nenhum momento se cria
outro WhatsApp para o bot.
