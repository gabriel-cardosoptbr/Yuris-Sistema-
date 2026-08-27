# Prospeccao/ — o funil de vendas do escritório

Tela: **Operação › Prospecção** (`public/prospeccao.php`, `public/api/cards.php`).

É um kanban de oportunidades: cada card é um contato que pode virar cliente, e
as colunas são as etapas do funil. Quando o card vira cliente de fato, o
assunto passa a ser de [`../Clientes/`](../Clientes/), que é outro módulo, com
outra tela.

**Prospecção e Clientes são coisas diferentes.** Até 2026-08-27 os dois moravam
na mesma pasta e isso confundia: um é funil de venda, o outro é a base
operacional de quem já é cliente.

## Arquivos

| Classe | O que faz |
|---|---|
| `Card.php` | o card do funil. Listagem, movimentação entre colunas, reordenação em lote |
| `CardChecklist.php` | checklist dentro do card |
| `PipelineColumn.php` | as colunas do funil, configuráveis por conta |
| `Contato.php` | pessoa por trás do card: nome, telefone normalizado, e-mail |

## Sobre o `Contato`

Ele está aqui porque nasceu aqui, mas **é usado também fora da Prospecção**:
por `public/api/processes.php` e pelo handoff do agente de IA
(`../WhatsAppAgente/AiIntake/HandoffService.php`). Ao mexer nele, considere
esses três chamadores, não só o funil.

O telefone é normalizado para só dígitos com DDI 55, e `null` quando tem menos
de 10 dígitos. É essa normalização que permite casar um contato do CRM com uma
conversa do WhatsApp, então mudá-la tem efeito no módulo de Comunicação.

## Regras

**Card sempre listado com filtro de conta.** `Card::list()` exige `account_id`
e nunca deve ser chamado sem ele. A listagem já inclui os cards compartilhados
com a conta via `resource_shares`, e essa junção é intencional.

**Reordenação é em lote.** Arrastar um card renumera vários; use o caminho de
`bulkUpdateOrders`, não um update por card, ou a ordem sai inconsistente sob
concorrência. `../Tarefas/Task.php` segue o mesmo padrão, de propósito.
