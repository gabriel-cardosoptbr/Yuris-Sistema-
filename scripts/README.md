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
| `tests/sdr_treino_comando_test.php` | **a Vitória nos números de treino** (migration 145): "#ativar"/"#desativar" com e sem acento, frase comum não é comando, número de treino começa desligado, liga, desliga, estado por número (só a linha do número de teste, devolvida no fim) | sim |
| `tests/plataforma_crm_test.php` | **a integração com a plataforma** (migration 144): código de conexão FFC1, assinatura e timestamp dos eventos, textos das notas, o mesmo evento não grava duas vezes, com vínculo vira nota no lead e aviso ao responsável, outra conta não vê (linhas marcadas, apagadas no fim; não chama a plataforma) | sim |
| `tests/orcamento_crm_test.php` | **o orçamento do CRM** (migration 143): a conta bate com a planilha do despachante (cliente vê Detran + honorário R$ 200; escritório, Detran imediato, SERPRO mensal e honorário R$ 191,39), débito do veículo nunca é honorário, aprovar e fechar negócio gravam no lead, rejeitado sai dos ativos, reabrir e editar voltam a rascunho, convertido não muda, outra conta não vê nada (linhas marcadas, apagadas no fim) | sim |
| `tests/ambientes_test.php` | **ambientes da Inovaize, Digital e Drive** (migrations 159 a 163): ambiente de cada página e menu de cada ambiente, quem entra em cada um, quadro de área (cliente de outra conta recusado, colunas do modelo), entrega com os quatro estados e as datas, contagem do mês, previsto x realizado e checklist fora da contagem, material que não aprova nem entrega, responsáveis a mais, campanha, o mesmo cliente em Software e Digital contado uma vez, receita por área sem duplicar, isolamento entre contas e o Drive com o Google de mentira (domínio corporativo, pasta x arquivo, lista com a hora, falha sem lista velha como atual, árvore do cliente, mudanças, vínculo de cartão, acessos), postado e não postado, ritmo do mês os blocos de cada área na Empresa a área marcada na ficha e a miniatura do Drive (só da árvore do cliente, com cache), a navegação no Drive para escolher a pasta, o quadro do projeto de Software (colunas do quadro, entregar grava a data e voltar apaga, coluna de outro quadro recusada, "Projeto · Cliente" criado sozinho) e o de propostas (arrastar de rascunho para negócio fechado passa por aprovada, negócio fechado não muda, outro cliente e outra conta recusados), os relatórios em Excel, o quadro de entregas da ficha (arquivo do Drive na entrega, quadro "Produção · Cliente" criado sozinho, entrega sem tipo recusada) e o relatório em Excel, o Drive completo (envio direto com a origem do sistema, arquivo que cai fora da pasta do cliente recusado, pasta nova, abrir e fechar o link, baixar um arquivo, documento do Google convertido, .zip de todos e dos escolhidos, seções com ordem e título, nada disso para outra conta ou outro cliente), e a ficha de cada ambiente (migration 162: perfil por área, link do post, postagem com a arte do Drive, demandas de Software, projeto ligado, negociações, card do quadro, nada disso lido por outra conta). 156 ok (157 com um segundo usuário na conta 304) | sim |
| `tests/contratos_test.php` | **contratos e assinatura eletrônica** (migration 151, `app/Contratos`): identificadores, CPF, fluxo com três participantes (WhatsApp falso), token inválido, expirado e revogado, código errado, reutilizado e esgotado, pular a confirmação, CPF divergente bloqueia e a equipe libera, clique repetido e assinatura simultânea sem duplicar, nova versão com a assinatura antiga preservada, conclusão com comprovante (e PDF de conclusão e manifesto selado quando há qpdf), gatilhos barrando UPDATE/DELETE, corrente de eventos, outra conta sem acesso, cancelamento, e a ficha do cliente (vincular e desvincular, documentos do contrato na seção Documentos, PDF anexado vira contrato, outra conta sem acesso), e o lugar no CRM (20/10/2026: sem lead nem cliente é recusado, vendedor de outra conta é recusado, sem escolha o vendedor é o responsável do lead, a lista traz o vendedor, tirar o cliente de contrato sem lead é recusado, vendedor e card trocam depois de assinado com a trilha íntegra; 92 ok). Assinatura e evento não se apagam: a limpeza tira os gatilhos, apaga e recria, e o teste se recusa a rodar com `APP_ENV=production`. No XAMPP: `php -d extension=sodium` | sim |
| `tests/card_reorder_test.php` | **o arrastar do quadro grava** (`Card::bulkUpdateOrders`, corrigido em 20/10/2026): mover de etapa grava coluna e ordem com histórico `moved` e request_id; os outros cards mandados juntos não ganham histórico nem updated_at novo; id inexistente é ignorado. Volta os cards no fim; a linha do histórico fica (card_history é imutável) | sim |
| `tests/lead_quadro_test.php` | **lead em mais de uma etapa e em mais de um setor** (migration 156): etapas a mais sem a principal, setor principal do primeiro marcado, setor e etapa de outra conta recusados, quadro com orçamento aprovado, último contato e o básico da cópia, outra conta sem acesso. Desfaz tudo no fim | sim |
| `tests/cliente_valores_test.php` | **o Financeiro da ficha do cliente e as cobranças** (migrations 152 e 153): total por mês (mensal + anual/12, sem encerrado nem vencido), número em formato brasileiro, validações ("começa em" obrigatório, contrato de outra conta), cobranças previstas (mensal desde o início, dia 31 em mês curto, fim, anual, serviço, taxa, demanda, sem pilha para trás), salvar gera, gerar de novo não duplica, baixa e desfazer, editar refaz a aberta e mantém a paga, encerrar cancela, reativar devolve, remover com paga é recusado, painel de Finanças, outra conta sem acesso, negócio fechado de cliente com mensalidade fora da soma de fechamentos (e "por demanda" dentro; usa um cliente provisório apagado no fim), "2.500" como milhar, descrição vazia, cobrança cancelada com valor errado voltando ao corrigir, reabrir, setores do cliente (colunas do quadro: mais duas além da principal, desmarcar, de outra conta recusado), o resumo e o card do quadro (com outra conta sem acesso). Linhas apagadas no fim | sim |
| `contratos_gerar_chave.php` | gera a chave Ed25519 do selo dos contratos e grava a privada num arquivo **fora do repositório** (recusa caminho dentro dele e não sobrescreve); imprime a chave pública e o `chave_id`. Uma vez por servidor | não |
| `tests/agenda_lead_test.php` | **a próxima interação com o lead** (migration 141): agendar cria a tarefa no quadro com o lead vinculado e a hora exata; hora passada, tipo inválido e mensagem sem WhatsApp são recusados; lembrete na janela certa, uma vez, e de novo se remarcada; mensagem programada sai na hora (enviador falso, nada vai ao WhatsApp), conclui a tarefa e não duplica; falha avisa e só volta por "reenviar"; desmarcar ou concluir antes cancela; o card do funil traz o próximo em aberto (o mais cedo, atrasado marcado); a agenda de hoje traz só o da pessoa; outra conta não enxerga (linhas marcadas, apagadas no fim) | sim |
| `tests/setores_test.php` | **setor do lead e gestor do setor** (migration 140): setor pelo nome sem diferenciar caixa e acento e sem cruzar contas, gestor gravado e devolvido, automação que não troca setor escolhido, e conversa e card mantendo o mesmo setor nos dois sentidos (transação desfeita no fim) | sim |
| `tests/card_crm_test.php` | **os campos do card da edição CRM** (`tipo_lead`, `temperatura`): normalização, criar e editar gravam, e a lista traz a última mensagem do WhatsApp ligado (transação desfeita no fim) | sim |
| `tests/projetos_test.php` | **a aba Projetos** (`App\Projetos\Projeto`): centavos, dólar pela cotação, equivalente mensal e vigência, cobranças vencidas para o arrecadado (dia 31, anual, fim), rateio de servidor com soma exata (encerrado não paga, servidor sem custo fica a preencher), pendências do dossiê, validação, módulo por conta, e no banco painel, rateio, importação que não duplica nem sobrescreve, e o isolamento entre contas (inclusive projeto ligado em servidor de outra conta); e, da migration 147, o rateio do custo entre as contas pelo peso com custo direto, receita só de conta que paga, custo por usuário, a evolução mês a mês, o agregado da mãe, a hierarquia de um nível só (neto e mãe de outra conta recusados), a senha de acesso cifrada (revelar, manter em branco, apagar) e a medição que atualiza sem duplicar e sem mexer na receita. Apaga o que criou. Precisa das migrations 146 e 147. As seções com banco pulam no servidor (Docker, produção) sem `TESTE_NO_SERVIDOR=1` e com o banco fora do ar | sim |
| `tests/evolution_painel_test.php` | **a aba Evolution** (`App\Projetos\Evolution`), sem banco nem Evolution: limpeza do fetchInstances (token descartado, item sem nome ignorado, situação desconhecida vira close, número do JID, datas em Brasília), rateio do custo só entre as conexões que ocupam o servidor, montagem (vínculo gravado vence a sugestão, projeto apagado ignorado, totais por projeto e situação), série diária pelas fotos e a configuração da conta; com banco e a migration 148, a foto do dia que não duplica. A parte com banco pula no servidor sem `TESTE_NO_SERVIDOR=1` | sim |
| `tests/reembolsos_test.php` | **os reembolsos** (`App\Financas\Reembolso`): centavos, divisão das parcelas com soma exata, vencimento no fim de mês, situação derivada das parcelas, validação, e no banco criar, pagar, desfazer, quitar, a trava de valor com parcela paga o módulo ligado por conta (`habilitado()`, Yuris nunca), quem pagou cada parcela, e o isolamento entre contas (outra conta não lê, não altera, não paga, não exclui); e, da migration 149, o estorno (tipo, compatível com o que já existia), a pessoa ligada ao usuário (usuário de outra conta recusado, nome vindo do cadastro, editar solta a pessoa) e o saldo por pessoa (agrupa reembolso e estorno, quitado, pago em parte, em atraso, pessoa de fora pelo nome). Apaga o que criou. Precisa das migrations 138, 139 e 149 | sim |
| `tests/situacao_conversa_test.php` | **a linha "Aguardando resposta" e o autor das mensagens** (`App\WhatsAppAgente\SituacaoConversa`, `AutorDaMensagem`): a dedução robô / agente / follow-up / balões seguidos, cada motivo da situação e quando aparece o botão, e com banco numa conversa de teste da conta CRM local: rótulos, "chegou sem webhook", "Mandar para o agente" registrando a entrega (endereço falso em 127.0.0.1:9, nada sai da máquina), trava de dois cliques, pausa e outra conta sem acesso. Apaga o que criou. Precisa da migration 142 | sim |
| `tests/automacao_sdr_test.php` | **os botões Disparo e Follow-up** (`App\WhatsAppAgente\AutomacaoSdr`): sem registro as duas chaves valem ligadas, desligar e religar uma não mexe na outra, gravar de novo não duplica, chave desconhecida e conta inválida são recusadas. Usa a conta da prospecção e devolve o estado que encontrou | sim |
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
| `sincronizar_contas_projetos.php` | mede as contas reais deste sistema (usuários ativos, mensagens de WhatsApp e chamadas e custo de IA nos últimos 30 dias, clientes, processos, leads, plano e preço de tabela) e grava a foto delas como contas dos projetos da pasta Projetos da conta destino. `--yuris=slug` manda todas as contas do produto Yuris para um projeto; `--mapa=conta:slug,...` manda contas da edição CRM, cada uma para o seu. O custo de IA vira custo direto. Não toca em receita, peso nem observação. Só lê as contas de origem e só escreve na destino. Só confere por padrão; `--aplicar` grava, numa transação |
| `ligar_projetos.php` | liga ou desliga a aba Projetos numa conta (`configuracoes.modulos.projetos`). Recusa conta com módulo jurídico (Yuris). Só confere por padrão; `--aplicar` grava, `--desligar` tira. Em 14/10/2026, para a Inovaize (119) |
| `semear_projetos.php` | carrega um dossiê de projetos em JSON (`servidores` e `projetos` com `itens`) na aba Projetos de uma conta. Não duplica (servidor pelo nome, projeto pelo slug, item por natureza + categoria + descrição) e em registro existente só completa campo vazio. Só confere por padrão; `--aplicar` grava, numa transação. `--arquivo=-` lê da entrada (para o `docker exec -i`). O dossiê da Inovaize fica fora do repositório |
| `ligar_reembolsos.php` | liga ou desliga o painel de Reembolsos da tela Finanças numa conta (`configuracoes.modulos.reembolsos`). Recusa conta com módulo jurídico (Yuris). Só confere por padrão; `--aplicar` grava, `--desligar` tira. Em 03/10/2026, ligado só na Inovaize (119) |
| `dono_por_conversa.php` | liga o **dono por conversa** num número (`App\WhatsAppAgente\DonoDaConversa`, migration 164): `--desde` (corte), `--antigas` (quem fica com as conversas de antes) e `--novas` (quem recebe as novas), e distribui as conversas sem dono pela data de criação; quem já tem dono não muda. Só confere por padrão, `--aplicar` grava; `--novas=ID --aplicar` troca só quem recebe as novas (antes de um disparo para outra pessoa); `--desligar` volta ao normal. Ligado em 22/10/2026 no número 18 da Inovaize: antigas para a Isa (126), novas para o Kauê (128), desde 22/10/2026 00:00 |
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
