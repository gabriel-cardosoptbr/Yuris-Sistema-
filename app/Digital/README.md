# app/Digital

A operação de **Digital / Marketing** da Inovaize (20/10/2026), por cliente, no
modelo do Trello. O quadro é o de Tarefas (colunas configuráveis, cartão
arrastável, título, descrição, responsável, prazo, prioridade, checklist com 2/6,
comentários, anexos, histórico); esta pasta junta o que é de marketing.

| Arquivo | O que faz |
|---|---|
| `Entrega.php` | a entrega de um cartão (`task_entregas_digital`): tipo (post, vídeo, reels...) e quatro estados próprios, cada um com a data em que mudou: produção, aprovação do cliente, entrega e publicação. A contagem do mês por cliente e o previsto x realizado (`resumo`). Quem edita é quem edita o quadro (`cartaoEditavel`) |
| `RelatorioEntregas.php` | o **relatório de entregas em Excel** (21/10/2026): uma linha por entrega do período (mês, entrega, tipo, prazo, produção, aprovação e quando foi aprovada, quando foi entregue e publicada, link do post, arquivo do Drive e os outros, responsável, quadro) e a aba "Resumo por mês" (contratado, cadastradas, entregues, publicadas, pendentes por tipo); até 24 meses; planilha pelo `App\Core\PlanilhaXlsx` |
| `Frequencia.php` | a frequência contratada pelo cliente ("6 vídeos por mês", "3 posts por semana"), o previsto do mês |
| `Material.php` | o material que a entrega precisa (`task_arquivos_esperados`): o que falta e o que chegou, com quem, quando e a referência do arquivo |
| `Campanha.php` | campanhas de tráfego pago por cliente: plataforma, objetivo, verba mensal, período, situação, link do gerenciador |

API: `public/api/digital.php`. Telas: a aba "Entrega" do cartão
(`public/assets/tarefas-areas.js`, com o link do post publicado), as abas Produção,
Entregas e Postagem da ficha do cliente (`public/assets/ff-cliente-operacao.js`,
dados de `App\Ambientes\FichaDoCliente`), e Entregas e Campanhas em `/digital`
(`ambientes-visao.js`). Migrations 159 e 162 (`task_entregas_digital.link_publicacao`).
Teste: `scripts/tests/ambientes_test.php`.

## O quadro de entregas da ficha (21/10/2026)

Na aba Produção da ficha do cliente (Digital e Empresa), as entregas do mês num
quadro no modelo do Trello (`App\Ambientes\FichaDoCliente::producao`, tela em
`ff-cliente-operacao.js`): colunas **Em produção**, **Com o cliente** (aprovação
aguardando ou ajustes), **Entregue** e **Publicado**. Arrastar o card muda os
estados com a data (soltar em Entregue marca pronto, aprovado e entregue). Cada
card tem o arquivo do Drive vinculado (miniatura e pré-visualização, "Vincular
arquivo do Drive" pela pasta do cliente), "Editar" (os quatro estados e o link do
post) e o link para o cartão. "+ Nova entrega" cria o cartão no quadro de Digital
do cliente; sem quadro, cria "Produção · Cliente" sozinho (`novaEntrega`).
"Baixar relatório (Excel)" gera o `RelatorioEntregas` do período escolhido. Só
quadros que a pessoa vê; só quem edita o quadro arrasta e edita.

## Regras

- **Entrega não é coluna nem checklist.** O cartão vira entrega quando ganha um
  tipo. As colunas do quadro podem ser etapas, episódios ou campanhas, como o
  projeto quiser; a contagem de entregas vem dos estados da entrega, e item de
  checklist nunca conta como entrega.
- **Cada estado tem dono e data.** Produção (a fazer, em produção, pronto),
  aprovação (não enviado, aguardando, aprovado, ajustes pedidos), entrega (pendente,
  entregue) e publicação (não se aplica, pendente, agendado, publicado). Cada
  mudança vai para o histórico do cartão (`task_history`, `entrega_etapa`).
- **Receber material não aprova, não entrega, não publica.** Registrar o arquivo
  recebido (do Drive, ou enviado pela tela, que vai para o Drive do cliente) marca
  só o recebimento.
- **Contagem do mês** (`Entrega::resumo`): o mês é a competência da entrega (o mês
  do prazo do cartão, ou o da criação; editável). Cada entrega tem um estado só, nesta
  ordem: concluída (entregue), aguardando aprovação, em produção. "Atrasadas" é à
  parte: as não concluídas com prazo vencido. "Contratadas" é a frequência, e "sem
  cartão" é o contratado que ainda não virou cartão.
- **Postado e não postado** (`resumo`): publicadas (publicação "publicado"), agendadas
  e a postar (publicação "pendente"), por cliente e por tipo; entrega que não vai ao
  ar fica "não se aplica" e não entra. **Ritmo do mês**: o esperado até hoje é o
  contratado proporcional aos dias corridos (mês passado: tudo; futuro: nada) e o
  cliente fica "atrasado" quando as concluídas estão abaixo dele. A Visão Geral da
  Empresa mostra isso por cliente e marca quem não postou nada no mês.
- **Previsto x realizado**, por tipo: previsto pela frequência (semanal vira mês
  pelas semanas: 3 por semana em 30 dias = 13), entregues, pendentes. Ex.: 6 vídeos
  previstos, 4 entregues, 2 pendentes.
- **Isolamento**: tudo com `account_id`; cliente de outra conta é recusado em
  quadro, frequência e campanha; vendedor só mexe nos clientes dele.
