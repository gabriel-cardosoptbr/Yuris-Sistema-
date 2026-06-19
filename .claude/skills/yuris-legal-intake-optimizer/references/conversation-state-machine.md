# Máquina de estados da conversa

Define os estados do pré-atendimento e as transições. O campo `conversation_state` do
Structured Output (ver `structured-output-schema.md`) carrega o estado atual. Na v1, a
pausa por humano é o booleano `whatsapp_chats.agent_paused`; os demais estados são
conduzidos pelo modelo via o JSON.

## Estados

| Estado | Significado | Saída típica |
|---|---|---|
| `greeting` | primeiro contato; saudar e identificar-se como assistente virtual | pergunta inicial aberta |
| `collecting` | coletando fatos essenciais (uma pergunta por vez) | `proxima_pergunta` preenchida |
| `clarifying` | resolvendo ambiguidade pontual | `proxima_pergunta` específica |
| `triage` | área + urgência definidas; decidindo encaminhamento | resumo + decisão |
| `awaiting_human` | encaminhado; aguardando atendente | mensagem de espera; bot pausa |
| `completed` | pré-atendimento concluído | mensagem de encerramento; `encerrar=true` |
| `out_of_scope` | tema não atendido / spam | mensagem cordial; encaminhar ou encerrar |

## Transições (feliz e ramos)

```
greeting
  → collecting           (cliente respondeu o motivo do contato)
  → out_of_scope         (spam, engano, ou área não atendida já evidente)

collecting
  → clarifying           (resposta ambígua ou incompleta)
  → triage               (fatos essenciais suficientes para classificar)
  → awaiting_human       (atingiu MAX_PERGUNTAS sem fechar a triagem)
  → out_of_scope         (ficou claro que a área não é atendida)

clarifying
  → collecting           (segue coletando)
  → triage               (resolvido)

triage
  → awaiting_human       (encaminha para advogado, com resumo)
  → completed            (pré-atendimento concluído sem necessidade imediata de humano)
  → out_of_scope         (área não atendida)

awaiting_human
  → (humano assume; agent_paused=1; bot não atua)
  → completed            (quando o atendimento humano encerra, se aplicável)

out_of_scope / completed
  → encerrar = true      (fim da sessão automática)
```

## Regras de transição

- **Uma pergunta por vez.** Cada turno em `collecting`/`clarifying` faz no máximo uma
  pergunta. `perguntas_feitas` incrementa e nunca passa de `{{MAX_PERGUNTAS}}`.
- **Não repetir pergunta** já respondida (checar `dados_extraidos` antes de perguntar).
- **Urgência alta/crítica** acelera para `triage`/`awaiting_human` mesmo sem todos os
  dados.
- **Encerramento antecipado**: se `intent = spam` ou área claramente não atendida →
  `out_of_scope` + `encerrar`.
- **Takeover** (humano assume a qualquer momento): a conversa entra de fato em pausa
  (`agent_paused=1`); o modelo não deve gerar novas respostas até liberação.
- **Idempotência de efeitos**: a criação de card/lead a partir da triagem acontece uma vez
  por sessão (não duplicar por reprocessamento de evento).

## Evolução (pós-v1)

Persistir o estado e o resumo por sessão (tabela própria de sessão do agente, referenciando
o canal e a conversa) para: retomar após takeover, registrar quem assumiu/quando, e evitar
recomeçar do zero. Continuar respeitando a premissa de instância única.
