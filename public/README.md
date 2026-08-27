# public/ — a única pasta que o Apache serve

O `DocumentRoot` aponta para cá, em desenvolvimento e em produção. Tudo o que
existe aqui **é acessível por URL**; tudo o que está fora daqui não é.

Consequência prática, e é o motivo desta pasta não ter sido reorganizada junto
com o `app/`:

> **O caminho do arquivo é a URL.** `public/processos.php` responde em
> `/processos.php`. Mover o arquivo muda o endereço, e quebra link salvo, link
> em e-mail de convite já enviado, e webhook cadastrado apontando para o
> endereço antigo.

Por isso `public/` mantém a disposição que sempre teve. Reorganizar aqui exige
antes uma camada de rota que preserve os endereços atuais, e isso é mudança de
arquitetura, não de pasta. Fica como decisão separada.

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
> nginx que trata URL limpa, e ela lista os slugs. Página nova exige atualizar
> o vhost, senão responde 404 só em produção, funcionando em local. Detalhe em
> [`../docs/seo/`](../docs/seo/).

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
