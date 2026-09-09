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
| `VinculosCliente.php` | o que está ligado ao cliente e mora do lado da prospecção: conversa de WhatsApp e tarefa. Resolve pelas prospecções de origem em vez de duplicar o vínculo. **Tarefa não tem `account_id`**: a conta vem do quadro, e o JOIN em `task_boards` é o que impede vazamento entre escritórios |

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
