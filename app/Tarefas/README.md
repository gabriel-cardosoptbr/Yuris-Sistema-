# Tarefas/ — o kanban de tarefas do escritório

Tela: **Operação › Tarefas** (`public/tarefas.php`, `public/api/tasks*.php`).

É o módulo mais completo do sistema em número de peças: quadro, colunas,
cartão, checklist, comentário, anexo por link, apontamento de horas, lembrete e
recorrência. A especificação está em
[`../../docs/TAREFAS_SPEC.md`](../../docs/TAREFAS_SPEC.md).

## Arquivos

| Classe | O que faz |
|---|---|
| `TaskBoard.php` | quadros visíveis para o usuário dentro da conta |
| `TaskColumn.php` | colunas do quadro |
| `Central.php` | **a Central de Tarefas**: lista única do que está em `tasks`, `processo_tarefas` e `processo_prazos`, com classificação por período. Só leitura: as escritas continuam nos endpoints de cada origem. API em `public/api/tarefas_central.php`, tela em `public/assets/tarefas-central.js` |
| `Task.php` | a tarefa. Inclui a reordenação em lote, no mesmo padrão de `../Prospeccao/Card.php` |
| `TaskChecklist.php` | checklist dentro da tarefa |
| `TaskComment.php` | comentários |
| `TaskLink.php` | vínculo da tarefa com outro recurso (processo, card, cliente) |
| `TaskParticipante.php` | responsáveis além do principal (`task_participantes`, migration 159): o principal continua em `tasks.responsavel_id`, que é o que OTIF, lembrete e filtros usam; estes aparecem no cartão e nas Visões Gerais. Só gente da conta do quadro |
| `TaskTimeEntry.php` | apontamento de horas |
| `TaskReminder.php` | lembretes pendentes, consumidos pelo cron |
| `TaskRecurrence.php` | a regra de repetição: calcula a próxima data a partir de uma data dada |
| `RecurrenceCronService.php` | serviço que o cron chama para materializar as tarefas recorrentes vencidas |
| `TaskAudit.php` | propaga evento de tarefa para o histórico do processo vinculado |
| `TaskEntrega.php` | grava a "foto" de cada entrega em `task_entregas` (prazo e checklist do momento), matéria-prima do OTIF. Chamado por `Task::complete()` e pelo `RecurrenceCronService` |
| `Otif.php` | calcula o OTIF (On Time In Full) por colaborador: métricas, ranking com toda a equipe ativa (mesmo quem não teve compromisso), período anterior, série mensal, entregas por dia, semana ou mês (`fluxo`) e pendências. Tela em `public/desempenho.php`, API em `public/api/otif.php` |
| `CargaDaEquipe.php` | o que está aberto na mão de cada pessoa AGORA, sem filtro de data: vencidas, vencendo em 7 dias, em dia e sem prazo. Mesma regra de "aberta" do OTIF (tarefa ativa, quadro ativo, fora da coluna de concluído, só a instância visível da recorrência). Usa o relógio local, nunca `NOW()` |
| `LeituraDaEquipe.php` | transforma os números da tela Desempenho em frases do que pede atenção (tarefa vencida, conversa sem resposta, lead parado, quem está sem tarefa). Pura, só relata o que está nos dados |

## Coluna de concluído é entrega

Arrastar o cartão para uma coluna `is_coluna_concluido` conta como entrega, igual ao
botão Concluir (`Task::aoTrocarDeColuna`, em `move`, `update` e `bulkUpdateOrders`).
A tarefa segue `ativa` para o cartão continuar no quadro, mas grava a foto em
`task_entregas` e preenche `concluida_em`. Tirar da coluna desfaz a entrega daquele
prazo, para um arraste errado não virar resultado. Até 05/11/2026 isto não existia:
a entrega não contava e, vencido o prazo, o cartão em Concluído saía como não
entregue. A migration 176 recupera o que já estava nessas colunas.

A mesma ocorrência é uma entrega só (`TaskEntrega::jaEntregue`): arrastar e depois
clicar em Concluir não conta duas vezes, e cartão em coluna de concluído nunca vira
`perdida` nem `em_atraso`. Para quem lê "o que está aberto", a regra é sempre
`status = 'ativa'` E `COALESCE(col.is_coluna_concluido, 0) = 0`.

## Central de Tarefas: tudo numa tela

Três tabelas guardam "algo a fazer": `tasks` (quadros), `processo_tarefas` e
`processo_prazos` (dentro do processo). A central **lê as três e não copia nada**:
cada tarefa continua sendo um registro só, então concluir ou editar pela central é
o mesmo que fazer pelo processo.

- É a porta de entrada da tela em conta com módulo jurídico. Conta da edição CRM e
  tela de área (`?amb=`) continuam só com quadros. `?board=N` abre o quadro direto.
- **Tipo**: jurídica é a que tem processo (tarefa processual, prazo, ou tarefa de
  quadro com `task_links` para um processo que a pessoa enxerga). O resto é escritório.
- **Nova tarefa** pergunta o tipo. A jurídica procura o processo por nome do cliente
  ou número e grava em `processo_tarefas`.
- **Período**: "Esta semana" é de segunda a domingo e "Este mês" é o mês do
  calendário, inteiros. Quem classifica é `Central::classificar`; a tela só filtra,
  para contador, Kanban e lista não discordarem. O mesmo filtro vale dentro de um
  quadro, por `Task::findByBoard`.
- **Escopo**: quadros pela regra de `TaskBoard::findForUser`, mais o que a pessoa
  criou ou recebeu em quadro alheio; processos pela regra de `Processo::list`.
- **Responsável** de tarefa e prazo do processo é TEXTO (o nome). O filtro chega como
  id e vira nome. O aviso de vencimento (`notificacao_prazos_tick.php`) resolve o
  nome para o usuário da conta e, sem ele, usa o responsável do processo.
- **Clicar numa tarefa ou prazo do processo abre o processo inteiro**
  (`processos.php?open=N&foco=tarefa:N&volta=tarefas`): a ficha desce até o item e
  dá o destaque (`focarNoProcesso`), porque a tarefa só faz sentido com o processo
  em volta. O lápis do cartão edita ali mesmo, sem sair da central.
  Na interface nova (08/11/2026, "tem que só abrir o pop-up, que nem a gente combinou"), o
  processo abre num pop-up por cima da central (`YurisEmbed.abrir`, em
  `public/assets/embed-abrir.js`, o mesmo da home), já descendo até a tarefa e piscando nela;
  o "Abrir processo" da edição rápida também. Fechar (clique fora, Fechar ou Esc) deixa a
  central onde estava, e ela só se redesenha se a lista mudou lá dentro (a tarefa concluída
  sai). Sem a interface nova, o link de sempre.
- **A edição rápida mostra o log do item** (08/11/2026): o lápis de um prazo ou de uma
  tarefa do processo abre, embaixo, o histórico só dele (`carregarHistItem` em
  `public/assets/tarefas-central.js`, pelo `processo_history.php?item_tipo=&item_id=`). O que
  se salva ali entra no log do próprio item.
- **`/tarefas?nova=1` abre o cadastro sozinho** (07/11/2026), como `?tarefa=ID` abre o
  painel da tarefa (`abrirTarefaDoLink` em `tarefas.js`, que tira o parâmetro da barra).
  É o "Nova tarefa" da home nova, que abre esta tela dentro dela com `?embed=1` e
  mostra só o pop-up (ver `app/Inicio/README.md`).
- **Na interface nova o painel da tarefa é maior** (08/11/2026): 980 x 780, sem passar
  da janela, e as abas quebram linha em vez de rolar para o lado. Fica em
  `public/assets/interface-nova-popups.css`; quem não está na prévia vê os 680px de sempre.
- **Tarefa jurídica: cliente e processo** (08/11/2026, "tem que ter uma divisão entre cliente e
  processo, os processos já têm de estar aparecendo, e se eu escolher o processo aparecem as
  tarefas processuais dele, se já foi feito ou não"). O cadastro jurídico ficou com 760px e dois
  campos lado a lado: **Cliente** (`Central::buscarClientes`, `?acao=clientes`: quem tem processo,
  com quantos) e **Processo** (`Central::buscarProcessos`, que agora aceita termo vazio, os mais
  recentes, e `&cliente=` para só os daquele cliente). As duas listas já abrem preenchidas e
  filtram ao digitar; cliente com um processo só já o deixa escolhido; escolher o processo fixa
  o cliente dele. Escolhido o processo, aparecem as tarefas processuais dele
  (`processo_tarefas.php?processo_id=`), a fazer e feitas, com data e responsável; título igual
  a uma que já existe acende ela e avisa se já foi feita. Tudo no mesmo escopo de Processos
  (conta e compartilhamento); o teste `central_tarefas` trava o isolamento das duas buscas.
- **O que criar no processo** (08/11/2026, "nova tarefa pode ser um prazo processual, uma
  tarefa processual ou um vínculo que eu tenho de fazer ou conferir"; e, na mesma tarde, "isso
  não tem de existir antes de eu selecionar um processo; depois, dentro dos campos, tem de ter
  a opção de fazer algo; e não é título, é descrição"). Antes do processo, só Cliente e Processo.
  Escolhido o processo, cada bloco (Tarefas processuais, Prazos processuais, Vínculos) tem o seu
  botão ("Nova tarefa", "Novo prazo", "Adicionar vínculo"), e o formulário abre dentro do
  bloco: Descrição (área de texto), Data ("Data limite" obrigatória no prazo), Prioridade e
  Responsável, com o botão de criar ali; o título, a data e o botão gerais do rodapé somem no
  modo jurídico. O que se digita fica guardado ao trocar de bloco. **Tarefa processual** grava em `processo_tarefas.php`, como antes. **Prazo processual**
  grava em `processo_prazos.php` (descrição, data limite obrigatória, prioridade, responsável),
  com a mesma auditoria da ficha. **Vínculo** não grava nada daqui: abre a ficha do processo com
  `?foco=vinculos` (e `embed=1` dentro da home), que desce até a seção e abre o "+ Adicionar
  vínculo" de sempre, com código, conferência da conta e permissão (é acesso entre escritórios).
  Escolhido o processo, o cadastro mostra o processo inteiro, como na ficha: tarefas (a fazer e
  feitas), prazos (em aberto, vencidos em vermelho, cumpridos) e vínculos ativos
  (`resource_shares.php`, que confere a leitura). O bloco do que se vai criar vem primeiro, e o
  aviso de repetido olha esse bloco.
- **Tarefa de escritório nasce no painel completo** (08/11/2026, "não faz sentido eu criar e
  depois abrir o card para colocar as coisas; amplia o card, faz o carregamento fantasma e
  mostra a tarefa inteira para preencher"). Na Central, clicar em "Tarefa de escritório" no
  cadastro abre o painel da tarefa (`#tkDrawer`) vazio, no modo nova (`abrirTarefaNoPainel` em
  `tarefas.js`): ele nasce da caixa do cadastro, mostra ossos cinza por um instante e depois os
  campos; a coluna lista todos os quadros (por quadro), já no escolhido; só a aba Geral vale e
  Concluir/Arquivar somem até criar. "Criar tarefa" faz o mesmo POST do cadastro pequeno, e o
  painel continua aberto com a tarefa criada e todas as abas liberadas (checklist, anexos,
  lembretes, vínculos). Num quadro aberto (fora da Central), "Nova tarefa" já vai direto para o
  painel. O painel abre antes de o cadastro fechar, para o pop-up embutido na home não fechar.
  A edição CRM não muda.
- **Lista e Calendário na interface nova** (11/11/2026, "completamente sem formatação"): cada linha
  da Lista é um cartão (o desenho está no fim de `public/assets/interface-nova.css`), a coluna das ações
  tem 56 px (lápis e concluir lado a lado; com 30 um caía em cima do outro) e a data atrasada é texto
  vermelho, não etiqueta. O Calendário copia o de Processos: dia em cartão, hoje com o número no círculo
  azul, compromisso afundado com ponto de situação (vermelho atrasado, âmbar hoje, verde o que vem,
  cinza concluído) e **todo compromisso aparece**: o "+3 mais" saiu do `tarefas.js`.
- **Calendário**: compara a data como texto. `new Date('2026-11-05')` é meia-noite em
  UTC, e no Brasil a tarefa cairia na véspera. Clicar no dia cria a tarefa com a data.

## O que `TaskAudit` faz e por que importa

Quando uma tarefa está ligada a um processo, mexer na tarefa **escreve no
histórico do processo**. É o que faz o histórico processual contar a história
completa, e não só o que foi digitado direto no processo. São 16 métodos,
justamente porque cada tipo de evento vira uma linha diferente.

Se você criar um tipo novo de evento de tarefa, decida conscientemente se ele
deve aparecer no histórico do processo. Ficar de fora é uma escolha válida;
ficar de fora por esquecimento não é.

## Regras

**Recorrência gera tarefa, não repete a mesma.** Cada ocorrência é uma tarefa
nova. Se o cron rodar duas vezes na mesma janela, não pode duplicar: a proteção
está no `RecurrenceCronService`, e o lock em `storage/recurrence_cron.lock`.

**Lembrete depende do cron estar de pé.** Se lembrete parar de chegar, verifique
o agendamento antes de procurar bug no código.

**Reordenação é em lote**, pelo mesmo motivo do funil de Prospecção.

## OTIF: desempenho por colaborador

Tela **Gestão › Desempenho**. OTIF = entregas **no prazo e completas** ÷
**compromissos** (tarefas com prazo entregues ou vencidas no período). A conta
inteira, com o porquê de cada escolha, está no cabeçalho de `Otif.php`.

**A base é `task_entregas`, não `tasks`** (migration 131). Tarefa recorrente
renova na mesma linha, então `tasks` só guarda a última conclusão; e checklist e
prazo podem mudar depois. Cada conclusão vira uma linha com a foto do momento.

Regras que valem para quem mexer aqui:

- **Todo caminho que conclui tarefa passa por `Task::complete()`**, que grava a
  foto. Caminho novo de conclusão que não passe por lá some do OTIF.
- **Recorrente vencida sem conclusão é `perdida`** e conta contra: o
  `RecurrenceCronService` grava antes de avançar o prazo. Não entregue pesa
  contra as três taxas, senão deixar vencer daria nota melhor que entregar
  atrasado.
- **Fuso.** `tasks.prazo` é horário de Brasília como a pessoa digitou; o relógio
  do servidor é UTC. Toda comparação converte a conclusão para o fuso do prazo
  (`TaskEntrega::paraHorarioLocal`). Sem isso, quem conclui até 3h antes sai
  atrasado. O cron também renova em UTC, por isso `registrarPerdida` só conta
  depois do vencimento local.
- **De recorrência, só conta a instância que o quadro mostra**
  (`TaskEntrega::instanciaVisivel`, mesma regra do `findByBoard`). O sistema
  antigo criava uma linha por ciclo e essas duplicatas seguem ativas e sendo
  renovadas pelo cron: em produção, em 27/09/2026, eram 317 linhas para 8
  recorrências. O retroativo deduplica por (recorrência, prazo) e descarta a
  perda de ocorrência que foi concluída na instância visível.
- **Gravar a foto nunca derruba a conclusão**: se falhar, vai para o log.
- **Linhas `retroativo`** foram reconstruídas pela migration a partir do
  `task_history`, com o checklist atual; a tela avisa quantas entram no período.
- **Visibilidade**: dono/admin vê a equipe; os demais, só o próprio desempenho.
  Precisa da permissão de Tarefas, sem chave nova.

Teste: `scripts/tests/otif_test.php`.

## Quadro de área (ambientes da Inovaize, 20/10/2026)

Na conta com ambientes (`app/Ambientes`), o quadro pode ter área (`task_boards.area`:
software, digital, empresa, comercial), cliente (`cliente_id`) e modelo de colunas
(`modelo`, que semeia as colunas iniciais). Tudo em `App\Ambientes\QuadroDaArea`;
`TaskBoard` e as permissões não mudam. `api/task_boards.php` filtra por `?area=` e
`?cliente=` e aceita os três campos ao criar e editar; a tela (`tarefas.php?amb=digital`
ou `?amb=software`) mostra só os quadros da área, e `?board=N` abre um quadro direto.
O cartão de quadro de área ganha a aba "Entrega" (`public/assets/tarefas-areas.js`):
entrega (`App\Digital\Entrega`), responsáveis a mais, materiais esperados e arquivos do
Drive do cliente. Quadro sem área segue igual, e nas outras contas nada disto aparece.

