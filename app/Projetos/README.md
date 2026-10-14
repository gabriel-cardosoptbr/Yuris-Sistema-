# app/Projetos

A aba **Projetos** da edição CRM (menu Financeiro › Projetos), pedida pela
Inovaize em 14/10/2026: todos os projetos que a empresa roda num lugar só, com o
que cada um consome, quanto arrecada, a margem, e um dossiê por projeto que mostra
o que ainda falta preencher.

| Arquivo | O que faz |
|---|---|
| `Projeto.php` | regra inteira do módulo: chave por conta, validação, as contas de dinheiro (equivalente mensal, conversão do dólar, rateio de servidor, arrecadado), as pendências do dossiê, o painel que a tela consome, o CRUD de projeto, item e servidor, e a importação de um dossiê em JSON |

Tela: `public/projetos.php` + `public/assets/projetos.js` + `public/assets/projetos.css`.
API: `public/api/projetos.php`. Tabelas: migration 146 (`projeto_servidores`,
`projetos`, `projeto_itens`). Teste: `scripts/tests/projetos_test.php`.
Scripts: `scripts/manutencao/ligar_projetos.php` e `semear_projetos.php`.

## O modelo

- **Servidor** (`projeto_servidores`): máquina ou hospedagem com custo mensal (R$
  ou US$). Vários projetos dividem o mesmo servidor.
- **Projeto** (`projetos`): cliente, tipo (SaaS próprio, cliente, interno, site,
  automação), situação (produção, desenvolvimento, pausado, encerrado), endereço,
  repositório, stack, servidor + peso no rateio, usuários ativos, responsável.
- **Item** (`projeto_itens`): uma receita ou um custo do projeto. Categoria
  (mensalidade, implantação... / servidor, IA, WhatsApp, APIs, domínio, licença por
  usuário, ferramentas, equipe), fornecedor, valor unitário x quantidade, moeda,
  recorrência (mensal, anual, único) e vigência (início e fim).

## Regras

- **Isolamento.** Toda leitura e escrita recebe a lista de contas acessíveis e
  filtra por ela nas três tabelas. Item é criado na conta do projeto; projeto só
  liga em servidor da **mesma** conta (`servidorDaConta`), senão fica sem servidor.
- **Valor vazio é "a preencher", nunca zero.** O item existe porque o projeto
  consome aquilo; sem número, fica fora das somas e vira pendência do dossiê.
- **Dólar** é convertido pela cotação da conta (`configuracoes.projetos.cotacao_usd`,
  padrão `COTACAO_PADRAO`), editável na tela.
- **Equivalente mensal:** mensal = total; anual = total/12; único não entra no mês.
  Item fora da vigência (antes do início ou depois do fim) vale zero no mês.
- **Rateio de servidor:** o custo mensal do servidor é dividido entre os projetos
  **não encerrados** que rodam nele, pelo `servidor_peso`; os centavos que sobram
  vão para os primeiros, e a soma é sempre o total. No resumo da carteira o
  servidor entra inteiro, mesmo sem projeto ligado (a fatura chega igual).
- **Arrecadado** é o previsto pelo contrato até hoje: cobranças vencidas desde o
  início da receita (a primeira vence no próprio início; dia 31 em mês curto vence
  no último dia), até o fim se houver. Não é extrato bancário. Receita sem início
  não calcula e vira pendência.
- **Dossiê:** `calcular()` monta um checklist do que se aplica ao projeto
  (cliente só para projeto de cliente, receita e usuários só para quem cobra,
  endereço só para o que está em produção...) e devolve as pendências e o % de
  completude. Nada disso é gravado.
- **Importação** (`importar`, usada por `semear_projetos.php`): casa servidor pelo
  nome, projeto pelo slug e item por natureza + categoria + descrição. Não duplica
  e, em registro existente, só completa campo vazio: nunca sobrescreve o que alguém
  preencheu na tela. O dossiê da Inovaize é dado de negócio e fica fora do
  repositório.
- **Módulo por conta:** `configuracoes.modulos.projetos = true`
  (`habilitado()`/`ligar()`), só edição CRM; conta Yuris nunca, mesmo com a chave.
  Acesso só de administrador (vendedor da edição CRM recebe 403 na API e volta para
  o painel na tela). No menu, aparece no grupo Financeiro com a permissão de Finanças.
