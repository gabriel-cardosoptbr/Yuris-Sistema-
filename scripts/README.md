# scripts/ — utilitários de linha de comando

Nada aqui é acessível por URL, e nada roda sozinho. São scripts que alguém
executa de propósito, com `php scripts/<arquivo>.php`.

## Testes

`tests/` é o que você roda **antes e depois** de qualquer mudança em `app/`.

| Script | Cobre | Precisa de banco? |
|---|---|---|
| `tests/class_refs_test.php` | **toda referência a classe resolve, e todo `require` aponta para arquivo real**. Carrega de `app/` só quem declara classe: desde que existe página web ali (`app/Lgpd/Paginas/`), dar `require` numa página a **executaria** | não |
| `tests/wa_webhook_parser_test.php` | parsers do payload da Evolution | não |
| `tests/wa_webhook_token_test.php` | segundo fator do webhook | não |
| `tests/wa_invariants.php` | invariantes do módulo WhatsApp e do agente | sim |
| `tests/plan_feature_test.php` | limites e módulos por plano | sim |
| `tests/plan_gate_e2e_test.php` | enforcement de plano ponta a ponta | sim |
| `tests/dominios_test.php` | **escrita real** em Clientes, Prospecção, Processos, Tarefas, Finanças e LGPD, + isolamento entre contas | sim |
| `tests/perfil_comercial_test.php` | **nome de conta comercial do WhatsApp**: os casos reais dão o nome certo e os duvidosos dão null; o peso fica abaixo do pushName; e `resolver()` grava, não consulta a Evolution duas vezes e não atropela nome real (Evolution simulada, transação desfeita no fim) | sim |
| `tests/termometro_test.php` | **o termômetro da edição CRM (regra mista)**: etapa como partida e teto, tempo que só esfria, resposta do lead até morno, manual vencendo tudo, limites inclusivos, validação da regra e do formato antigo, gravação que preserva o `produto` e regra inválida gravada à mão voltando ao padrão (transação desfeita no fim) | sim |
| `tests/card_crm_test.php` | **os campos do card da edição CRM** (`tipo_lead`, `temperatura`): normalização, criar e editar gravam, e a lista traz a última mensagem do WhatsApp ligado (transação desfeita no fim) | sim |
| `tests/marca_test.php` | **a marca das contas da edição CRM**: a Fleetiflow original continua idêntica (paleta tom a tom, arquivos, Vitória), as validações recusam cor, domínio, endereço de agente e imagem inválidos (inclusive SVG e domínio do Yuris), a gravação preserva o `produto`, o domínio leva à conta certa, e conta de outra marca **não** cai na Vitória (transação desfeita no fim) | sim |
| `tests/wa_midia_test.php` | **o arquivo de mídia do WhatsApp**: miniatura não é servida como se fosse o arquivo, PDF/Office/MP4 passam a ser guardados, mídia grande demais não derruba a mensagem, mensagem temporária e de visualização única são lidas, a segunda tentativa de download grava o arquivo e nunca grava lixo (Evolution simulada), e a ficha do cliente acha a conversa pelo número dele sem atravessar para outra conta (transação desfeita no fim) | sim |
| `tests/otif_test.php` | **OTIF das tarefas**: regra de uma entrega, conversão de fuso (prazo local x relógio UTC), denominador por compromisso, série mensal, período da URL, e a foto gravada na conclusão (integração dentro de transação desfeita no fim) | sim |
| `tests/djen_filtros_test.php` | **a OAB manda na busca do DJEN**: com OAB o nome não vai junto, e o nome de exibição nunca vira filtro | não |
| `tests/rotas_test.php` | **a camada de rota de `public/`**: o `.htaccess` só desvia o que não existe, o `require` acontece em escopo global, `includes/` e `uploads/` não saem por rota, e toda página resolve pelo endereço limpo | não |
| `tests/varredura_urls.php` | não é suíte, é **ferramenta**: captura status e corpo de toda URL de `public/`, com sessão de owner e de member, para comparação diferencial antes/depois de mudança estrutural | sim |

Baseline conhecido em **08/09/2026**, com o MySQL de pé:

```
class_refs          3528 referencias + 330 requires · todos resolvem
wa_webhook_parser     69 PASS · 0 FAIL
wa_webhook_token      21 PASS · 0 FAIL
wa_invariants         61 PASS · 0 FAIL
rotas                 47 PASS · 0 FAIL
conversao             65 PASS · 0 FAIL  (só em dev: escreve no banco)
plan_gate_e2e         25 ok  · 0 falha
plan_feature          79 ok  · 0 falha
dominios              51 ok  · 0 falha
djen_filtros           8 ok  · 0 falha
```

### A varredura diferencial, e por que ela é autenticada

`varredura_urls.php` existe por uma lição cara: em 27/08/2026 uma varredura
**anônima** deu 164/164 com o sistema quebrado, porque página interna redireciona
para o login **antes** de executar a linha que fatalava. A varredura de hoje monta
duas sessões (owner e member) replicando o que o `AuthController` grava, só com
`SELECT`, e compara status, redirect e corpo normalizado.

```bash
php scripts/tests/varredura_urls.php --out=antes.json
# ... a mudança ...
php scripts/tests/varredura_urls.php --out=depois.json
php scripts/tests/varredura_urls.php --antes=antes.json --depois=depois.json
```

Ela só faz `GET` e **não varre `public/api/`** por padrão: endpoint com sessão
válida pode mutar dado. Foi ela que pegou, no D3, a home perdendo o ícone do
WhatsApp em todos os botões: mudança invisível em status, visível só no corpo.

O inventário vem de dois lugares: os arquivos de `public/` **e as chaves de
`config/rotas.php`**. Sem a segunda fonte a varredura ficaria cega exatamente
onde a camada de rota atua, porque página que sai de `public/` sumiria do
inventário e a captura "depois" não teria como acusar que ela quebrou.

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
| `create_fleetiflow_account.php` | **hoje se usa o Painel Master** (botão "+ Conta CRM"), que faz o mesmo e ainda grava a marca. O script cria a conta Fleetiflow (edição CRM/comercial): mesma sequência do Painel Master (`accounts` + `users` + `subscriptions` + `AccountBootstrapSeeder`), já com `configuracoes.produto=fleetiflow`. Idempotente por rejeição: aborta se já existir conta ou login com o mesmo nome, não duplica. Desde 02/10/2026 aceita **marca própria** (`--marca-nome`, `--marca-subtitulo`, `--marca-cor`, `--dominio`, `--logo`, `--icone`) e os dados da empresa (`--account-email`, `--razao-social`, `--cnpj`, `--telefone`, `--cidade`, `--estado`), gravados na mesma transação como no Painel Master; recusa domínio ou CNPJ que já sejam de outra conta. Foi assim que nasceu a conta da Via Autodoc (`crm.viaautodoc.com.br`) |
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
| `especialista_retroativo.php` | move para "Em atendimento pelo especialista" os cards de conversas já respondidas por uma pessoa (celular ou WhatsApp Web), na edição CRM. Mostra antes a contagem de mensagens próprias por status e origem, que é a prova do critério. **Simulação por padrão**, `--aplicar` move, `--dias=N` muda a janela |
| `_phase2_repoint_silvana.php` | reaponta o webhook de uma instância específica para o Yuris. Imprime o webhook atual antes de trocar, para permitir voltar |

Os dois são de fases de migração já concluídas, guardados porque documentam
**como** a operação foi feita e como desfazê-la. Não rode sem entender o que
fazem: mexem em infraestrutura viva de WhatsApp.

## Regra ao criar script novo

Script que altera dado nasce com **dry-run como padrão** e só age com uma flag
explícita, e imprime o estado anterior antes de mudar qualquer coisa. É o que os
dois de `manutencao/` fazem, e é o que permitiu confiar neles em produção.
| `tests/conversao_test.php` | **Prospecção → Cliente**: conversão transacional, timeline que atravessa a conversão, valor anterior e novo, duplicidade, vinculação a cliente existente, permissão, isolamento entre contas e rollback. **ESCREVE no banco**: só em desenvolvimento | sim |
