# app/Projetos

A pasta **Projetos** da edição CRM, pedida pela Inovaize em 14/10/2026: todos os
projetos que a empresa roda num lugar só. No menu é uma pasta própria (como
Operação e Comunicação): **Visão geral** e, abaixo, cada projeto-mãe. Cada projeto
tem a sua página com gráficos, os subprojetos, as contas que usam o projeto (quanto
cada uma paga e consome, custo por usuário), os acessos (links, login, painel
master), custos e receitas, e o dossiê do que falta preencher.

| Arquivo | O que faz |
|---|---|
| `Projeto.php` | chave por conta, validação, as contas de dinheiro (equivalente mensal, dólar, rateio de servidor, arrecadado, evolução mês a mês), pendências do dossiê, hierarquia (mãe e subprojetos, `agregar`), o painel que a tela consome, CRUD de projeto, item e servidor, e a importação de um dossiê em JSON |
| `Conta.php` | contas de um projeto (`projeto_contas`): validação, rateio do custo do projeto entre elas, CRUD e a gravação da medição automática (`sincronizar`) |
| `Acesso.php` | acessos de um projeto (`projeto_acessos`): link, usuário e senha cifrada; CRUD e `revelar` |
| `Evolution.php` | a aba **Evolution** (menu Evolution › Conexões): lê o servidor de WhatsApp (só leitura), limpa o fetchInstances, rateia o custo entre as conexões, liga instância a projeto (gravado ou sugerido) e guarda a foto diária (`evolution_snapshots`, migration 148) para o volume por dia |

### A aba Evolution (`Evolution.php`, `public/evolution.php`, `public/api/evolution.php`, `public/assets/evolution.js`)

- **Só leitura no Evolution.** Usa a configuração global do Painel Master
  (`WhatsAppProvisioningService::globalCfg`: endereço + chave de administrador
  cifrada) e chama só `GET /` (versão) e `GET /instance/fetchInstances`. Nada aqui
  conecta, desconecta, reinicia ou apaga instância (risco 2 do CLAUDE.md).
- O `token` de cada instância vem no fetchInstances e é descartado em
  `normalizar()`; nunca chega à API nem à tela. Item sem nome é ignorado.
- Situação: `open` conectada; `connecting` presa tentando reconectar (ocupa o
  servidor e não funciona; a tela avisa e lista); `close` desconectada.
- Custo mensal do Evolution informado na tela (`configuracoes.evolution.custo_mensal_centavos`),
  rateado igual entre as conexões que ocupam o servidor (open e connecting), com
  soma exata. Por projeto = soma das instâncias dele.
- Projeto da instância: vínculo gravado (`configuracoes.evolution.vinculos`, nome →
  projeto da mesma conta) ou, sem vínculo, sugestão: a instância é de uma conta
  deste sistema (`whatsapp_instances`) cadastrada como conta de um projeto ("conta
  #N"). A sugestão lê só nome da instância e id da conta, e só das contas já
  cadastradas nos projetos.
- O Evolution só dá totais acumulados. Cada abertura da aba grava a foto do dia
  por instância (mesmo dia atualiza, não duplica); o volume por dia é a diferença
  entre fotos (primeiro dia desconhecido; instância recriada não dá negativo).
- Mesma condição da pasta Projetos: conta com o módulo ligado e administrador.

Tela: `public/projetos.php` (`?p=<slug>` abre a página do projeto) +
`public/assets/projetos.js` + `public/assets/projetos.css`. API:
`public/api/projetos.php`. Tabelas: migrations 146 (`projeto_servidores`,
`projetos`, `projeto_itens`) e 147 (`projetos.parent_id`, `projeto_contas`,
`projeto_acessos`). Teste: `scripts/tests/projetos_test.php`. Scripts:
`scripts/manutencao/ligar_projetos.php`, `semear_projetos.php` e
`sincronizar_contas_projetos.php`.

## O modelo

- **Servidor** (`projeto_servidores`): máquina ou hospedagem com custo mensal (R$
  ou US$). Vários projetos dividem o mesmo servidor.
- **Projeto** (`projetos`): cliente, tipo (SaaS próprio, cliente, interno, site,
  automação), situação (produção, desenvolvimento, pausado, encerrado), endereço,
  repositório, stack, servidor + peso no rateio, usuários ativos, responsável e
  `parent_id` (a mãe, para subprojeto).
- **Item** (`projeto_itens`): uma receita ou um custo do projeto. Categoria,
  fornecedor, valor unitário x quantidade, moeda, recorrência (mensal, anual,
  único) e vigência (início e fim).
- **Conta** (`projeto_contas`): quem usa o projeto (escritório do Yuris, cliente da
  plataforma Fleetiflow). Plano, situação (ativa, em teste, inadimplente,
  cancelada), quanto paga por mês, usuários, peso de consumo, custo direto, início,
  e as métricas da última medição automática.
- **Acesso** (`projeto_acessos`): rótulo, endereço, usuário e senha cifrada.

## Regras

- **Área responsável** (ambientes da Inovaize, 20/10/2026, migration 159):
  `projetos.area` (software, digital, empresa; vazio = software), gravada à parte
  por `Projeto::definirArea` só quando a tela manda o campo. É o que coloca o projeto
  na Visão Geral de Software.

- **Isolamento.** Toda leitura e escrita recebe a lista de contas acessíveis e
  filtra por ela nas seis tabelas. Item, conta e acesso são criados na conta do
  projeto; projeto só liga em servidor e em mãe da **mesma** conta.
- **Valor vazio é "a preencher", nunca zero.** Fica fora das somas e vira
  pendência do dossiê. Vale para item, servidor e receita de conta que paga.
- **Dólar** é convertido pela cotação da conta (`configuracoes.projetos.cotacao_usd`,
  padrão `COTACAO_PADRAO`), editável na tela.
- **Equivalente mensal:** mensal = total; anual = total/12; único não entra no mês.
  Item fora da vigência vale zero no mês.
- **Rateio de servidor:** o custo mensal do servidor é dividido entre os projetos
  **não encerrados** que rodam nele, pelo `servidor_peso`, com soma exata. No resumo
  da carteira o servidor entra inteiro, mesmo sem projeto ligado.
- **Contas:** receita do projeto = itens de receita + o que pagam as contas
  `ativa`/`inadimplente`. Custo atribuído a cada conta = custo compartilhado do
  projeto (itens + servidor) dividido pelo `peso_consumo` entre as contas que
  consomem (todas menos `cancelada`), mais o `custo_direto` dela, que também soma
  no custo do projeto. Custo por usuário = custo / usuários. Usuários do projeto: o
  número informado nele ou, vazio, a soma das contas.
- **Hierarquia, um nível só.** Subprojeto não tem filho e projeto com filho não
  vira subprojeto (`paiValido`). A mãe mostra os números dela somados aos dos
  subprojetos não encerrados (`agregado`, inclusive a evolução mês a mês). Mãe com
  subprojeto ativo não cobra de si servidor, endereço, stack, receita, usuários e
  custos: isso é pendência dos subprojetos. Excluir a mãe não apaga os filhos: eles
  sobem para o primeiro nível. Excluir projeto leva itens, contas e acessos dele.
- **Evolução** (`evolucao`, gráficos): receita e custo dos últimos 12 meses pelo
  cadastro. Item vale nos meses em que está vigente (único só no mês do início;
  sem início, não entra); conta vale a partir do início dela (sem início, sempre);
  servidor a partir do início do projeto (sem início, sempre). É o previsto, não
  extrato.
- **Arrecadado** é o previsto pelo contrato até hoje: cobranças vencidas desde o
  início da receita, até o fim se houver. Receita sem início vira pendência.
- **Senha de acesso** é gravada cifrada (`App\Core\Crypto`). O painel nunca devolve
  senha, só `tem_senha`; `revelar` decifra sob pedido e a API audita
  (`projeto.acesso_revelado`). Editar com senha em branco mantém a guardada;
  `limpar_senha` apaga. Sem chave de criptografia, salvar senha é recusado. O
  dossiê importado nunca traz senha (o campo é descartado).
- **Medição automática das contas** (`sincronizar_contas_projetos.php`): o Yuris e
  as edições CRM rodam neste sistema, então o script mede cada conta (usuários,
  mensagens de WhatsApp e IA em 30 dias, clientes, processos, leads, plano e preço
  de tabela) e grava a foto na conta destino, casando pelo identificador
  (`conta #ID`). O custo de IA vira custo direto. Receita, peso e observação são da
  pessoa e nunca são tocados. É manutenção por CLI: a tela e a API nunca leem dado
  de outra conta.
- **Importação** (`importar`): servidor casa pelo nome, projeto pelo slug, item por
  natureza + categoria + descrição, conta pelo nome e acesso pelo rótulo. Não
  duplica e só completa campo vazio. `pai` liga o subprojeto à mãe (se ainda não
  tem); `excluir_itens` tira item que ainda está sem valor. O dossiê da Inovaize é
  dado de negócio e fica fora do repositório.
- **Módulo por conta:** `configuracoes.modulos.projetos = true`
  (`habilitado()`/`ligar()`), só edição CRM; conta Yuris nunca. Acesso só de
  administrador (vendedor recebe 403 na API e volta para o painel na tela). No
  menu, pasta própria com a permissão de Finanças.
