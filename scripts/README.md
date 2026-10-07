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
| `tests/setores_test.php` | **setor do lead e gestor do setor** (migration 140): setor pelo nome sem diferenciar caixa e acento e sem cruzar contas, gestor gravado e devolvido, automação que não troca setor escolhido, e conversa e card mantendo o mesmo setor nos dois sentidos (transação desfeita no fim) | sim |
| `tests/card_crm_test.php` | **os campos do card da edição CRM** (`tipo_lead`, `temperatura`): normalização, criar e editar gravam, e a lista traz a última mensagem do WhatsApp ligado (transação desfeita no fim) | sim |
| `tests/reembolsos_test.php` | **os reembolsos** (`App\Financas\Reembolso`): centavos, divisão das parcelas com soma exata, vencimento no fim de mês, situação derivada das parcelas, validação, e no banco criar, pagar, desfazer, quitar, a trava de valor com parcela paga o módulo ligado por conta (`habilitado()`, Yuris nunca), quem pagou cada parcela, e o isolamento entre contas (outra conta não lê, não altera, não paga, não exclui). Apaga o que criou. Precisa das migrations 138 e 139 | sim |
| `tests/contatos_salvos_test.php` | **todo contato do WhatsApp da edição CRM tem card** (`SdrFleetiflow::conversasSemCard` e `ligarConversasSoltas`): conversa com telefone e mensagem ganha card (com o nome da conversa, ligado a ela), telefone que já é card só é ligado sem duplicar, e ficam de fora com o motivo a conversa sem mensagem, a de telefone desconhecido, a do número da própria conta, a de treino e o grupo; segunda passada não faz nada. Conta CRM de teste, migration 140. Apaga o que criou | sim |
| `tests/segmento_lead_test.php` | **`App\WhatsAppAgente\SegmentoLead` e o lead que nasce com nome e setor**: a saudação "Oi|Olá, NOME!" vira o nome ("pessoal da" sai, genérico e resposta curta são descartados), o setor sai da família de palavras da abertura ou do tipo do lead, só entre os setores da conta (duas famílias é ambíguo, setor da Fleet nunca é sugerido); e, no banco (conta CRM de teste, migration 140), a mensagem chega, o card nasce com nome, setor e conversa herdando o setor, sem trocar nome ou setor já escolhidos. Apaga o que criou | sim |
| `tests/tema_escuro_test.php` | **o tema escuro da edição CRM** (`App\Master\TemaEscuroCrm`): só regras do tema claro entram e com o prefixo trocado, cores claras viram escuras, a cor da marca como texto vira o tom claro (como fundo não muda), regras sem prefixo só quando pedido e nunca `:root`, `@media` respeitado, e as folhas reais (fichas, abas do chat) convertem sem sobrar seletor claro | não |
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
| `whatsapp_dedupe_lid.php` | desduplica contatos por LID: funde a conversa `@lid` na do telefone quando o par é conhecido (tabela de identidade). Simulação por padrão, `--apply` funde; `--instancia=N` limita a fusão a um número |

Estes **alteram dados**. Leia o script antes, e faça backup.

## manutencao/ — operações pontuais, já executadas

| Script | O que faz |
|---|---|
| `_phaseB_webhook_token.php` | liga o `webhook_token` na Evolution de um canal. **Roda em dry-run por padrão**, só age com `--apply` ou `--rollback` |
| `especialista_retroativo.php` | move para "Em atendimento pelo especialista" os cards de conversas já respondidas por uma pessoa (celular ou WhatsApp Web), na edição CRM. Mostra antes a contagem de mensagens próprias por status e origem, que é a prova do critério. **Simulação por padrão**, `--aplicar` move, `--dias=N` muda a janela |
| `_phase2_repoint_silvana.php` | reaponta o webhook de uma instância específica para o Yuris. Imprime o webhook atual antes de trocar, para permitir voltar |
| `salvar_contatos_sem_card.php` | garante que todo contato do WhatsApp de uma conta da edição CRM tenha card: para cada número da conta lista o que faria (ligar a conversa a um card de mesmo telefone, criar o card que falta, ou pular com o motivo) e, com `--aplicar`, executa a mesma rotina da lista do Chat. Cada card novo avisa a equipe como qualquer lead novo. Só confere por padrão (`--conta=ID`). Em 07/10/2026, para a Inovaize (119) e a Fleet (116) |
| `nome_do_lead_pela_abertura.php` | dá nome ao lead que só tem o telefone no lugar do nome (criado pela automação antes do backfill da planilha): lê a saudação da PRIMEIRA mensagem que a conta enviou ("Olá, Sampaio e Dellova Campos Advogados! Tudo bem?") e grava em `cliente_nome` e `empresa_nome`, com o valor anterior no histórico do lead. Só mexe em lead sem nenhuma letra no nome e sem empresa; idempotente. Só confere por padrão (`--conta=ID`, `--aplicar` grava). Em 07/10/2026, para a Inovaize (119). Desde 07/10/2026 o lead que nasce já recebe o nome por `SdrFleetiflow::completarLead` (mesma regra, `SegmentoLead`); o script cobre o que já existia |
| `setores_por_segmento.php` | cadastra os setores de uma conta da edição CRM por segmento (Advocacia, Estética) e marca neles os leads e as conversas que estão "Sem setor": vale o `tipo_lead` do lead, e sem tipo a primeira mensagem que a conta enviou ("escritório"/Yuris ou "clínica"); ambíguo fica sem setor e é listado. Não troca setor já escolhido; idempotente. Só confere por padrão (`--conta=ID`, `--aplicar` grava). Em 07/10/2026, para a Inovaize (119) |
| `ligar_reembolsos.php` | liga ou desliga o painel de Reembolsos da tela Finanças numa conta (`configuracoes.modulos.reembolsos`). Recusa conta com módulo jurídico (Yuris). Só confere por padrão; `--aplicar` grava, `--desligar` tira. Em 03/10/2026, ligado só na Inovaize (119) |
| `definir_dono_numero.php` | define o vendedor dono de um número de WhatsApp (`whatsapp_instances.responsavel_user_id`, migration 137); o usuário tem de ser ativo e da mesma conta. Só confere por padrão, `--aplicar` grava, `--usuario=0` tira o dono |
| `backfill_prospeccao_planilha.php` | lança na Prospecção os leads que um disparo já abordou antes de o número ser ligado, a partir do CSV da planilha do disparo (só `whatsapp_status = enviado`). Preserva a **data real do envio**: o card nasce com `created_at` do envio (São Paulo convertido para UTC) e o `card_history` ganha `created` e `captado_whatsapp` com essa data. Lead existente só ganha empresa, categoria e responsável vazios; cliente fica fora; conversa solta é ligada. Só confere por padrão, `--aplicar` lança; rodar de novo não duplica |
| `vincular_whatsapp.php` | liga a uma conta, como primeiro número, uma instância que **já existe** na Evolution (QR lido fora do sistema), por `WhatsAppProvisioningService::vincularExistente()`. **Só confere por padrão**, `--aplicar` liga. Recusa sem mexer em nada se a conta já tem número, se a instância é de outra conta ou se ela já manda eventos para outro endereço. Usado em 02/10/2026 para o número comercial da Inovaize (conta 119, instância `S1  principal`, com dois espaços) |

Os dois são de fases de migração já concluídas, guardados porque documentam
**como** a operação foi feita e como desfazê-la. Não rode sem entender o que
fazem: mexem em infraestrutura viva de WhatsApp.

## Regra ao criar script novo

Script que altera dado nasce com **dry-run como padrão** e só age com uma flag
explícita, e imprime o estado anterior antes de mudar qualquer coisa. É o que os
dois de `manutencao/` fazem, e é o que permitiu confiar neles em produção.
| `tests/conversao_test.php` | **Prospecção → Cliente**: conversão transacional, timeline que atravessa a conversão, valor anterior e novo, duplicidade, vinculação a cliente existente, permissão, isolamento entre contas e rollback. **ESCREVE no banco**: só em desenvolvimento | sim |
