# AiIntake/ — o agente de pré-atendimento jurídico

O robô que atende o cliente no WhatsApp antes do advogado: entende o caso,
classifica a área, mede urgência, faz as perguntas certas e passa para o humano
com a ficha pronta.

Ele roda **sobre o canal da pasta de cima**, nunca sobre uma conexão própria.
Leia [`../README.md`](../README.md) antes de mexer aqui.

## O desenho em uma linha

Mensagem chega → `IntakeEngine` monta o contexto → **uma** chamada de IA →
resposta em JSON validado contra `IntakeSchema` → grava sessão → responde, ou
chama `HandoffService`.

**Uma chamada de IA por mensagem.** Não é detalhe de performance, é regra de
custo: o cliente paga por conversa.

## Arquivos

| Classe | O que faz |
|---|---|
| `IntakeEngine.php` | o orquestrador. 22 métodos, é por onde tudo passa |
| `IntakeSchema.php` | **fonte de verdade do Structured Output.** O JSON que a IA é obrigada a devolver |
| `IntakeStateMachine.php` | os estados da conversa e as transições permitidas |
| `IntakeSessionRepository.php` | persistência de sessão, mensagem e consumo (tabelas `ai_*`). 20 métodos |
| `Taxonomy.php` | classificação preliminar **determinística**, feita no backend a partir do catálogo de áreas, antes de envolver a IA |
| `HandoffService.php` | passa o lead qualificado para o humano, reaproveitando os módulos que já existem (cria card, avisa) |
| `AgentEvent.php` | log estruturado em `ai_agent_events` |
| `LlmProviderInterface.php` | contrato de provedor de LLM |
| `OpenAiProvider.php` | OpenAI via Chat Completions com `response_format: json_schema`. É o caminho principal |
| `AnthropicProvider.php` | fallback. A Messages API da Anthropic não tem `response_format`, então a garantia de formato é obtida de outro jeito |
| `FakeProvider.php` | provedor determinístico para teste. **Roda sem consumir crédito** |

## Como testar sem gastar

`FakeProvider` existe exatamente para isso. Os scripts são
`../../../scripts/ai_intake_smoke.php` e `../../../scripts/ai_intake_eval.php`,
e a skill de desenvolvimento tem os casos de conversa em
`../../../.claude/skills/yuris-legal-intake-optimizer/tests/`.

Rode o smoke antes de qualquer mudança de prompt ou de schema.

## Regras de conduta que o agente não pode violar

O prompt proíbe, e o código deve continuar sustentando: **nada de parecer
jurídico, promessa de resultado, prazo, chance de êxito, análise de documento,
nem se passar por humano.** Não é preferência de estilo, é exposição do
escritório.

## Regras técnicas

**`IntakeSchema` é contrato compartilhado.** O mesmo schema vale em produção e
nos testes. Se divergirem, os testes passam a validar outra coisa.

**Não peça raciocínio ao modelo, e não guarde raciocínio.** O prompt de
produção pede classificação, extração, urgência, resultado e resumo. Nada de
chain-of-thought armazenado, por LGPD e por custo.

**A ficha vai junto no handoff.** O advogado nunca pode chegar perguntando o
que o cliente já respondeu. Se isso acontecer, o problema é aqui, não no
atendimento.

**Só texto individual aciona o agente.** Não grupo, não broadcast, não
`fromMe`, não duplicado. Mídia e áudio não acionam.
