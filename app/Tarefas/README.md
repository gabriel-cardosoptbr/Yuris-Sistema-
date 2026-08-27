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
