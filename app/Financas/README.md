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
