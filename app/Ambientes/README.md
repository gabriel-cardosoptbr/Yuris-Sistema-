# app/Ambientes

Os **ambientes** da Inovaize (pedido de 20/10/2026): **Empresa**, **Comercial**,
**Software** e **Digital / Marketing**. Um seletor no topo do menu, abaixo da
logo, troca o menu inteiro, a Visão Geral, os indicadores e os atalhos. O login é
o mesmo e os cadastros são os mesmos: o ambiente decide o que o menu mostra e,
nas telas que mudam por área (Tarefas e Clientes), o filtro. Nada é copiado.

Só vale na conta com o módulo ligado (`accounts.configuracoes.modulos.ambientes`,
`Ambiente::ligar`), hoje só a Inovaize (conta 119). Nas outras contas o menu e as
telas são os de sempre.

| Arquivo | O que faz |
|---|---|
| `Ambiente.php` | a lista dos ambientes, o menu de cada um (`LAYOUT`, montado em cima das páginas que a pessoa já via no menu de sempre), quem entra em cada um (`podeEntrar`), a regra que decide o ambiente de cada página (`resolver`), o último ambiente por pessoa (`user_ambientes`) e a guarda das Visões Gerais (`exigir`) |
| `QuadroDaArea.php` | o quadro de Tarefas como quadro de uma área: `task_boards.area`, `cliente_id` e `modelo` (colunas iniciais: etapas, episódios, campanhas, demandas, suporte, livre), filtro da lista e o nome do cliente no seletor |
| `AreaDoCliente.php` | em que áreas cada cliente está, sem cadastro duplicado: Software pelo valor com área software ou quadro de Software; Digital pelo valor com área digital, quadro de Digital, frequência contratada ou campanha; e, nas duas, a área **marcada direto na ficha** (`crm_cliente_areas`, migration 161; `marcar`, `marcadas`), para o cliente recém-fechado que ainda não tem nada disso. A ficha mostra os botões em "Operação" (aba Visão geral) |
| `Visao.php` | os números das Visões Gerais (Empresa, Software, Digital) e as vendas por área do serviço do Comercial. A Empresa junta um bloco por área: `financeiro`, `clientesDetalhe`, `software`, `digital` (marketing), `comercialPorArea`, `contratos`, `equipe` (OTIF do mês e evolução por `App\Tarefas\Otif`) e `series` (as séries dos gráficos: 12 meses de dinheiro e de clientes, 6 de entregas e de contratos, 8 semanas de tarefas concluídas por área) |
| `FichaDoCliente.php` | **a ficha do cliente de cada ambiente** (21/10/2026): o perfil por área (`crm_cliente_perfil`, migration 162: redes, site e briefing da marca no Digital; endereço do sistema, repositório, stack, hospedagem e notas técnicas no Software; cada área grava só os campos dela), a postagem do mês (entregas com a situação da publicação, o link do post e a primeira arte do Drive ligada ao cartão), o link do post (`salvarLinkPublicacao`, quem edita o cartão), o bloco de Software (demandas abertas e atrasadas, concluídas no mês, suporte, quadros), os projetos da pasta Projetos ligados ao cliente (`projetos.cliente_id`), as negociações (as prospecções que apontam para o cliente) e o miolo do card de cada cliente no quadro (`quadro`) e o quadro de entregas da aba Produção (`producao`, `novaEntrega`: sem quadro de Digital, cria "Produção · Cliente") |
| `Paginas/visao.php` | a página das Visões Gerais, incluída por `public/empresa.php`, `software.php` e `digital.php` (e as telas Entregas e Campanhas do Digital, `?ver=`) |

Telas: `public/empresa.php`, `public/software.php`, `public/digital.php` (finas),
`public/assets/ambientes.js` + `ambientes.css` (seletor, guarda de formulário,
faixa de Clientes, bloco do Comercial), `ambientes-visao.js` (Visões Gerais,
Entregas, Campanhas) e `ff-cliente-operacao.js` (a ficha e o card de cada
ambiente). API: `public/api/ambientes.php` e `public/api/digital.php`.
Migrations 159, 161 e 162. Teste: `scripts/tests/ambientes_test.php`.

## O menu de cada ambiente

| Ambiente | Visão Geral | Menu |
|---|---|---|
| Empresa | `/empresa`, **a visão de tudo** (20/10/2026): resumo no topo e uma seção por área, com navegação rápida. **Comercial** com os mesmos números do painel comercial no mês (a tela chama `api/dashboard_comercial.php` com o mês: novos leads, oportunidades, vendas, receita fechada, conversão, ticket, meta, funil por etapa) e as vendas por área do serviço; **Financeiro** (receita recorrente por área, previsto, recebido e a receber no mês, em atraso, próximos 7 dias, plano fixo do DRE, dinheiro por área); **Clientes** (ativos, novos no mês, por área, nas duas, sem área, inadimplentes, sem responsável, por situação); **Marketing** (contratadas, concluídas, postadas, agendadas, a postar, em produção, aguardando aprovação, atrasadas, campanhas e verba, e por cliente o que foi postado e o que não foi, com o ritmo do mês); **Software** (projetos por situação e por responsável, quadros, tarefas, suporte, próximos prazos); **Contratos** (só para quem vê contrato); **Equipe** (OTIF do mês, abertas e atrasadas por pessoa); atrasos, pendências e a conexão do Google Drive. **Em gráficos** (pedido do dono do produto, 20/10/2026): cada área tem uma faixa de números e gráficos Chart.js (o mesmo do painel comercial, nas cores da marca): Comercial (receita fechada, meta e leads em 12 meses; funil por etapa; vendas por área), Financeiro (previsto, recebido e receita recorrente em 12 meses; recebido por área), Clientes (novos e base acumulada em 12 meses; por área), Marketing (contratadas, concluídas e postadas em 6 meses; situação do mês; por cliente), Software (projetos por situação e por responsável; tarefas concluídas por semana), Contratos (por situação; enviados e assinados por mês) e Equipe (tarefas por pessoa; OTIF em 6 meses). As séries vêm de `Visao::series`; Software e Digital usam `Visao::fluxoSemanal` e `Visao::entregasPorMes` | Cadastros (Clientes, Usuários, Setores), Contratos, Financeiro, Projetos, Gestão (Tarefas, Relatórios, Desempenho, Escritórios), Infraestrutura (Evolution, Agente, Webhooks) |
| Comercial | o painel comercial de sempre (`/dashboard`), com o bloco "Vendas por área do serviço" | Vendas (Planejamento, Prospecção, Orçamentos, Plataforma, Clientes), Contratos, Comunicação (WhatsApp, Chat Interno, Agente), Metas e resultados |
| Software | `/software`, em gráficos (20/10/2026): fluxo de tarefas por semana (criadas x concluídas), abertas por prioridade, projetos por situação e por responsável, tarefas abertas e atrasadas por quadro e por pessoa, próximos prazos e a carteira | Operação (Demandas e suporte, Clientes de Software), Projetos, Infraestrutura (Evolution), Equipe |
| Digital | `/digital`, em gráficos (20/10/2026): entregas por mês (contratadas, concluídas, postadas), situação do mês, por cliente e por tipo de entrega, fluxo de cartões por semana, cartões por pessoa, próximos prazos e a tabela por cliente com postagem e ritmo | Operação (Clientes de Digital, Produção, Entregas, Campanhas), Equipe |

A Visão Geral é sempre o primeiro item. Cada item continua com a permissão de
sempre (o filtro do sidebar não muda); contrato continua só para quem vê contrato
e Projetos só com o módulo de Projetos.

## A ficha do cliente em cada ambiente (21/10/2026)

Pedido do dono do produto: cada ambiente abre o cliente como um sistema à parte.
O cadastro é um só; o ambiente do menu (`aside[data-ambiente]`) decide as abas, as
seções e o card do quadro (`public/assets/ff-cliente-operacao.js`, dados de
`api/digital.php?ficha=` e `?quadro_clientes=`):

| Ambiente | Abas | Card do quadro de Clientes |
|---|---|---|
| Digital | Visão geral (resumo do mês com ritmo e próximas entregas, redes e marca, operação), Produção, Entregas, Postagem (grade com a arte do Drive, situação, link do post, Instagram), Arquivos (Drive), WhatsApp, Histórico. **Sem** financeiro, contratos, dados pessoais, endereço, origem e negociações | entregas do mês (concluídas de contratadas, barra), postadas, agendadas, com o cliente, ritmo, Instagram e próxima entrega; sem dinheiro |
| Software | Visão geral (resumo, perfil técnico), Projeto e demandas (quadros de Software, demandas abertas, projetos ligados), Arquivos, WhatsApp, Histórico. Sem financeiro e sem Digital | demandas abertas, atrasadas ou próximo prazo, quadros |
| Comercial | Visão geral (dados, financeiro, origem, negociações, contratos), WhatsApp, Arquivos, Histórico. Sem produção | o de sempre (dinheiro) |
| Empresa | tudo: as de cima juntas (Produção, Entregas, Postagem, Software) | dinheiro e uma linha por área ("Digital: 2 de 10 entregas", "Software: 4 demandas abertas") |

- **O Google Drive do cliente abre a Visão geral** no Digital, no Software e na
  Empresa (vincular a pasta, navegar, pré-visualizar, enviar arquivo, acessos) e
  continua na aba "Drive e arquivos". No Comercial não aparece.
- A faixa do topo troca "Origem" por **Instagram** (Digital) ou **Sistema** (Software).
- Seção que outro script põe na ficha (Financeiro, Contratos, Setores do
  `ff-cliente-crm.js`) entra no mapa pelo título (`MAPA`); seção nova precisa de
  linha no mapa, senão aparece onde o script a pôs. As seções ficam sem número
  (as escondidas deixariam buraco na contagem).
- O ambiente organiza, não dá permissão: quem não vê Tarefas não recebe postagem
  nem Software; projetos só com o módulo Projetos e sem vendedor; negociações só
  para quem vê a Prospecção ou o painel.

## Regras

- **O ambiente de cada página** (`resolver`): `?amb=` no endereço, se a pessoa pode
  entrar; senão, a página que só existe num ambiente decide; página em vários
  ambientes fica no último usado (se for um deles); página fora de todos
  (Configurações) também. Link direto e atualizar o navegador mantêm o menu certo.
  Página que aparece em mais de um ambiente ganha `?amb=` no link do menu.
- **Quem entra** (`podeEntrar`, mesma regra no seletor, nas páginas e na API):
  Empresa, dono, administrador ou quem vê Finanças; Comercial, quem vê o painel ou a
  Prospecção; Software, quem vê Tarefas ou Finanças; Digital, quem vê Tarefas ou
  Clientes. Vendedor da edição CRM não entra na Empresa; no Digital vê só os
  próprios clientes.
- **Sem contar duas vezes** (Empresa): cliente nas duas áreas conta uma vez no total;
  a receita recorrente soma cada linha de valor uma vez, na área da linha; a venda
  segue a regra do painel comercial (`CobrancaDoCliente::sqlFechamentoSemDuplicar`)
  e o mesmo cliente na mesma data é uma venda só.
- **Alteração pendente**: formulário visível em que a pessoa mexeu e não salvou fica
  marcado; sair pelo menu ou pelo seletor abre um aviso (modal, sem diálogo do
  navegador). Enviar o formulário, ou ele sumir da tela, tira a marca.
- **Título**: o nome do ambiente entra antes do título da página ("Software · ...").
- Ao trocar o ambiente pelo seletor, a pessoa vai para a Visão Geral dele, e o
  ambiente fica gravado como o último usado.
