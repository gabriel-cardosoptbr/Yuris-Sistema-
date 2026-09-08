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

Reorganizar de fato os arquivos daqui virou possível, mas continua sendo uma
decisão à parte: as páginas ainda dependem de `includes/`, então mover páginas
para fora de `public/` implica mover as views junto.

## O que tem aqui

### Páginas do sistema (exigem login)
`dashboard.php` · `planejamento.php` · `prospeccao.php` · `clientes.php` ·
`tarefas.php` · `processos.php` · `intimacoes.php` · `juridico.php` ·
`chat.php` · `chat_interno.php` · `financas.php` · `usuarios.php` ·
`escritorios.php` · `agente.php` · `webhooks.php` · `configuracoes.php`

Cada uma corresponde a um domínio em [`../app/`](../app/); a tabela de
equivalência está em [`../app/README.md`](../app/README.md).

### Entrada e sessão
`login.php` · `logout.php` · `404.php`

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
| `includes/` | pedaços de página reaproveitados: `sidebar.php`, `seo_head.php`, `legal_page.php`, rodapés |
| `assets/` | CSS, JS e imagens. Um arquivo JS por tela (`processos.js`, `tarefas.js`), mais `design-system.css` e `yuris-theme.css` |
| `v2/` | a landing institucional nova (`index.php` + `partials/` + `data/`), servida na `/` |
| `sistema_vendas/Imagens/` | os três logos, servidos em `/sistema_vendas/Imagens/`. **Não mova:** `sidebar.php`, `login.php` e as páginas legais apontam para essa URL. O nome é herança de quando o app era servido em `/sistema_vendas/` |
| `uploads/` | arquivos enviados pelos clientes |
| `lgpd/`, `configuracoes/` | telas secundárias desses módulos |

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
