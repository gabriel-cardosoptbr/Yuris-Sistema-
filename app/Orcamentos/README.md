# app/Orcamentos — o orçamento do CRM (edição Fleetiflow)

Tela **Operação › Orçamentos** (`public/orcamentos.php`), a seção "Orçamentos" da
ficha do lead e a impressão para o cliente (`public/orcamento_imprimir.php`). Conta
jurídica (Yuris) não tem nada disto.

Nasceu em 13/10/2026 ("coloque isso de orçamento no CRM também"), espelhando o
orçamento da plataforma Fleetiflow, que foi refeito no mesmo dia a partir do relato
de um despachante: transferência com IPVA e multas da mesma placa, e as taxas do
órgão contando como lucro.

## O exemplo que guia tudo

| Para o escritório | | Para o cliente | |
|---|---|---|---|
| Valor recebido | R$ 495,91 | Detran | R$ 295,91 |
| Detran (repasse imediato) | −R$ 295,91 | Honorário | R$ 200,00 |
| SERPRO (repasse mensal) | −R$ 8,61 | **Orçamento** | **R$ 495,91** |
| **Serviço / Honorário** | **R$ 191,39** | | |

## Arquivos

| Classe | O que faz |
|---|---|
| `Decomposicao.php` | a conta, PURA (não lê banco): cada linha vira repasses, custos, honorário e o que o cliente lê. **É a mesma regra de `backend/src/modules/financeiro/decomposicao-da-venda.ts` da plataforma**: quem muda uma, muda a outra |
| `Catalogo.php` | a lista inicial de serviços (de despachante) só entra no modo veículo; conta genérica começa vazia (20/10/2026). Os serviços da conta (`crm_servicos`) e as taxas de cada um (`crm_servico_componentes`): REPASSE com prazo (`IMEDIATO`/`MENSAL`) e "cliente vê", ou CUSTO. Conta sem serviço ganha a lista inicial, só com nomes (o preço é do escritório) |
| `Orcamento.php` | `modoVeiculo()` (20/10/2026): veículo (placa, veículo, débitos como IPVA e Detran, honorário) para a Fleetiflow original (sem marca própria) e para quem liga `configuracoes.orcamentos.veiculo` (Via Autodoc); genérico, só serviços, para as outras marcas (Inovaize, Seller Mentoria). A tela, a impressão e a semente do catálogo seguem o modo. O orçamento (`crm_orcamentos`, `crm_orcamento_itens`): criar, editar, aprovar, rejeitar, reabrir, fechar negócio, a ficha com as duas visões e o texto para o WhatsApp |

## Regras

- **Item é SERVICO ou DEBITO.** Débito do veículo (IPVA, multas, dívida ativa,
  licenciamento, taxas diversas) é repasse integral: nunca honorário. Débito não
  carrega serviço do catálogo.
- **Cliente vê** ligado: a taxa sai em linha própria no orçamento do cliente.
  Desligado: fica dentro do honorário que o cliente lê (a SERPRO).
- **Situações**: RASCUNHO → APROVADO → CONVERTIDO ("Negócio fechado"); RASCUNHO ou
  APROVADO → REJEITADO → (reabrir) → RASCUNHO. Editar volta para rascunho.
  Convertido não muda nem se rejeita.
- **Rejeitado sai da lista dos ativos** e fica na aba Rejeitado.
- **Ligado a um lead**: aprovar grava o total em `cards.valor_proposta`; fechar o
  negócio grava `valor_fechado_final` e `data_fechamento` (data de Brasília). Os
  dois entram no histórico do lead como nota (`crm_interacoes`).
- **O cliente nunca vê o controle interno**: a impressão e o texto do WhatsApp usam
  só `para_o_cliente`.
- Numeração por conta (`numero`, único por `account_id`).
- O catálogo (preço e taxas) só muda por dono ou administrador; quem vende só usa.
- Toda consulta filtra `account_id`; id vindo da tela nunca basta sozinho.

Pela ficha do cliente (Clientes, aba Propostas, nos ambientes da Inovaize): as
propostas do cliente num quadro pela situação (`App\Ambientes\QuadrosDaFicha`),
arrastar usa `aprovar`, `converter`, `rejeitar` e `reabrir` desta classe, e "+ Nova
proposta" abre `orcamentos?novo=1&cliente=N`, o formulário já com o cliente (a API
devolve o cliente em `?cliente_ref=N`).

Teste: `scripts/tests/orcamento_crm_test.php`. Migration: `database/migrations/run_143.php`.
