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
| `Task.php` | a tarefa. Inclui a reordenação em lote, no mesmo padrão de `../Prospeccao/Card.php` |
| `TaskChecklist.php` | checklist dentro da tarefa |
| `TaskComment.php` | comentários |
| `TaskLink.php` | vínculo da tarefa com outro recurso (processo, card, cliente) |
| `TaskTimeEntry.php` | apontamento de horas |
| `TaskReminder.php` | lembretes pendentes, consumidos pelo cron |
| `TaskRecurrence.php` | a regra de repetição: calcula a próxima data a partir de uma data dada |
| `RecurrenceCronService.php` | serviço que o cron chama para materializar as tarefas recorrentes vencidas |
| `TaskAudit.php` | propaga evento de tarefa para o histórico do processo vinculado |
| `TaskEntrega.php` | grava a "foto" de cada entrega em `task_entregas` (prazo e checklist do momento), matéria-prima do OTIF. Chamado por `Task::complete()` e pelo `RecurrenceCronService` |
| `Otif.php` | calcula o OTIF (On Time In Full) por colaborador: métricas, ranking, série mensal e pendências. Tela em `public/desempenho.php`, API em `public/api/otif.php` |

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
