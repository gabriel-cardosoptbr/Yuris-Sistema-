# scripts/ — utilitários de linha de comando

Nada aqui é acessível por URL, e nada roda sozinho. São scripts que alguém
executa de propósito, com `php scripts/<arquivo>.php`.

## Testes

`tests/` é o que você roda **antes e depois** de qualquer mudança em `app/`.

| Script | Cobre | Precisa de banco? |
|---|---|---|
| `tests/class_refs_test.php` | **toda referência a classe resolve, e todo `require` aponta para arquivo real** | não |
| `tests/wa_webhook_parser_test.php` | parsers do payload da Evolution | não |
| `tests/wa_webhook_token_test.php` | segundo fator do webhook | não |
| `tests/wa_invariants.php` | invariantes do módulo WhatsApp e do agente | sim |
| `tests/plan_feature_test.php` | limites e módulos por plano | sim |
| `tests/plan_gate_e2e_test.php` | enforcement de plano ponta a ponta | sim |
| `tests/dominios_test.php` | **escrita real** em Clientes, Prospecção, Processos, Tarefas, Finanças e LGPD, + isolamento entre contas | sim |

Baseline conhecido em **27/08/2026**, com o MySQL de pé:

```
class_refs          3411 referencias + 323 requires · todos resolvem
wa_webhook_parser     42 PASS · 0 FAIL
wa_webhook_token      21 PASS · 0 FAIL
wa_invariants         39 PASS · 0 FAIL
plan_gate_e2e         25 ok  · 0 falha
plan_feature          79 ok  · 0 falha
dominios              51 ok  · 0 falha
```

**Tudo verde é o esperado.** Qualquer falha é regressão.

### O que o `class_refs_test` pega, e por que ele existe

Ele confere estaticamente, com o tokenizer do PHP, se **todo nome de classe
citado no projeto resolve para uma classe que existe**, aplicando as regras
reais do PHP (`\X` global; `X` vira o `use` se houver, senão
`NamespaceAtual\X`, sem fallback para o global).

Nasceu de um caso real: ao dividir um namespace em 27/08/2026, 131 referências
passaram a apontar para o vazio, e **nada do que se costuma rodar pegou**. `php
-l` não resolve nome de classe; carregar o arquivo também não, porque type hint
só resolve na chamada; e a varredura HTTP sem sessão redireciona para o login
antes da linha quebrar, então deu 164/164 "sem fatal" com o sistema quebrado.

Desde 27/08/2026 ele também confere se **todo `require` aponta para arquivo que
existe**. Isso entrou depois de um caso real: ao mover `Cliente.php` de pasta, um
`require __DIR__ . '/Contato.php'` **dentro de um método** ficou apontando para o
vazio. Não aparecia no lint nem no carregamento, e a varredura HTTP não pegava
porque só o POST de criar/editar cliente executa aquela linha.

É o único teste aqui que cobre **caminho de código que nenhuma requisição
executa**. Rode-o sempre que mexer em namespace, mover arquivo ou renomear
classe.

### O que o `dominios_test` cobre, e por que ele existe

É o único teste que **escreve de verdade** nos domínios de negócio. Cria duas
contas descartáveis, exercita criar/atualizar/listar em Clientes, Prospecção,
Processos, Tarefas, Finanças e LGPD, e exige que **a segunda conta não enxergue
nada da primeira**, domínio por domínio.

Nasceu do caso de 27/08/2026: um `require` quebrado dentro de `Cliente::create()`
chegou à main porque só era alcançável **criando cliente com telefone**, e nada
automatizado fazia isso. O `class_refs_test` pega o sintoma estático; este pega o
comportamento. Validado reintroduzindo o bug: os dois acusam.

Cobre também garantias que não são de código: o histórico de solicitação LGPD é
**imutável no banco** (trigger recusa UPDATE e DELETE), e o teste exige que
continue assim, porque uma migration futura poderia recriar a tabela sem os
triggers e ninguém notaria.

**Limpa o que cria**, num `register_shutdown_function` que roda mesmo se uma
asserção falhar, e reclama em `stderr` se alguma tabela recusar a limpeza. Não
toca em dado pré-existente.

### As 12 falhas antigas do `plan_feature` acabaram

Durante meses a suíte fechava em `66 ok · 12 falha`, e as 12 eram tratadas como
dívida herdada que ninguém tinha investigado. Investigadas em 27/08/2026: eram
**as asserções de preço da página pública**, que deixaram de valer quando o
produto decidiu tirar os valores de `planos.php` e tratar preço por consulta.
O teste é que estava velho, não o código.

Hoje o mesmo bloco confere a decisão nova, e é mais forte do que era: nenhum
`R$` na página, nenhum dos valores da grade, JSON-LD sem `offers`/`price` (para
o Google não anunciar um preço que a página não mostra), um "Valor sob consulta"
por plano e o CTA de contato. Validado injetando um preço de propósito: acusa em
três frentes.

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
