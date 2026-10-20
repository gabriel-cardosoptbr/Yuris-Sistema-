# Clientes/ — a base real de clientes do escritório

Tela: **Operação › Clientes** (`public/clientes.php`, `public/api/clientes.php`).

É a base de quem **já é cliente**, com um kanban próprio cujas colunas são
etapas operacionais do escritório (não etapas de venda). Quem ainda é
oportunidade de venda vive em [`../Prospeccao/`](../Prospeccao/).

## Arquivos

| Classe | O que faz |
|---|---|
| `Cliente.php` | o cliente: cadastro, listagem por conta, movimentação entre setores. `registrarEvento()` é o ponto público por onde outros módulos escrevem no histórico dele, para o formato não divergir |
| `ClienteOrigem.php` | de onde o cliente veio (indicação, site, anúncio). Lista editável por conta, não é enum fixo |
| `ClienteSetor.php` | as colunas do kanban de Clientes, também editáveis por conta |
| `VinculosCliente.php` | o que está ligado ao cliente e mora do lado da prospecção: conversa de WhatsApp e tarefa. Resolve pelas prospecções de origem em vez de duplicar o vínculo. A conversa também é achada **pelo número do próprio cliente** (`whatsapp` ou `telefone`, com e sem o nono dígito, inclusive conversa gravada sob `@lid`): cliente cadastrado direto, sem prospecção, abria a ficha sem a conversa. Continua sendo resolução, não vínculo gravado. **Tarefa não tem `account_id`**: a conta vem do quadro, e o JOIN em `task_boards` é o que impede vazamento entre escritórios |
| `ValoresDoCliente.php` | **o Financeiro da ficha do cliente** (edição CRM, 20/10/2026): os valores combinados, uma linha cada (`crm_cliente_valores`, migrations 152 e 153): mensal (com dia de vencimento), anual, serviço avulso ("fez um site"), taxa fixa ou única, por demanda, com "começa em", fim, ativo, observação e, se quiser, o contrato do cliente a que se refere. `numero()` lê "2.500" e "1.250.000" como milhar (antes, "2.500" virava R$ 2,50); "2.5" e "1234.56" seguem decimais. Sem descrição, vale o nome do tipo ("Mensalidade", "Serviço"). **"Começa em" é obrigatório** (para mensal e anual, quando a cobrança começa; para serviço e taxa, a data da cobrança); só "por demanda" dispensa. `resumo` (pura) soma o total por mês (mensal + anual/12) e os outros à parte, sem linha encerrada ou vencida. Salvar chama `CobrancaDoCliente::sincronizar`. Encerrar guarda o histórico; remover é para lançamento errado e é recusado se o valor já tem cobrança paga. API `public/api/cliente_valores.php`; tela por `public/assets/ff-cliente-crm.js`. Teste: `scripts/tests/cliente_valores_test.php`. Na conta com ambientes, cada linha tem **área da receita** (`area`: software, digital, outro; `AREAS`, migration 159): a Visão Geral da Empresa soma cada linha uma vez, na área dela; a coluna só muda quando o pedido traz o campo |
| `CardDoCliente.php` | **o card do cliente no quadro de Clientes** (edição CRM, 20/10/2026): de uma vez para o quadro inteiro, o cadastro (nome, tipo, cidade, contato, situação, responsável, cliente desde), a coluna principal e as outras colunas do cliente, o dinheiro (total por mês, serviços e taxas, o que está em atraso, a próxima cobrança, o já recebido), os contratos (total, assinados, aguardando; zerados para o vendedor, que não vê contrato) e o último contato registrado. Com `$soDoResponsavel` (o vendedor da sessão), só os clientes dele. Lido por `GET /api/cliente_valores.php?quadro=1`; a tela é `public/assets/ff-cliente-crm.js` |
| `SetoresDoCliente.php` | **setores do cliente** (edição CRM, 20/10/2026): as colunas do quadro de Clientes (`clientes_setores`: Atrasado, Em dia...) em que o cliente está. A principal continua em `clientes.setor_id` (é onde o card é arrastado); as outras ficam em `crm_cliente_setores_extras` (migration 155), e o card aparece também em cada uma. `salvar` troca só as de mais (a principal não entra) e recusa coluna de outra conta. `quadro` dá, por cliente, as colunas a mais, o total por mês e serviços e taxas. Os setores da equipe (`teams`, Advocacia, Estética) não são setor de cliente: a migration 154 tinha usado eles e a 155 desfez |
| `CobrancaDoCliente.php` | **as contas a receber dos clientes** (edição CRM, 20/10/2026, `crm_cliente_cobrancas`, migration 153): as cobranças que saem dos valores. Mensal: uma por mês desde "começa em", no dia de vencimento (dia 31 em mês curto cai no último dia); anual: uma por ano; serviço e taxa: uma só, na data; por demanda: nenhuma. Geradas até o fim do mês seguinte, ao salvar o valor e pelo `bin/cobrancas_clientes_worker.php` diário; `(valor_id, competencia)` é único, então gerar de novo não duplica. Não gera para trás antes do mês do cadastro (mensalidade antiga cadastrada hoje não vira pilha de atrasadas). Mudou o valor: refaz as **abertas** de hoje em diante, a paga e a vencida ficam. Encerrou: as abertas futuras viram canceladas ("Valor encerrado"); reativou ou estendeu o período: as canceladas pelo sistema voltam (a cancelada à mão não). `baixar` (data, valor recebido, forma), `desfazer`, `cancelar`; `doCliente` (lista e totais da ficha) e `painel` (números do mês e lista do painel "Recebimentos de clientes" de Finanças). `reabrir` desfaz um cancelamento. Cancelada à mão volta sozinha só quando o valor é corrigido e ela tinha o valor ou o vencimento antigos (o caso de "2.500" lido como R$ 2,50, cancelado e depois corrigido). `sqlFechamentoSemDuplicar` é a regra que evita receita em dobro no DRE e no painel comercial: o negócio fechado cujo cliente tem valor que vira cobrança (tudo menos "por demanda") sai da soma de fechamentos, porque a receita dele passa a ser o que ele paga. "Hoje" é o de Brasília; toda consulta filtra `account_id` |

## Por que origem e setor são tabela, e não constante no código

Porque cada escritório organiza do seu jeito. Se você precisar acrescentar uma
origem ou um setor "padrão", o lugar é o seed de conta nova
(`../Master/AccountBootstrapSeeder.php`), não uma constante em PHP: uma
constante valeria para todos os tenants e tiraria do cliente a capacidade de
editar.

## Regras

**Sempre filtrar por `account_id`.** Vale o mesmo de todo o resto do sistema.

**Cliente tem relação com processo e com conversa de WhatsApp.** Antes de
apagar ou fundir cliente, verifique o que aponta para ele. Exclusão aqui não é
operação isolada.
