# scripts/ — utilitários de linha de comando

Nada aqui é acessível por URL, e nada roda sozinho. São scripts que alguém
executa de propósito, com `php scripts/<arquivo>.php`.

## Testes

`tests/` é o que você roda **antes e depois** de qualquer mudança em `app/`.

| Script | Cobre | Precisa de banco? |
|---|---|---|
| `tests/wa_webhook_parser_test.php` | parsers do payload da Evolution | não |
| `tests/wa_webhook_token_test.php` | segundo fator do webhook | não |
| `tests/wa_invariants.php` | invariantes do módulo WhatsApp e do agente | sim |
| `tests/plan_feature_test.php` | limites e módulos por plano | sim |
| `tests/plan_gate_e2e_test.php` | enforcement de plano ponta a ponta | sim |

Baseline conhecido em **27/08/2026**, com o MySQL de pé:

```
wa_webhook_parser   42 PASS · 0 FAIL
wa_webhook_token    21 PASS · 0 FAIL
wa_invariants       39 PASS · 0 FAIL
plan_gate_e2e       25 ok  · 0 falha
plan_feature        66 ok  · 12 falha    <- as 12 sao PRE-EXISTENTES
```

As 12 falhas do `plan_feature` são anteriores à reorganização de pastas. **Se
você vir 12, é o estado herdado. Acima de 12, é sua.** Compare sempre com este
baseline em vez de esperar tudo verde.

Sem MySQL de pé, os três que dependem de banco pulam blocos e o resultado não
significa nada.

## Setup e diagnóstico

| Script | O que faz |
|---|---|
| `seed_admin.php` | cria o usuário admin inicial |
| `check_user.php` | inspeciona um usuário |
| `test_multitenancy_e2e.php` | testa o isolamento entre contas ponta a ponta |

## Agente de IA

| Script | O que faz |
|---|---|
| `ai_intake_smoke.php` | smoke do pré-atendimento. Usa o `FakeProvider`, **não gasta crédito** |
| `ai_intake_eval.php` | avaliação dos casos de conversa |

Rode o smoke antes de mexer em prompt ou schema do agente. Ver
[`../app/WhatsAppAgente/AiIntake/README.md`](../app/WhatsAppAgente/AiIntake/README.md).

## Correção de dados

| Script | O que faz |
|---|---|
| `whatsapp_cleanup_ghosts.php` | remove conversas fantasma |
| `whatsapp_dedupe_lid.php` | desduplica contatos por LID |

Estes **alteram dados**. Leia o script antes, e faça backup.

## manutencao/ — operações pontuais, já executadas

| Script | O que faz |
|---|---|
| `_phaseB_webhook_token.php` | liga o `webhook_token` na Evolution de um canal. **Roda em dry-run por padrão**, só age com `--apply` ou `--rollback` |
| `_phase2_repoint_silvana.php` | reaponta o webhook de uma instância específica para o Yuris. Imprime o webhook atual antes de trocar, para permitir voltar |

Os dois são de fases de migração já concluídas, guardados porque documentam
**como** a operação foi feita e como desfazê-la. Não rode sem entender o que
fazem: mexem em infraestrutura viva de WhatsApp.

## Regra ao criar script novo

Script que altera dado nasce com **dry-run como padrão** e só age com uma flag
explícita, e imprime o estado anterior antes de mudar qualquer coisa. É o que os
dois de `manutencao/` fazem, e é o que permitiu confiar neles em produção.
