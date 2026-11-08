# Inicio/ — a home do escritório e a interface nova (prévia)

Pasta criada em **07/11/2026**. Guarda a home nova do Yuris, a que abre quando a
pessoa entra, e a chave que decide quem a vê.

**Está em prévia.** Depois da paleta de 06/11/2026 (que subiu para todos e foi
reprovada), mudança de aparência só vai para todos depois que o dono do produto
aprova. Vê quem está numa **conta ligada** (`accounts.configuracoes.interface_nova`,
por `scripts/interface_nova_conta.php --conta=ID --ligar`: a conta inteira, em qualquer
navegador, foi o pedido "sobe o layout completo do sistema inteiro dentro da minha
conta") ou quem abriu uma tela com `?novo=1`. `?novo=0` desliga nos dois casos.

## A paleta (07/11/2026)

A do logo, e nada fora dela, nas três folhas e nos dois temas:

- **Prata**, os cinzas do logo com um fio de azul: toda superfície, os ícones dos
  cartões e a hora do compromisso (caixas afundadas, com sombra interna), as
  iniciais das pessoas. Nunca círculo azul saturado para avatar.
- **Preto**, o traço: texto, ícones, horas.
- **Azul do Yuris, forte e opaco** (nunca neon): só o que é ação ou está selecionado:
  botão principal, pílula ativa, dia escolhido, links. Sem brilho largo em volta.

A madeira que entrou numa rodada anterior (ícones, hora, fio sob a marca) saiu na
mesma noite: "esse amarelo, esse pastel, está quebrando a identidade; o Yuris é
azul, preto e prata".

Vermelho, âmbar e verde continuam semânticos (atraso, atenção, em dia), apagados.
O claro é prata, não branco; o escuro é grafite, não azul-marinho.

## O pedido

"Queria algo no Yuris que remetesse a um escritório, que abrisse e se sentisse no
escritório." E, com as referências de tela: "a home muito mais voltada à
advocacia do que a métricas; o advogado tem que saber o que está acontecendo:
agenda, associados, processos. As métricas podem ficar dentro de Gestão." E o
menu "aprofundado, com a seleção em relevo", como na referência do campo de
seleção em pílula.

## Arquivos

| Arquivo | O que faz |
|---|---|
| `InterfaceNova.php` | a chave da prévia. `ligada($_GET, $_COOKIE, $contaDeMarca)` decide sem efeito colateral; `aplicar()` grava o cookie `yuris_interface_nova` (um ano) quando a URL traz `?novo=1`, e apaga com `?novo=0`; `scriptCookie()` faz o mesmo por JavaScript, para o menu, que entra com os cabeçalhos já enviados. Conta de marca (edição CRM) nunca liga: o desenho dela é outro. É cookie, e não `localStorage` como a paleta, porque o PHP precisa saber antes de desenhar: a home nova é outra página |
| `../../scripts/interface_nova_conta.php` | liga e desliga a prévia numa conta (`--procurar=NOME`, `--listar`, `--conta=ID --ligar/--desligar`), preservando o resto de `configuracoes`. Recusa conta de marca |
| `Painel.php` | o que a home mostra, lido de onde já está, sem copiar: os quatro cartões de cima (prazos urgentes em até 3 dias, processos ativos, publicações pendentes, audiências na semana); o calendário do mês (`porDia`, `gradeDoMes`, todos os itens abertos com data, por dia, para o navegador trocar de mês e de dia sem recarregar); agenda e prazos de `App\Tarefas\Central` (as três tabelas, com a visibilidade de quadros e processos); publicações pendentes de `push_events`; a equipe de `users` + `CargaDaEquipe` + os prazos de processo cujo responsável é o nome da pessoa; processos por última movimentação com a última publicação de cada um; quem espera resposta no WhatsApp pela mesma regra de `MetricasAtendimento::relatorio` (a última mensagem é do contato); e os números do rodapé. As regras puras (saudação, tipo do item, rótulo de vencimento, frase do resumo, situação do processo) ficam separadas das consultas |

## Onde entra

- [`public/dashboard.php`](../../public/dashboard.php) chama `InterfaceNova::aplicar()`
  antes de qualquer saída e, depois de calcular tenant e DRE, entrega
  [`public/includes/dashboard_inicio.php`](../../public/includes/dashboard_inicio.php)
  quando a conta é jurídica, a chave está ligada e não veio `?gestao=1`. Com
  `?gestao=1` o painel de métricas de sempre aparece, acendendo **Gestão ›
  Indicadores** no menu.
- [`public/includes/sidebar.php`](../../public/includes/sidebar.php) lê a mesma
  chave: troca "Visão Geral" por "Início", põe "Indicadores" como primeiro item
  de Gestão e carrega `assets/interface-nova.css`, que veste o menu que já existe
  (painel em relevo, itens em pílula, a página atual numa pílula azul que brilha,
  cada ícone num disco afundado como o do sino, e a aba de recolher na borda do
  painel, metade para fora, como na referência). O HTML do menu não muda.
- A estrutura segue a tela de referência do dono do produto (07/11/2026, segunda
  rodada): faixa de boas-vindas; quatro cartões do que está acontecendo; **Agenda
  jurídica** com o calendário do mês à esquerda e o dia escolhido à direita (clicar
  no dia troca a lista e marca o dia; as setas trocam o mês; sob a grade, atalhos de
  período: hoje, amanhã, esta semana, semana passada, próxima semana e este mês, que
  listam a faixa agrupada por dia e a marcam na grade; tudo com os dados já na página).
  Os dias são caixas afundadas na prata, o escolhido é azul côncavo, como o menu.
  A agenda preenche o cartão inteiro: as semanas do calendário crescem até a altura
  da coluna ao lado, e a lista mostra o dia escolhido e, em seguida, os próximos
  dias (até 12 compromissos), rolando sem barra ("preencha todo o espaço").
  Escolher as datas: clique escolhe um dia; arrastar sobre os dias escolhe um
  período; Shift+clique vai do dia escolhido até o clicado; e o botão "Escolher
  datas" pede o primeiro e o último dia (serve no celular, onde arrastar rola a tela);
  Prazos (os três contadores, hoje, amanhã e na semana, são cartões como os da lista: caixa
  elevada, ícone afundado com traço azul, número e rótulo ao lado; vermelho só em "hoje" com
  prazo, zero apagado; empilham quando o cartão fica estreito: "tá feio, não tá de acordo",
  08/11/2026) e Aguardando retorno ao lado; Publicações, Processos com movimentação e
  Equipe embaixo. Bordas pouco arredondadas (12px nos cartões, 8px no resto):
  "tá muito arredondado, arredondar só um pouco".
- `assets/interface-nova-telas.css` veste **todas as outras telas** (Processos,
  Tarefas, Publicações, Clientes, Chat, Finanças, Configurações...), carregada pelo
  menu fora da home: troca as variáveis de cor que as telas e as folhas globais usam
  (`--panel`, `--bg-main`, `--text`, `--yuris-*`, `--y-*`) e as superfícies (painéis
  na prata com 12px, botão principal azul com 8px, campos afundados, tabelas e abas).
  **Não mexe em layout**: nada de `display`, largura ou posição; o teste trava isso.
- `assets/interface-nova-popups.css` é a única folha da prévia que mexe em **tamanho de
  pop-up**, carregada junto com a das telas (e por isso também no pop-up de verdade aberto
  na home). Hoje: o painel da tarefa vai a 980 x 780 (sem passar da janela) e as abas
  quebram linha em vez de rolar para o lado ("não faz sentido ter rolagem pro lado, tem
  mais espaço; deixa o pop-up maior", 08/11/2026).
- No menu, o grupo que tem a página atual dentro (Gestão em Indicadores, por exemplo)
  leva o ícone azul numa caixa branca, ligado ao rótulo azul ("deixa pelo menos o ícone
  azul e o fundo branco", 08/11/2026). No escuro a caixa é a superfície clara do painel.
- **Um cartão só para todo item** (compromisso, prazo, publicação, processo, conversa,
  pessoa): a mesma caixa (`.ini-cartao`), que é o link para o lugar dele, com a seta azul
  à direita. O azul opaco marca os detalhes: número do processo (`.ini-num`), hora,
  etiquetas, tribunal, grupos, "Responder no WhatsApp". A tabela de processos virou
  cartões. ("Todos têm que ser um card, cada um com a sua função; use o azul nos detalhes.")
- **Pop-up de detalhe** (`public/assets/inicio-detalhe.js`): clicar num cartão abre a
  janela na própria home, sem sair da tela ("queria que abrisse o pop-up direto aqui").
  Cada cartão leva os dados dele em `data-det`; para processo e tarefa a janela busca o
  resto nos endpoints que as telas já usam (`/api/processes.php?id=`,
  `/api/processo_prazos.php?processo_id=`, `/api/tasks.php?id=`), com a mesma checagem
  de conta, e mostra os prazos em aberto do processo com **Concluir** (PATCH no
  `processo_prazos.php`, com o token CSRF). Publicação mostra o teor em tópicos (até
  12.000 caracteres, de `Painel::publicacoes`). Ctrl/Cmd/Shift+clique ainda abre a página.
  Esc, o × e o clique fora fecham.
  **Ações na própria janela** ("não pode ser só o preview"): concluir prazo, tarefa do
  processo e tarefa do quadro (PATCH `processo_prazos.php` / `processo_tarefas.php`, POST
  `tasks.php?action=complete`); tratar a publicação, com nota (`push/persist.php`, ação
  `tratar`); e os botões da faixa
  de boas-vindas: **Tratar publicações** abre a lista das pendentes, cada uma com a
  leitura e o tratamento.
  **A publicação em tópicos** ("tem que separar os OAB, o que é advogado, o que é
  corpo"): `Painel::publicacoes` manda cada publicação já separada por
  `App\Processos\PublicacaoEstrutura` (ver o README de Processos): a grade com tribunal,
  processo, classe e documento, sem vão (se sobra um campo, ele ocupa a linha); o timbre
  miúdo; partes por polo; advogados um por linha com a OAB em azul; cada seção do teor
  com título azul, uma frase por parágrafo e a assinatura à direita; intimados; e "Ver a
  certidão no tribunal". Texto que não dá para separar cai na limpeza do Diário
  (`cleanText` + `_formatLegal` de `intimacoes.js`, copiadas em `inicio-detalhe.js`).
  **O pop-up de verdade do módulo** ("tem que abrir exatamente o pop-up lá do campo, do
  jurídico, de operação: o campo de verdade"): a home não imita a tela do módulo. Abre
  a própria tela num iframe por cima da home, com `?embed=1`, e ela mostra só o pop-up
  dela, com tudo o que ele tem (`public/assets/embed.js` e `embed.css`, carregados pelo
  menu só na interface nova e só com `?embed=1`). O cartão de processo e "Abrir o
  processo" abrem a ficha de Processos (`/processos?open=ID`, com `&foco=prazo:ID` ou
  `tarefa:ID` quando vem de um prazo ou tarefa, e ela desce até ele); **Novo processo**
  abre o cadastro (`/processos?criar=1`); **Nova tarefa** abre o cadastro de Tarefas
  (`/tarefas?nova=1`); a tarefa do quadro abre o painel dela (`/tarefas?tarefa=ID`).
  Desde 08/11/2026 também o **Chat** e **Clientes**: o cartão de quem espera resposta abre a
  conversa no Chat (`/chat?jid=`), a mesma de sempre, com setor, assumir, cadastrar,
  vincular, anexo e áudio (a home deixou de ter envio próprio de WhatsApp); e o nome do
  cliente, no processo e na publicação, abre a ficha de Clientes (`/clientes?open=ID`,
  quando o processo tem `cliente_id`). O Chat não tem pop-up: dentro da home a conversa
  vira o pop-up, no centro, e fecha pelo "Voltar" dela, pelo clique fora ou pelo Esc. Com
  o WhatsApp desconectado a conversa não abre. Conversa fora das 500 mais recentes da
  lista do Chat é procurada pelo número e aberta assim mesmo (só no modo embutido).
  **Pop-up que não abre nunca leva para a tela** ("tem que abrir o card, não ir pra
  tela"): a home fica onde está e avisa. Enquanto aparece "Abrindo…", o clique fora
  e o Esc cancelam (o quadro só pega clique depois de pronto). A
  tela avisa a home por `postMessage` (só da mesma origem) quando o pop-up abre, quando
  fecha (a home tira o iframe e se atualiza) e quando não tem pop-up (a home vai para a
  tela). Para uma tela nova entrar, basta uma linha em `TELAS` no `embed.js`.
- **O desenho da home no sistema todo** (08/11/2026, "pega todo esse design da home e espalhe para
  todo o sistema, todas as abas, guias, botões, animações, gráficos; não deixe nenhuma aba de
  fora"): `assets/interface-nova-sistema.css` + `.js`, carregados pelo menu junto com a folha das
  telas (fora da home e também no modo embutido). A tela é achada por si (`.main-content`, ou o
  `main` ao lado do menu, ou o irmão do menu quando o `main` o embrulha, como em Webhooks) e
  marcada `.inn-tela`. Por cima do que a folha das telas já fazia (cor e superfície): o ícone do
  item do menu numa caixa afundada ao lado do título; botão principal que sobe e acende,
  secundário com borda azul; toda aba, guia, pílula ou filtro reconhecido pelo nome (tab, mtab,
  pill, chip, seg, filtro, view, periodo, opcao) com o ativo em qualquer nome (active, ativa,
  ativo, selected, -active, aria-selected) vira a pílula azul da home; cartões clicáveis sobem
  com borda azul; botão só de ícone e ícone de KPI na caixa afundada (caixa de marcar fica de
  fora); selos no formato da etiqueta; cabeçalho de tabela em rótulo azul; "Carregando…" vira a
  forma cinza que brilha; a onda do toque num quadro por cima do elemento (o elemento não
  muda); e os gráficos do Chart.js na paleta (azul e prata; vermelho, verde e âmbar só onde já
  eram estado), letra Inter, linhas apagadas, dica no painel prata e entrada em sequência.
- **Reconhecimento pela cor, não só pelo nome** (08/11/2026, "mudou nada": a Prospecção
  continuava com os cartões brancos e os selos coloridos de antes, porque cada tela dá um nome
  próprio às peças). A camada passou a ler a cor computada e marcar: selo pela cor que já tinha
  (`inn-selo-erro/aviso/ok/azul/neutro`: vermelho, âmbar e verde só quando já eram estado; azul e
  roxo viram o azul do Yuris; cinza fica na prata); fundo branco puro no tema claro vira painel,
  campo ou botão secundário (`inn-c-painel/campo/botao`); botão cheio de outra cor vira o
  principal azul (`inn-c-primario`, só com 30px ou mais: o miúdo dentro de cartão não ganha
  brilho), vermelho vira o secundário com texto vermelho (`inn-c-perigo`); borda colorida por
  estado vira a linha neutra (`inn-c-borda`); faixa lateral sai (`inn-c-sem-faixa`); filete
  decorativo em `::before`/`::after` de cartão some (`inn-c-sem-filete-*`); caixa tingida baixa
  vira o aviso da home (`inn-c-alerta-*`), alta vira o painel afundado (`inn-c-neutro`); toast de
  qualquer módulo vira o painel (`inn-toast`). O quadro da Prospecção (`.kanban-col`,
  `.card-mini`) e a faixa de origem (MATRIZ/FILIAL, Prospecção e Processos) têm regra própria.
- **Caixa dentro de caixa da mesma cor** (08/11/2026, o funil do Planejamento: "cheio de linha,
  cheio de contorno quadrado"). Duas causas. A primeira era do tema claro antigo
  (`yuris-theme.css`): o seletor `[class*="funnel-"]` pintava fundo e borda em título, cabeçalho e
  métricas de cada etapa, e o contorno do gargalo ia no embrulho `.funnel-stage` (quadrado) e no
  cartão `.funnel-shape` (arredondado), dois neons. Foi corrigido na origem, vale para todos no
  claro: só a coluna e o cartão são caixa, e o contorno é um só, no cartão. A segunda é geral e
  vive na camada: `afundarCaixas` lê a cor de fundo (cor ou primeira parada do degradê) de todo
  elemento com borda e, se a caixa de fora mais próxima tem a mesma cor, a de dentro afunda
  (`inn-c-afundado`: fundo da página, sombra interna, sem linha, como as caixas dos ícones da
  home); dentro de uma afundada, a terceira fica plana (`inn-c-plano`). Lista não entra (pai com
  nome de raia, coluna, grade ou lista; ou irmão com a mesma primeira classe): cartão numa raia é
  para ficar em relevo. Campo, botão, seleção e dropdown ficam de fora pelo nome. Os pais são
  avaliados antes dos filhos, de fora para dentro, porque o resumo nasce vazio e só enche depois
  (quem já afundou perdeu a borda, mas continua contando como a caixa de fora). O funil do
  Planejamento tem regra própria na prévia (raia afundada, etapa em relevo, gargalo com borda azul
  e anel suave). Degradê quase branco (branco a `#F4F7FB`, o `.card` do claro antigo) conta como
  branco e vira painel.
- **Título sem faixa** (08/11/2026, "fui claro, não é para ter listra nenhuma", sobre a faixa atrás
  de "Funil comercial"). Título ou cabeçalho de seção, coluna ou cartão (classe com `title`,
  `titulo`, `header`, `head`, `cabec`, `heading`; também `h1` a `h6`), com até 64px de altura e
  qualquer fundo, degradê ou borda, vira só o texto (`inn-c-titulo`: fundo e borda transparentes,
  sem sombra; a largura da borda fica, para nada mexer de lugar). Pegou a barra azulada das seções
  de Configurações e do Agente, o título "Prazos Críticos" e os cabeçalhos tingidos HOJE / 7 / 15 /
  30 DIAS do Painel jurídico, e a linha embaixo do cabeçalho das colunas de Clientes, Tarefas e
  Processos. O cabeçalho da página (`page-header`) é o painel do topo e fica de fora. O resumo do
  aumento e a nota do gargalo do Planejamento afundam (sem o tracejado azul). A faixa que ele ainda
  via no título do funil era cache: as páginas carregam `yuris-theme.css` com versão fixa, que
  passou de `?v=42` (e 27, 44) para `?v=45` em todas as 34; ao mexer nesse arquivo, suba o número.
  Rodapé (`footer`, `rodape`) entra na mesma regra.
- **Pop-up legível** (08/11/2026, "os campos podem ser brancos" e "o subtítulo não dá para
  enxergar"). Três regras. (1) Campo dentro de pop-up (modal, diálogo, gaveta, overlay; a busca
  sobe a partir do PAI, porque o título da tarefa é `.tk-drawer-title` e se achava a si mesmo) fica
  branco no claro, grafite um tom acima do painel no escuro, com borda visível e foco azul
  (`inn-c-campo-pop`); vale para texto, lista e área; caixa de marcar, cor e arquivo ficam como
  estão. As listas de seleção tinham perdido a seta porque `inn-c-campo` zerava o
  `background-image`: agora isso vale só para `input` e `textarea`. (2) Texto com contraste menor
  que 3 para 1 contra o fundo que se vê atrás dele (cálculo WCAG, fundo subindo pela árvore com
  cache na passada) é corrigido: estado vira o tom forte (`inn-c-texto-erro/aviso/ok`), azul vivo
  ou link vira o azul do Yuris (`inn-c-texto-azul`, o número do processo no Painel jurídico),
  azul desbotado e o resto viram o cinza do texto (`inn-c-texto-cinza`, o subtítulo do pop-up do
  lead, `#9BB5D2` em cima da prata). (3) Bloco escuro no tema claro (luminosidade abaixo de 22%,
  sobra do tema escuro, como o "Valor calculado" do imposto, azul-marinho com texto amarelo) vira
  a prata com texto escuro (`inn-c-escuro`); overlay, backdrop e o que cobre a tela inteira ficam
  de fora, senão o fundo escurecido atrás do pop-up viraria uma parede cinza.
- **Clicar fora fecha o pop-up** (08/11/2026, "em absolutamente todos os pop-ups"). Fora é o fundo
  escurecido: elemento fixo (ele ou um dos 3 pais) que cobre 90% ou mais da janela e tem dentro uma
  peça visível bem menor (o painel); no `<dialog>` nativo, o clique fora do retângulo dele. O
  clique aperta o PRÓPRIO botão de fechar do pop-up, na ordem: X (`×`, `aria-label`/`title`/classe
  com close, fechar, dismiss), "Fechar", "Cancelar" ou "Voltar". Assim cada módulo faz a limpeza
  de sempre (gaveta da tarefa, `?open=` do lead). Sem botão reconhecível, o clique segue para o
  módulo. O botão do mouse tem de descer E subir no fundo: arrastar uma seleção de dentro de um
  campo até fora não fecha. Atenção: fechar assim descarta o que foi digitado e não salvo, como o
  Cancelar. Conferido em: lead, Novo Cliente, Cadastrar Processo, Nova Tarefa, gaveta da tarefa,
  Novo imposto, Novo Usuário, Monitoramento de Publicações.
  Armadilhas que viraram regra: ler a cor no meio de uma transição dá a cor do caminho (a camada
  espera o `transitionend`); trocar a classe no meio da leitura recalcula o estilo (lê tudo,
  escreve depois); o que estava `display:none` ou ganhou classe depois é relido pelo observer;
  e a entrada `inn-item` não pode cair em quem já tem `transform` próprio (a coluna do quadro
  gira em `rotateX` e o texto ficava de cabeça para baixo). Os seletores usam `html:root` e a
  classe repetida para vencer os `html[data-theme="light"] … !important` das folhas antigas.
  As opções do Chart.js são proxies: troca-se propriedade por propriedade, nunca o objeto todo.
  A camada não mexe em layout (o teste trava). Conferido em 25 telas; Fila e Roleta dão 403 na
  conta local, Contratos, Projetos e Evolution não estão ligados nela.
- **Setas e detalhes no mesmo afundado do ícone** (08/11/2026, "todas essas setas e detalhes
  têm de ser aprofundados, como os ícones: o cinza com o ícone azul escuro"): as setas do
  calendário, a seta de cada cartão, o × do pop-up, a seta do De/Até e os ícones dos contadores
  de Prazos usam a mesma caixa do `.ini-ic` (fundo afundado, sombra interna, traço em
  `--ini-icone`) e o mesmo hover do ícone do cabeçalho (sobe e acende em azul, traço branco).
- Cabeçalho dos cartões: sem link de texto; o ícone é o atalho para a tela do bloco,
  centrado com o título, e no hover sobe 2px e acende como o botão aprovado.
- Cartões nunca deixam vão: as colunas se esticam até a mesma altura e o último
  cartão de cada coluna cresce ("nunca deixe esses vãos entre um card e o outro").
- A faixa de boas-vindas: a saudação segue o horário de Brasília (`Painel::saudacao`, 5h a 12h
  Bom dia, 12h a 18h Boa tarde, depois Boa noite) e se atualiza sozinha a cada minuto com a
  página aberta; o nome vem no degradê do logo, do azul do Yuris para a prata (08/11/2026).
- **O movimento** (08/11/2026, "agora eu quero animação em tudo, animações elaboradas,
  condizentes com o que estou abrindo"): `assets/inicio-movimento.css` + `inicio-movimento.js`,
  carregados depois de tudo e só fora de `prefers-reduced-motion`. Não mudam regra nem cor:
  observam o que os outros scripts fazem e põem o movimento por cima. A faixa, os quatro
  números e os cartões sobem um a um na entrada; os números contam de zero até o valor
  (cartões de cima, prazos, o pop-up da pessoa); o dia do calendário e os atalhos enchem de
  azul a partir do ponto do clique (`--cx/--cy`, gravados no pointerdown); botões, cartões e
  ícones têm a onda do toque; a lista do dia sai e entra item por item, o título troca e o
  mês desliza para o lado da seta; o pop-up nasce do cartão clicado (o `inicio-detalhe.js`
  o põe em cima do cartão e solta) e volta para ele ao fechar, com o conteúdo entrando campo
  por campo; link para outra tela leva a página embora antes de navegar; o cartão resolvido
  se recolhe. Segunda volta (mesmo dia): o círculo do preenchimento vai até o canto mais
  longe (`--raio`, medido no clique) e o fundo azul inteiro assenta por cima quando ele
  termina, então a caixa fica cheia por completo, de onde quer que o clique tenha partido.
- **A tela fantasma** ("abre a tela com os campos cinza, mostrando o que está carregando, e
  depois ela ganha vida, cor, as palavras"): o "Abrindo…" saiu. Enquanto o módulo carrega,
  a home mostra a forma do pop-up que vem, em ossos cinza que brilham (ficha com cabeçalho,
  campos em duas colunas, área de texto e botões; ou conversa com balões e a barra de
  escrever). Quando o módulo avisa que o pop-up abriu, manda junto a medida dele (`caixa`
  no `embed-pronto`, medida 260 ms depois, quando a entrada dele acabou); a forma vai até
  essa medida (parando na borda da janela se o pop-up for mais alto que ela) e o pop-up de
  verdade aparece no lugar dela. Dentro do pop-up da home, processo e tarefa também
  carregam em ossos (`ossos()`) e o conteúdo de verdade sai do cinza para a cor (`idt-vida`).
- **Fechar só fecha** ("tá forçando muito o recarregamento" e, depois, "se eu sair da aba, é
  só para sair; se eu recarregar a página, tem que recarregar tudo"): fechar o pop-up do
  módulo tira o pop-up e a home fica exatamente como estava. Não há `location.reload` nem
  atualização em silêncio (houve uma, que trocava os cartões ao fechar; foi reprovada no
  mesmo dia porque Prazos e Aguardando retorno pareciam recarregar). Quem atualiza os
  dados é o recarregar da página. Ações feitas no pop-up da própria home (concluir,
  tratar) continuam marcando o cartão como resolvido na hora, sem buscar nada. Movimento das telas de dentro e do menu: `assets/interface-nova-movimento.css`
  + `.js` (saída pelo menu, pop-ups que surgem, toque nos botões), que o menu carrega com a
  folha das telas e que não rodam no modo embutido. **A entrada das telas por partes** (08/11,
  "quero esse simples bonito nas páginas também, os ícones crescendo"): os blocos de
  `.main-content` sobem um a um (CSS puro, por `nth-child`, para não piscar antes do script),
  o título desliza, todo svg pequeno cresce com mola, todo número inteiro sozinho conta de
  zero, e os itens (cartões e colunas do Kanban, cartões de processo e cliente, KPIs, linhas de
  tabela) entram um a um dentro do bloco deles. O que a tela desenha depois por JavaScript
  entra do mesmo jeito nos primeiros 5 s e até 1,5 s depois de um clique; fora disso (o Chat
  repondo a lista a cada 4 s) nada se mexe. Item novo entra na lista `SEL_ITEM` do script.
- `assets/inicio.css` é a home: dois temas pelas variáveis `--ini-*`, a faixa de
  boas-vindas com uma biblioteca jurídica ao fundo (`assets/img/inicio-biblioteca.jpg`,
  foto de Planet Volumes no Unsplash, licença Unsplash: uso comercial livre, sem
  atribuição obrigatória; as peças de madeira em CSS da primeira versão foram
  reprovadas e saíram), cards com relevo, campos com sombra interna. O claro é papel
  e pedra, não branco.

## Regras que valem aqui

- **Toda consulta filtra por conta.** `Painel` recebe os `$tenantIds` do dashboard e
  o escopo da Central (`user_id`, `is_admin`, `contas_tarefas`, `contas_processos`),
  e nunca consulta sem eles. O teste confere que nada de outra conta aparece.
- **Nada de reticências escondendo dado.** Texto quebra linha; a folha não usa
  `text-overflow`. O teste trava.
- **Azul escuro, nunca roxo.** As variáveis estão no topo das duas folhas; é ali
  que se mexe.
- **Quando aprovar**, ligar para todos é apagar a condição da chave em
  `dashboard.php` e `sidebar.php`, não reescrever nada. Antes disso, nada aqui
  aparece para quem não pediu.

Teste: `scripts/tests/interface_nova_test.php`.
