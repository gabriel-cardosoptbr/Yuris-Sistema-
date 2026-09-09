# Crm/ — o que vale para os DOIS lados: prospecção e cliente

Telas: **Comercial › Prospecção** (`public/prospeccao.php`) e **Operação ›
Clientes** (`public/clientes.php`). A interface dos quatro blocos é um módulo
compartilhado: `public/assets/crm-fase2.js`.

Esta pasta é a Fase 2 do CRM (09/09/2026). Nasceu de uma pergunta simples: onde
guardar o RG do cliente, a etiqueta "urgente", o campo "NIT" que só um
escritório previdenciário usa, e a ligação de meia hora que alguém fez ontem.

A resposta não podia morar em [`../Clientes/`](../Clientes/) nem em
[`../Prospeccao/`](../Prospeccao/), porque as cinco coisas valem para os dois.
Daí o nome: é do CRM, os dois lados usam.

## Arquivos

| Classe | O que faz |
|---|---|
| `Entidade.php` | **o portão único de tenancy.** `resolver()` recebe (`'cliente'` ou `'card'`, id, contas acessíveis) e devolve a conta DONA, ou `null`. Nenhuma outra classe daqui toca em `clientes` ou `cards` para descobrir dono |
| `Auditoria.php` | um evento, dois destinos, um formato. Manda para `card_history` (colunas) ou `clientes_history` (JSON) conforme a entidade, com a mesma chamada |
| `Anexo.php` | documentos. Não toca no filesystem: quem grava e apaga bytes é `public/api/crm_anexos.php` |
| `Tag.php` | etiquetas, que também são as "classificações" e as "labels": os três nomes, uma estrutura |
| `CampoPersonalizado.php` | definição de campo por conta, e o valor dele por entidade |
| `Interacao.php` | contato registrado e nota interna. Uma tabela, porque nota é interação sem contraparte |
| `Permissao.php` | `crm.catalogos_gerenciar`: quem pode mexer nos catálogos, que é diferente de quem pode usar a ficha |

## A regra que decide tudo aqui: fato não copia, opinião copia

Quando uma prospecção vira cliente:

**FATO se lê junto, nunca se copia.** Anexo, interação e histórico. Um documento
anexado dia 3 **é** o documento daquele dia; copiar criaria duas verdades e a
segunda envelheceria sozinha. A ficha do cliente lê o escopo dela mais o das
prospecções que apontam para ela (`Entidade::escopoLeitura`).

**OPINIÃO EDITÁVEL copia uma vez e fica independente.** Etiqueta e campo
personalizado. "Lead frio" é um juízo sobre a prospecção; se a ficha do cliente
herdasse por leitura, tirar a etiqueta do cliente exigiria editar o card antigo,
o que é ação a distância e ninguém descobre sozinho.

A cópia acontece em `../Prospeccao/ConversaoCliente.php`, uma vez, dentro da
transação da conversão. Campo personalizado tem uma trava a mais: só copia onde
o cliente está **vazio**, porque numa vinculação a cliente que já existe o que
ele já tem preenchido vale mais que o que o lead trouxe.

## Por que vínculo polimórfico, e o preço disso

As seis tabelas guardam `entidade ENUM('cliente','card')` + `entidade_id`, em
vez de duas tabelas espelhadas. O motivo é a leitura: a ficha do cliente precisa
dos dois lados numa consulta só, e com tabelas separadas cada bloco faria dois
SELECTs e um merge em PHP.

O preço é que **o banco não consegue garantir** que o `entidade_id` existe nem
que é da conta certa: não há FK possível para uma coluna que aponta para duas
tabelas. É exatamente por isso que `Entidade::resolver()` existe e é
obrigatório. Um lugar para errar, um para testar, um para consertar.

## Regras

**Nunca escreva sem passar por `Entidade::resolver()`.** E use o `account_id` que
ele devolve, nunca o da sessão: uma sessão matriz alcança a filial, e um anexo
posto num card da filial pertence à filial.

**Conta da entidade e conta do catálogo têm de bater.** `Tag::aplicar()` confere
isso explicitamente. Sem a conferência, as duas checagens de acesso passariam
isoladamente e o vínculo sairia cruzado entre matriz e filial.

**`chave` não é `rotulo`.** O histórico grava a chave do campo personalizado. O
rótulo pode ser reescrito amanhã e o evento de hoje continua dizendo a verdade.

**Registrar interação NÃO grava auditoria**, de propósito. A linha de
`crm_interacoes` (com `created_by` e `created_at`) é a prova da criação, e a
timeline lê a tabela direto. Gravar também no histórico faria o mesmo fato
aparecer duas vezes na mesma tela. Editar e remover, sim, são auditados.

**`ocorrido_em` não é `created_at`.** A ligação de ontem registrada hoje aparece
na timeline no lugar de ontem. Ordenar por `created_at` colocaria o registro
atrasado fora de lugar e a leitura cronológica do relacionamento ficaria errada.

## Onde mais isso encosta

- `../Core/Timeline.php` lê `crm_interacoes` e classifica os eventos novos na
  categoria `interacoes`
- `../Prospeccao/ConversaoCliente.php` faz a cópia de etiqueta e campo
- `public/api/users.php` tem a whitelist `$_validPages`: **chave de permissão que
  não está lá é descartada em silêncio**, o checkbox aparece marcado e não grava
- `database/migrations/run_127.php` cria as seis tabelas, `cards.origem_id`,
  o valor `'cliente'` em `task_links.link_type` e o trigger de imutabilidade de
  `clientes_history`
- `scripts/tests/crm_fase2_test.php` são 110 asserções, e escrevem no banco:
  **não rode em produção**
