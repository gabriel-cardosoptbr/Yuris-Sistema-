# public/ — a única pasta que o Apache serve

O `DocumentRoot` aponta para cá, em desenvolvimento e em produção. Tudo o que
existe aqui **é acessível por URL**; tudo o que está fora daqui não é.

Era esse o motivo de esta pasta nunca ter sido reorganizada junto com o `app/`:

> **O caminho do arquivo era a URL.** `public/processos.php` respondia em
> `/processos.php`. Mover o arquivo mudava o endereço, e quebrava link salvo,
> link em e-mail de convite já enviado, e webhook cadastrado apontando para o
> endereço antigo.

## A camada de rota (desde 08/09/2026)

Desde o D3 existe uma camada de rota, e ela desfaz esse acoplamento: o endereço
público deixou de ser consequência do lugar do arquivo.

| Peça | Papel |
|---|---|
| `.htaccess` | o gatilho. Manda para o front controller **só o que não existe no disco** (`RewriteCond !-f` e `!-d`) |
| `index.php` | o front controller. É o `DirectoryIndex` da raiz e o destino do fallback |
| [`../config/rotas.php`](../config/rotas.php) | a tabela de rotas declarada |
| [`../app/Core/Router.php`](../app/Core/Router.php) | resolve URL → arquivo |

**Arquivo que existe continua sendo servido direto pelo Apache, sem passar pelo
PHP.** É por isso que ligar a camada não mexeu em nenhuma página: o router só vê
URL que antes daria 404.

Ordem de resolução: tabela declarada, depois sondagem em `public/`
(`/x` → `x.php`, `/x/` → `x/index.php`), depois 404 brandado.

Três coisas mudaram de fato ao ligar isso:

1. **A URL limpa passou a valer também em desenvolvimento.** Antes `/dashboard`
   só funcionava em produção, por rewrite no nginx do host, fora do repositório;
   local dava 404. Agora os dois ambientes se comportam igual.
2. **O 404 brandado voltou a existir em produção.** O `ErrorDocument` que
   ativava o `404.php` morava num `zz-yuris.conf` que não existe mais no
   contêiner, então endereço errado devolvia a página padrão do Apache,
   expondo a versão do servidor. Agora quem responde é o `404.php`.
3. **Página nova em pasta deixou de depender do vhost.** A lista de slugs do
   nginx continua lá, mas não é mais a única coisa entre a página e um 404 que
   só aparece em produção.

Reorganizar de fato os arquivos daqui virou possível. A primeira página a sair
foi a dupla do titular de dados, que estava em `public/lgpd/` e foi para
[`../app/Lgpd/Paginas/`](../app/Lgpd/Paginas/): a pasta sombreava
`public/lgpd.php` e derrubava o link do rodapé em 403.

Duas coisas que essa primeira mudança ensinou, e valem para as próximas:

- **Renomear a pasta dentro de `public/` não resolve.** Arquivo que existe aqui
  é servido direto pelo Apache, sem passar pelo router, então o caminho físico
  vira um **segundo endereço público** para a mesma página. Só sair de `public/`
  dá um endereço só.
- **A view continua aqui.** A página movida ainda faz `require` de
  `public/includes/legal_page.php` e de `public/includes/sidebar.php`. Mover
  página para fora resolve o endereço, não a view; o acoplamento
  `app/` → `public/includes/` é o que sobra para um próximo passo.

`public/configuracoes/` foi pelo mesmo motivo e para o mesmo lugar: sombreava
`public/configuracoes.php`. Não aparecia porque em produção o nginx reescreve
`/configuracoes` antes de chegar ao Apache, mas bastava essa regra do vhost
mudar para a tela de Configurações cair em 403 igual à do LGPD.

**Nenhuma pasta daqui pode ter o nome de uma página.** `rotas_test.php` barra
sombra nova, sem lista de exceção.

## O que tem aqui

### Páginas do sistema (exigem login)
`dashboard.php` · `planejamento.php` · `prospeccao.php` · `clientes.php` ·
`tarefas.php` · `processos.php` · `intimacoes.php` · `juridico.php` ·
`chat.php` · `chat_interno.php` · `financas.php` · `usuarios.php` ·
`escritorios.php` · `agente.php` · `webhooks.php` · `configuracoes.php` ·
`relatorios.php` · `desempenho.php`

`desempenho.php` (Gestão › Desempenho) é o OTIF das tarefas por colaborador:
ranking, evolução mensal e a lista do que tirou o OTIF de cada um. Dono/admin
vê a equipe; os demais, só o próprio. Regras em
[`../app/Tarefas/README.md`](../app/Tarefas/README.md).

Cada uma corresponde a um domínio em [`../app/`](../app/); a tabela de
equivalência está em [`../app/README.md`](../app/README.md).

`chat.php` trava a página em 100% da altura da janela, sem rolagem, e divide
o espaço entre cabeçalho, KPIs e o painel (lista + conversa). Em tela baixa
isso zerava a lista: em 30/09/2026, num notebook com zoom de 125% (cerca de
1093x500 úteis), o contador dizia "7 conversas" e a lista tinha 0px de altura.
Um `@media (max-height: 820px)` destrava a rolagem da página e dá ao painel
altura garantida. A altura é **o que sobra da tela abaixo do cabeçalho**, com
piso de 420px: um script no fim da página mede onde o painel começa e publica
em `--chat-topo`. A primeira versão dava ao painel a tela inteira, e em
1366x768 o campo de digitar ficava fora da tela (era preciso rolar a página
para responder). Ao mexer nesse layout, teste em 1093x500, 1280x720 e
1366x768, não só em monitor grande, e confira que o campo de digitar aparece.

`chat.php` resolve a edição do produto (`$isFleetiflow`) para três coisas: o
nome na aba, a variável `window.CHAT_MARCA` (o `chat.js` reescreve o título
com o contador de não lidas) e `window.CHAT_SEM_JURIDICO`, que tira "Processos
Jurídicos" do modal Vincular e evita a chamada a `/api/processes.php` (403
nessa conta). Aviso de mídia indisponível, nome de documento e legenda têm
regra própria para o tema claro: o estilo escuro embutido virava bloco cinza
com texto ilegível sobre a bolha branca.

`prospeccao.php` troca título, cabeçalho e o nome do modal ("Novo Lead
Jurídico") quando a conta não tem módulo jurídico (`$moduloJuridico`). Nessa
edição (`$edicaoCrm`) o **card do quadro é o do Fleetiflow** (`renderCardCrm`,
classes `.lc-*`): termômetro e tipo do lead no alto, inicial da empresa, cidade,
potencial mensal, último contato (última mensagem do WhatsApp ligado, ou a
última mexida) ou próximo contato (prazo à frente), aviso de atraso, consultor
e as ações. Mesmas classes de gancho do card jurídico (`.card-mini`,
`.js-whatsapp`, `.chat-link-btn`, `.card-drag-handle`), então clique, arrastar e
filtros não mudaram. O formulário ganha "Tipo do lead" (texto com sugestões,
`datalist`) e "Termômetro" (automático ou escolhido), migration 134. O selo de temperatura do card é clicável (menu: automático, quente, morno, frio, congelado) e dono/admin tem o botão **Termômetro**, que define a regra da conta (`/api/crm_termometro.php`). Dono/admin vê na barra o seletor **Especialista do WhatsApp** (`/api/crm_especialista.php`): quem vira consultor do card que entra em atendimento pelo celular ou WhatsApp Web. A edição
jurídica renderiza o card de sempre.

`dashboard.php` calcula tenant, filtro de origem e DRE e, se a conta tem
produto `fleetiflow`, entrega em `includes/dashboard_fleetiflow.php` e sai. Essa
página é o **cockpit comercial** (30/09/2026): cabeçalho com período (7/30/90
dias, mês, mês passado, ano, personalizado; persiste em sessão pelo mesmo
`api/dashboard_settings.php`), filtro por responsável e menu de ações; seis
KPIs pequenos com comparação ao período anterior equivalente (leads, oportunidades,
vendas, receita em destaque, conversão, ticket); performance com abas
Receita/Vendas/Conversão; pipeline em rosca com lista e chips do que está fora
do funil; prospecção (leads que entraram por dia, empilhados pelo que viraram)
e funil da coorte (por quais etapas esses leads já passaram, via `card_history`); meta do mês em barra de progresso com ritmo e projeção; tabela de
atividades recentes (busca, filtro por etapa, "Ver todas", linha abre o card por
`prospeccao.php?open=`); mapa de calor da atividade no pipeline (dia da semana × bloco de 3h, de `card_history`); evolução por dia/semana/mês; e o financeiro (DRE)
abaixo dos negócios, como separador antes dos últimos gráficos (a página nunca termina em card). Sem dados o lugar do gráfico mostra um estado vazio, nunca um
eixo zerado. Os dados vêm de uma chamada a `api/dashboard_comercial.php`
(`assets/fleetiflow-dashboard.js`); o visual está em
`assets/fleetiflow-dashboard.css`, tudo com prefixo `.ffc-` e componentes
reutilizáveis (`ffc_kpi()` no PHP e `window.FleetiflowDash` no JS) para os
próximos painéis da Fleetiflow. A conta Yuris nunca passa por esse `require`.

### Entrada e sessão
`login.php` · `login-fleetiflow.php` · `logout.php` · `404.php`

`login-fleetiflow.php` é a tela de login da edição CRM comercial. Mostra a
marca da conta dona do domínio da requisição (nome, cor, logo, subtítulo, ver
`App\Master\Marca`), ou a do Fleetiflow quando o domínio não é de nenhuma marca. Visual próprio,
mas posta para o **mesmo** `AuthController::attemptLogin()` do login padrão,
sem autenticação paralela — ver `../app/Usuarios/README.md`.

**Domínio próprio, mesmo servidor.** `index.php` (raiz `/`) e `login.php`
chamam `App\Core\ProductHost::isFleetiflow()` antes de qualquer outra coisa:
se o `Host` da requisição estiver em `FLEETIFLOW_DOMAINS` (`.env`), servem
`login-fleetiflow.php` por `require` (sem redirect HTTP, a URL na barra do
navegador não muda). Pré-requisito no Apache: o domínio precisa de um
`ServerAlias` (ou um segundo `<VirtualHost>`) apontando pro **mesmo**
`DocumentRoot` — sem isso a requisição nem chega no PHP. Ver o exemplo já
existente em produção/dev para `yuris.local` no vhost do projeto.

### Painel Master (super admin da Inovaize, separado do login normal)
`master.php` · `master_login.php` · `master_logout.php` · `master_mfa_setup.php`

O Master tem **login próprio e 2FA próprio**. Não é um papel do login comum.

### Páginas públicas, sem login
`index.php` (a home) · `planos.php` · `termos.php` · `privacidade.php` ·
`cookies.php` · `lgpd.php` · `dpo.php`

`index-v1-legacy.php` é a home antiga. Desde 15/06/2026 a `/` serve a v2 por um
stub em `index.php`; a v1 ficou guardada. Ver
[`../docs/ARCHITECTURE.md`](../docs/ARCHITECTURE.md).

### Subpastas
| Pasta | O que é |
|---|---|
| `api/` | ~130 endpoints REST. Tem [README próprio](api/README.md) |
| `includes/` | pedaços de página reaproveitados: `sidebar.php`, `seo_head.php`, `legal_page.php`, rodapés, e `dashboard_fleetiflow.php` (a página inteira do cockpit comercial, incluída por `dashboard.php` só para a conta Fleetiflow). `sidebar.php` resolve `AccountContext::getProduto()` uma vez (`$_isFleetiflow`) e troca logo, rodapé, grupo "Jurídico" do menu e as duas primeiras abas da barra mobile conforme o produto da conta. Para a conta Fleetiflow troca também "Yuris" por "Fleetiflow" no título da aba (23 páginas escrevem o nome no `<title>`; a troca é feita num script só, aqui). Também força o tema **claro** do Yuris (existente, não é novo) na primeira visita da conta Fleetiflow e, num `<style>` só dessa conta, redesenha a barra como a `AppShell` real do Fleetiflow (coluna branca de 260px encostada na borda, item plano, ativo `#D6E4FF` com texto `#3D3D3D`, canvas `#F6F7F9`, fonte Manrope). Regra do bloco: todo seletor leva o prefixo `html[data-theme="light"]` e desce até `.label` / `svg *` onde o `yuris-theme.css` desce, senão o tema claro do Yuris vence por especificidade. O envoltório da barra varia por página (`main.px-6`, `main.rel-wrap`, `main.o-wrap`, `main` com padding inline, `.layout` sem `.page-layout` em `clientes.php`), então o bloco usa `:has(> .sidebar)`: quem contém a barra perde o padding e o irmão dela ganha `24px 24px 24px 0`. Página nova com outro envoltório entra sozinha nessa regra; não crie regra por classe de página. Sem tocar `yuris-theme.css`, então nenhuma conta Yuris é afetada |
| `assets/` | CSS, JS e imagens. Um arquivo JS por tela (`processos.js`, `tarefas.js`), mais `design-system.css` e `yuris-theme.css`. `dashboard.js` lê um tema de gráficos opcional em `window.YURIS_CHART_THEME` (cores das séries, raio e espessura das barras, tensão da linha, funil em rosca, fonte); sem ele, os valores são os de sempre do Yuris. `fleetiflow-dashboard.js` + `fleetiflow-dashboard.css` são o cockpit comercial da conta Fleetiflow, independentes do `dashboard.js` |
| `v2/` | a landing institucional nova (`index.php` + `partials/` + `data/`), servida na `/` |
| `sistema_vendas/Imagens/` | os logos, servidos em `/sistema_vendas/Imagens/`: 3 do Yuris (`Logo.png`, `Logo Loguin.png`, `YURIS.png`) + 4 do Fleetiflow (`fleetiflow-horizontal.png`, `fleetiflow-icone.png`, `fleetiflow-completa.png`, `fleetiflow-texto.png`, cópia dos arquivos oficiais da marca). **Não mova:** `sidebar.php`, `login.php`, `login-fleetiflow.php` e as páginas legais apontam para essa URL. O nome é herança de quando o app era servido em `/sistema_vendas/` |
| `uploads/` | arquivos enviados pelos clientes |

### Páginas de SEO
`automacao-juridica/` · `blog/` · `controle-de-processos/` · `crm-juridico/` ·
`demonstracao/` · `financeiro-juridico/` · `gestao-escritorio-advocacia/` ·
`lgpd-escritorios-advocacia/` · `prospeccao-juridica/` · `sistema-juridico/` ·
`sobre/`

Cada uma é uma pasta com um só `index.php`, e o formato é proposital: dá a URL
limpa `/crm-juridico/` em vez de `/crm-juridico.php`. `ai/` guarda as versões em
markdown para consumo por LLM.

> **Atenção ao criar página-pasta nova:** em produção existe uma camada de
> nginx que trata URL limpa, e ela **lista os slugs à mão**. Desde a camada de
> rota a página nova já responde mesmo sem o vhost saber dela, porque o fallback
> cai no front controller. Ainda assim, atualize a lista: sem ela `/slug` ganha
> um 301 a mais e `/slug/` deixa de ser servido pelo `DirectoryIndex`. Detalhe
> em [`../docs/seo/`](../docs/seo/).

## Regras

**Nada de credencial nem chave aqui.** Tudo nesta pasta é público por
definição. Segredo vive em `.env`, na raiz, fora do DocumentRoot.

**Toda página de sistema começa checando sessão e conta.** Antes de qualquer
query, `AccountContext`. Página nova que esqueça isso vira vazamento entre
escritórios.

**Endpoint responde por `ApiResponse`**, nunca com `echo json_encode` solto: o
formato precisa ser o mesmo em toda a API.

**Ao renomear ou mover algo daqui, você mudou uma URL.** Trate como mudança
externa: verifique link em e-mail, webhook cadastrado e o vhost de produção.
Mover um arquivo hoje é possível sem mudar o endereço, desde que a rota antiga
seja declarada em [`../config/rotas.php`](../config/rotas.php); o que não se faz
é renomear a chave da rota.

**`scripts/tests/rotas_test.php` é a defesa desta pasta.** Ele confere que o
`.htaccess` ainda preserva arquivo existente, que toda página resolve pelo
endereço limpo, que `includes/` e `uploads/` não saem por rota, e que o
`require` acontece em escopo global.
