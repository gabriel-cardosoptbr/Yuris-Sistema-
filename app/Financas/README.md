# Financas/ — plano de contas do DRE

Tela: **Gestão › Finanças** (`public/financas.php`, `public/api/dre_*.php`).

É a menor pasta de domínio do sistema, e é a mais provável de crescer. Hoje
cobre só a estrutura do **DRE** (Demonstração do Resultado do Exercício): o
plano de contas e os códigos.

## Arquivos

| Classe | O que faz |
|---|---|
| `DREAccount.php` | as contas do plano de DRE, com hierarquia |
| `DRECode.php` | os códigos que classificam cada conta |
| `Reembolso.php` | **os reembolsos** (03/10/2026): o que a empresa devolve a quem pagou uma conta dela do próprio bolso, à vista ou parcelado, e quais parcelas já foram pagas. Painel na tela Finanças da edição CRM. Ver abaixo |

## Reembolsos (`Reembolso.php`)

Pedido da Inovaize (03/10/2026): alguém paga uma conta da empresa do próprio
bolso e a empresa devolve, à vista ou parcelado. Painel "Reembolsos" em
`public/financas.php` (só na **edição CRM**, `$edicaoCrm`; o Yuris não muda),
`public/api/reembolsos.php` e `public/assets/reembolsos.js`. Tabelas
`reembolsos` e `reembolso_parcelas` (migration 138). O que vale ali:

- **Financeiro é de ADM.** Vendedor da edição CRM recebe 403 da API (e
  `financas.php` já o devolve para o painel). Conta Yuris recebe 404.
- **Toda leitura e escrita recebe a lista de contas acessíveis** e filtra por
  ela, inclusive nas parcelas (que também guardam `account_id`). Reembolso de
  outra conta não existe para quem chama: `buscar` devolve null, `atualizar`,
  `marcarParcela`, `quitar` e `excluir` recusam. Criação vai sempre para a
  conta da sessão.
- **Dinheiro em centavos** dentro da classe, `DECIMAL(12,2)` no banco.
  `dividir()` manda os centavos que sobram para as primeiras parcelas
  (100,00 em 3x = 33,34 + 33,33 + 33,33): a soma é sempre o total exato.
- **Vencimento mensal** a partir do primeiro; dia 31 em mês curto cai no último
  dia do mês (31/01 → 28/02 → 31/03), sem pular para o seguinte.
- **A situação não é gravada**, sai das parcelas em `situacao()`: `pago` (todas
  pagas), `atrasado` (alguma em aberto vencida antes de hoje), `pagando`
  (alguma paga) ou `pendente`. "Hoje" é o de Brasília (`hoje()`), porque o
  banco roda em UTC.
- **Com parcela paga, valor e parcelamento ficam travados** (mudar exigiria
  redistribuir dinheiro que já saiu). Descrição, favorecido, data e observação
  continuam editáveis. Sem parcela paga, mudar valor, número de parcelas ou o
  primeiro vencimento refaz as parcelas.
- **Exclusão é lógica** (`deleted_at`). Cada ação vai para a auditoria da conta
  (`reembolso.created`, `.updated`, `.deleted`, `.parcela_paga`,
  `.parcela_desfeita`, `.quitado`).
- **Não entra no DRE.** O painel mostra o que falta devolver, o que já foi
  devolvido e o que está atrasado, mas os números não somam em "Custos
  operacionais". Se um dia entrarem, a despesa é na data do pagamento de cada
  parcela, não na data da despesa.

Teste: `scripts/tests/reembolsos_test.php`.

## Como o plano de contas nasce

Conta nova já vem com um plano de contas padrão, criado pelo
`../Master/AccountBootstrapSeeder.php`. O escritório edita a partir dali. Se
você mudar a estrutura padrão, mexa no seeder, não em constante no código: o
plano é editável por tenant.

## Ao crescer este módulo

Lançamento, conciliação, recebível e relatório ainda não existem. Quando
existirem, entram aqui, e cada um traz seu próprio model junto do service, na
mesma pasta, seguindo a regra de [`../README.md`](../README.md).

Duas coisas a decidir cedo, quando isso acontecer:

- **valor em dinheiro nunca em `float`.** Use inteiro em centavos ou `DECIMAL`,
  ou o arredondamento aparece no relatório do cliente.
- **finanças é dado sensível do escritório.** O filtro por `account_id` vale
  aqui como em todo o resto, sem exceção.
