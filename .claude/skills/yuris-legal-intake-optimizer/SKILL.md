---
name: yuris-legal-intake-optimizer
description: >-
  Skill de DESENVOLVIMENTO (nunca runtime) para projetar, revisar, otimizar e testar o
  Assistente Virtual de Pré-Atendimento Jurídico do Yuris no WhatsApp. Use SEMPRE que o
  trabalho envolver: prompt do assistente jurídico, pré-atendimento, classificação ou
  triagem de leads, fluxo conversacional, Structured Outputs/JSON do agente, redução de
  tokens e custo, segurança jurídica, prompt injection, encaminhamento para humano
  (takeover), testes do bot, regras do agente, webhook da Evolution, reutilização de
  instância/canal, idempotência, prevenção de loop (fromMe), resolução de canal,
  matriz/filial, grants de compartilhamento de canal, ou pausa do bot por atendimento
  humano. Ative mesmo que o usuário não diga "skill" ou "prompt": qualquer mexida no
  agente de IA do WhatsApp do Yuris (public/agente.php, public/api/agent_settings.php,
  public/api/whatsapp/webhook.php, agent_configs) deve consultar esta skill primeiro.
license: Proprietário (Inovaize / Yuris). Uso interno de desenvolvimento.
metadata:
  author: Inovaize
  version: "1.0.0"
  project: Yuris SaaS jurídico
  scope: dev-only
---

# Yuris Legal Intake Optimizer

Skill interna para construir e manter o **Assistente Virtual de Pré-Atendimento Jurídico**
do Yuris sobre o WhatsApp, **sem nunca criar uma segunda conexão com a Evolution API**.

Esta skill é ferramenta de desenvolvimento. Ela NÃO vai para o runtime do bot, NÃO é
enviada à OpenAI/Anthropic a cada atendimento e NÃO aumenta o custo do cliente final.
Ver `references/security-rules.md` (seção "Skill não é runtime").

---

## PREMISSA ZERO (regra absoluta do projeto): UMA ÚNICA INSTÂNCIA DA EVOLUTION

O bot é uma **camada de automação** sobre a conexão WhatsApp que já existe no Yuris. Para
cada conta/canal existe **uma só instância** da Evolution, usada ao mesmo tempo pelo Chat
WhatsApp (humano) e pelo pré-atendimento (bot).

É proibido criar: segunda instância "do bot", segundo QR Code para o mesmo número, segundo
token/registro da Evolution só para o bot, webhook duplicado, ou tabela de credenciais
própria do agente. O agente guarda apenas uma **referência ao canal autorizado**
(`agent_configs.whatsapp_instance_id` → `whatsapp_instances.id`); as credenciais continuam
centralizadas em `whatsapp_settings` (por conta).

Detalhe completo, com diagramas de fluxo, em
`references/evolution-single-instance-architecture.md`. **Leia esse arquivo antes de tocar
em qualquer integração de canal.**

---

## Quando usar esta skill

- Escrever ou revisar o **prompt universal** do assistente (ver `templates/system-prompt-template.md`).
- Definir/ajustar **classificação, triagem, urgência e extração** de leads.
- Trabalhar o **fluxo conversacional** e a **máquina de estados** da conversa
  (`references/conversation-state-machine.md`).
- Definir/validar o **Structured Output** (JSON) do agente
  (`references/structured-output-schema.md`).
- Mexer no **webhook** (`public/api/whatsapp/webhook.php`), no **gating** do agente, na
  **idempotência** ou na **prevenção de loop** (`fromMe`).
- Mexer na **resolução de canal** e nas regras **matriz/filial/grants**
  (`references/multi-tenant-channel-resolution.md`).
- Implementar/ajustar o **atendimento humano** e o **takeover**
  (`references/human-handoff-rules.md`).
- **Reduzir tokens/custo** sem perder segurança nem cobertura.
- **Testar** o bot (conversação e integração com a Evolution).

## Quando NÃO usar

- Tarefas fora do agente de IA do WhatsApp (financeiro, processos, tarefas, etc.).
- Conexão/QR/reconexão do número: isso é do **módulo WhatsApp** (Comunicação → Chat
  WhatsApp) e do Painel Master, nunca do agente.

---

## Mapa do código real (verificado no repositório, 2026-06-19)

Use **estes nomes reais**. Nunca invente tabelas/arquivos. Detalhe e números de linha nas
referências.

| Papel | Onde está (real) |
|---|---|
| Webhook único da Evolution | `public/api/whatsapp/webhook.php` |
| Resolver conta pela instância | `WhatsAppInstance::findAccountByApiKey()` (lê `whatsapp_settings.evolution_api_key`) → `findOrCreate($instanceName,'',$accountId)` |
| Gating + enfileiramento do bot | `maybeQueueAgentReply()` em `webhook.php` (≈ linha 509) |
| Execução do bot (LLM + envio) | `runAgentReply()` em `webhook.php` (≈ linha 820), após `flushResponse()` |
| Serviço de envio (reutilizar) | `app/WhatsAppAgente/EvolutionApiService.php` → `sendText()/sendMedia()/sendAudio()` |
| Mídia (reutilizar) | `public/api/whatsapp/media.php` (download) e `media_upload.php` |
| Resolver/autorizar canal | `app/WhatsAppAgente/WhatsAppChannelAccessService.php` → `resolveForRequest()`, `check()`, `grant()`, `revoke()` |
| Contexto de conta/tenant | `app/Core/AccountContext.php` → `getAccountId()`, `getAccessibleAccountIds()`, `getPipelineAccountId()`, `isOwnerOrAdmin()` |
| Config do agente (1 por canal) | tabela `agent_configs` (UNIQUE `uk_agent_instance` em `whatsapp_instance_id`) |
| Tela de config | `public/agente.php` + `public/api/agent_settings.php` + `public/api/whatsapp/agent_instances.php` |
| Pausa por humano | tabela `whatsapp_chats.agent_paused` + `public/api/whatsapp/agent_takeover.php` |
| Idempotência inbound | UNIQUE `(instance_id, wamid)` em `whatsapp_messages` + checagem em `webhook.php` |
| Flag de compartilhamento | `WHATSAPP_SHARED_CHANNELS_ENABLED` via `EnvLoader::get(...)` (default `false`) |

Invariantes confirmadas que devem ser preservadas:
- O agente é selecionado **pela instância** (`whatsapp_instance_id = ? AND enabled = 1 AND
  wi.status = 'open'`). O UNIQUE por instância garante **no máximo 1 agente por canal**.
- A resposta do bot sai pela **mesma instância** (`runAgentReply` envia via
  `EvolutionApiService::sendText` usando as settings do **dono** da conta resolvida pela
  API key). Não introduzir credenciais novas no caminho do agente.
- O agente só dispara para **mensagem individual de texto, inbound, nova** (não grupo, não
  broadcast, não `fromMe`, não duplicada). Mídia/áudio NÃO acionam o bot na v1.

---

## Como trabalhar (workflow recomendado)

1. **Antes de mudar o prompt, preserve a baseline.** Copie o prompt vigente para
   `tests/baseline/` e rode os validadores (passo 4). Sem baseline não há comparação
   honesta. Ver seção "Avaliação antes/depois" abaixo.
2. **Projete/edite o prompt com a estrutura TIDD-EC** (Task, Instructions, Do, Don't,
   Examples, Context). É a estrutura adotada por esta skill, ver justificativa abaixo.
   Use `templates/system-prompt-template.md`. Para revisão de qualidade do prompt, a skill
   `prompt-architect` (instalada em `.claude/skills/prompt-architect/`) ajuda a escolher
   framework e checar clareza/especificidade/conflitos.
3. **Mantenha o Structured Output idêntico** entre produção e testes
   (`references/structured-output-schema.md`). Schema divergente quebra os evals.
4. **Valide com os scripts** (todos stdlib, sem pip):
   - `python scripts/validate_prompt.py <prompt.md>` — placeholders, tags, contradições,
     termos proibidos (promessa/prazo/êxito/parecer), ausência de regras obrigatórias
     (handoff, urgência, anti-injection, multi-tenant, instância única), tokens/chars.
   - `python scripts/estimate_prompt_tokens.py <prompt.md>` — custo por mensagem e por
     100/1.000/10.000 atendimentos (preços em config, marcados como estimativa).
   - `python scripts/validate_schema.py <instance.json>` — valida o JSON do agente contra
     o schema canônico (campos, tipos, enums, `additionalProperties:false`, limites).
   - `python scripts/validate_evolution_architecture.py <path>` — alerta sobre segunda
     instância, credencial duplicada, QR/admin key na tela do agente, webhook novo, envio
     fora do serviço, falta de idempotência/`fromMe`/takeover, bypass multi-tenant. Sai
     com código ≠ 0 em risco crítico.
   - `python scripts/run_conversation_evals.py --mode local` — roda os casos de
     `tests/conversation_cases.json` e `tests/evolution_integration_cases.json` **sem
     consumir créditos** (modo determinístico). Chamadas reais exigem `--mode api --live`
     + `--max-calls`.
5. **Compare antes/depois** e só aceite se ficou mais claro, determinístico, econômico,
   seguro, testável **e** compatível com instância única (não basta ficar menor).

### Por que TIDD-EC

O pré-atendimento jurídico é dominado por **restrições** (o que o bot pode e, sobretudo, o
que **não** pode fazer: nada de parecer, promessa, prazo, chance de êxito, análise de
documento, se passar por humano). TIDD-EC separa explicitamente **Do** e **Don't** e
ancora com **Examples** + **Context**, o que reduz alucinação de conduta proibida melhor
que frameworks sem listas negativas. CARE é a alternativa quando regras e exemplos vêm
juntos; preferimos TIDD-EC pela separação Do/Don't. (Critério vindo de
`.claude/skills/prompt-architect/SKILL.md`.)

---

## Runtime: o que vai (e o que NÃO vai) para o LLM

Esta skill **não** entra no runtime. No atendimento, mande ao modelo apenas:

- o **prompt universal otimizado** (system),
- a **configuração do tenant** (áreas habilitadas, nome do escritório, máx. de perguntas),
- o **estado resumido** da sessão,
- o **schema** de saída,
- a **mensagem atual** do cliente,
- a **referência ao canal** (`channel_id`), nunca credenciais.

NÃO mande: a skill, frameworks, arquivos de referência, avaliações, nem Chain-of-Thought.
O prompt de produção **não pede raciocínio privado** e **não armazena** raciocínio interno;
pede só classificação, extração, urgência, resultado estruturado e resumo. Ver
`references/security-rules.md`.

---

## Estrutura da skill (progressive disclosure)

```
yuris-legal-intake-optimizer/
├── SKILL.md                       (este arquivo)
├── references/                    (leia sob demanda)
│   ├── evolution-single-instance-architecture.md   ← premissa ZERO + diagramas
│   ├── multi-tenant-channel-resolution.md          ← matriz/filial/grants/backend
│   ├── human-handoff-rules.md                      ← estados + takeover + pausa
│   ├── legal-intake-rules.md                       ← regras jurídicas de conduta
│   ├── security-rules.md                           ← injection, segredo, LGPD, runtime
│   ├── structured-output-schema.md                 ← JSON canônico do agente
│   └── conversation-state-machine.md               ← estados e transições
├── templates/
│   ├── system-prompt-template.md                   ← prompt universal (TIDD-EC)
│   └── response-templates.json                     ← respostas padrão ao cliente
├── scripts/                       (stdlib, sem pip)
│   ├── validate_prompt.py
│   ├── estimate_prompt_tokens.py
│   ├── validate_schema.py
│   ├── validate_evolution_architecture.py
│   └── run_conversation_evals.py
├── tests/
│   ├── conversation_cases.json
│   ├── evolution_integration_cases.json
│   └── baseline/                                   ← prompt vigente preservado
└── evals/
    └── evals.json                                  ← casos no formato skill-creator
```

---

## Checklist de aceitação (resumo dos 20 testes da Evolution)

Antes de considerar qualquer implementação pronta, confirme (detalhe em
`tests/evolution_integration_cases.json`):

1. Agente reutiliza instância existente; 2. nenhuma instância nova; 3. nenhum 2º QR;
4. nenhuma credencial duplicada; 5. webhook atual encaminha ao motor; 6. evento duplicado
não responde 2x; 7. `fromMe` não aciona; 8. mensagem manual do advogado pausa o bot;
9. handoff humano na mesma conversa; 10. canal desconectado não cria instância;
11. tenant sem canal não ativa o bot; 12. filial sem grant não acessa canal da matriz;
13. filial autorizada usa o canal compartilhado pela regra existente; 14. token/instância
resolvidos só no backend; 15. nunca dois agentes na mesma conversa; 16. mídia usa a mesma
conexão; 17. bot desativado não processa; 18. conversa em human_takeover sem automação;
19. card de prospecção não duplica; 20. isolamento multi-tenant mantido.
