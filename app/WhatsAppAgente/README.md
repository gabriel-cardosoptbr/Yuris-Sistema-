# WhatsAppAgente/ — o canal WhatsApp e o agente de IA que roda sobre ele

Telas: **Comunicação › WhatsApp** (`public/chat.php`) e **Automações › Agente**
(`public/agente.php`).

## Por que WhatsApp e Agente estão na mesma pasta

No menu são dois itens. No código são uma coisa só, e isso é a premissa mais
importante deste módulo:

> **O agente é uma camada sobre a conexão de WhatsApp que já existe. Ele nunca
> cria uma segunda conexão.**

Para cada conta existe **uma única instância** da Evolution API, usada ao mesmo
tempo pelo chat humano e pelo agente. Um segundo QR Code, um segundo token, uma
segunda instância "do bot" ou um webhook paralelo quebram o módulo inteiro.

O agente guarda apenas uma **referência ao canal autorizado**
(`agent_configs.whatsapp_instance_id` → `whatsapp_instances.id`). As
credenciais continuam centralizadas em `whatsapp_settings`, por conta.

Separar as duas coisas em pastas diferentes deixaria essa relação invisível,
que é justamente como o erro acontece. Por isso ficam juntas.

O detalhe completo, com diagramas, está na skill de desenvolvimento em
`../../.claude/skills/yuris-legal-intake-optimizer/references/evolution-single-instance-architecture.md`.

## Arquivos

### O canal
| Classe | O que faz |
|---|---|
| `WhatsAppInstance.php` | a instância da Evolution ligada à conta: status, dono, nome |
| `WhatsAppMessage.php` | mensagens do chat, gravadas por `wamid` |
| `EvolutionApiService.php` | a camada de integração com a Evolution: `sendText`, `sendMedia`, `sendAudio`, webhook. **Todo envio passa por aqui**, 30 métodos |
| `WhatsAppProvisioningService.php` | provisionamento idempotente do canal ao conectar, inclusive geração do `webhook_token` |
| `WhatsAppChannelAccessService.php` | **camada única de autorização de canal.** Resolve se a sessão pode usar aquele canal, incluindo o caso filial usando canal da matriz |
| `WaLog.php` | log de uma linha em JSON, com chaves padronizadas, para todo o módulo |

### O webhook de entrada
| Classe | O que faz |
|---|---|
| `WhatsAppWebhookAuth.php` | valida o segundo fator do webhook (header `X-Webhook-Token`), em modo compatível: enquanto a Evolution não manda, a entrega segue |
| `WhatsAppWebhookParser.php` | parsers **puros** do payload da Evolution. Sem efeito colateral, por isso é o mais fácil de testar |
| `WhatsAppWebhookEntitySync.php` | persistência das entidades do webhook: contatos, conversas, grupos, participantes |
| `WhatsAppAgentBridge.php` | o caminho do agente dentro do webhook: enfileira resposta, detecta envio humano, devolve o 200 rápido antes de processar |

### O agente
[`AiIntake/`](AiIntake/) — o motor de pré-atendimento jurídico.

## Todas as classes daqui têm namespace (desde 27/08/2026)

Cinco delas viviam no namespace global por herança: `WhatsAppInstance`,
`WhatsAppMessage`, `EvolutionApiService`, `WhatsAppChannelAccessService` e
`WhatsAppProvisioningService`. Chamavam-se `\EvolutionApiService`, e essa
exceção já tinha causado **três bugs em produção** (lembrete recorrente que
nunca enviava, reagir e apagar mensagem dando 500, corrigidos em `f7d5ca8`).

Hoje todas são `App\WhatsAppAgente\*`, como o resto do projeto. Se você
encontrar `\EvolutionApiService` em código antigo, script solto ou runbook
fora do repositório, está desatualizado.

Detalhe que mordeu na conversão: esses arquivos eram globais, então `PDO` neles
resolvia para a classe nativa. Com namespace, `PDO` passou a significar
`App\WhatsAppAgente\PDO`. Por isso `WhatsAppInstance` e `WhatsAppMessage`
ganharam `use PDO;`. Vale para qualquer classe nativa (`Exception`,
`DateTime`...) ao dar namespace a um arquivo que não tinha.

## Regras que derrubam o módulo se ignoradas

**Uma instância por conta, sempre.** Vale para código novo, migration nova e
tela nova.

**O webhook responde 200 antes de processar.** `flushResponse()` vem antes de
`runAgentReply()`. Se inverter, a Evolution considera falha e reenvia.

**Idempotência por `wamid`.** O par `(instance_id, wamid)` é UNIQUE. Evento
repetido não pode virar mensagem repetida nem resposta repetida do bot.

**`fromMe` não aciona o agente.** Sem isso o bot responde a si mesmo, em laço.

**Mensagem manual do advogado pausa o bot** naquela conversa
(`whatsapp_chats.agent_paused`). O humano assume e o robô cala.
