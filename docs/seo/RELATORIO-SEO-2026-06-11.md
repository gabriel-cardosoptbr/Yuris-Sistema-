# Relatório — Implementação SEO/GEO/AEO do site Yuris

**Data:** 2026-06-11 · **Escopo:** site público (`public/`) · **Status:** ✅ **DEPLOYADO em produção em 2026-06-11** (commits `2b12712`, `ff46636`, `d9a8759`)
**Backup:** `.backup_seo_20260611/` (todos os arquivos alterados, estado anterior)

> **Deploy realizado (2026-06-11):** `git pull --ff-only` no servidor (zero downtime) + bloco novo
> no nginx do host (`/etc/nginx/sites-available/yuris-com-br`, backup `.bak-20260611`) liberando as
> páginas-pasta — o vhost tem camada de URLs limpas (REGRA 1/2) que reescrevia `/slug/` → 403.
> Smoke em prod: todas as 11 páginas 200, raiz 200 (antes era 302!), `/uploads/` e `/api/` 403,
> 404 ok, app 302→login, Evolution/n8n intocados. Canonicals na forma limpa (`/planos`, `/privacidade`…).
> PENDENTE do Dockerfile: o conf `zz-yuris.conf` (ErrorDocument 404 brandada, ServerTokens, cache)
> só entra no próximo rebuild do container — ver §4.

---

## 1. Arquivos CRIADOS

### Infra de indexação
| Arquivo | O quê |
|---|---|
| `public/robots.txt` | Política de crawl: público liberado, app/api/uploads bloqueados; grupos para bots de IA (GPTBot, ClaudeBot, PerplexityBot etc.); aponta sitemap |
| `public/sitemap.xml` | 17 URLs públicas canônicas (yuris.com.br), lastmod 2026-06-11 |
| `public/llms.txt` | Sumário curado do Yuris para IAs (GEO) |
| `public/ai/sistema-juridico.md` · `crm-juridico.md` · `automacao-juridica.md` · `lgpd-escritorios-advocacia.md` | Resumos Markdown citáveis por IA |
| `public/404.php` | 404 brandada (status 404 + noindex + navegação de volta) |

### Template e helpers (novas páginas)
| Arquivo | O quê |
|---|---|
| `public/includes/seo_head.php` | `<head>` padronizado: title/description/canonical/OG/Twitter/JSON-LD via array `$SEO` |
| `public/includes/seo_page.php` | Layout completo de página de marketing (`$SEO_PAGE`): header, breadcrumb, hero, artigo, FAQ `<details>`, relacionados, CTA, footer. **BreadcrumbList + FAQPage automáticos** |
| `public/includes/lp_footer.php` | Rodapé compartilhado (5 colunas, com Soluções) |
| `public/includes/lp_helpers.php` | `wa()` + ícone WhatsApp compartilhados (com guard `function_exists`) |
| `public/assets/seo-pages.css` | Estilos das páginas internas: hero compacto, breadcrumb, artigo, FAQ, tabela comparativa |
| `public/assets/img/` | `logo-144.webp` (7,8 KB vs 750 KB!), `logo-144.png`, `logo-512.png`, `og-image.jpg` (52 KB, 1200×630), `og-image.png` |

### Páginas estratégicas (pastas = slugs, funcionam sem rewrite)
| URL | Title | Conteúdo |
|---|---|---|
| `/sistema-juridico/` | Sistema Jurídico para Advogados e Escritórios | exemplar, ~950 palavras |
| `/crm-juridico/` | CRM Jurídico e Funil Comercial para Advogados | 880 palavras |
| `/gestao-escritorio-advocacia/` | Gestão de Escritório de Advocacia: Sistema Completo | 1009 palavras |
| `/automacao-juridica/` | Automação Jurídica para Escritórios de Advocacia | ~900 palavras |
| `/controle-de-processos/` | Controle de Processos Jurídicos para Escritórios | ~870 palavras |
| `/prospeccao-juridica/` | Prospecção Jurídica e Gestão de Leads na Advocacia | 852 palavras (enquadramento OAB) |
| `/financeiro-juridico/` | Financeiro para Escritório de Advocacia | 821 palavras |
| `/lgpd-escritorios-advocacia/` | LGPD para Escritórios de Advocacia na Prática | 944 palavras |
| `/sobre/` | Sobre o Yuris — Sistema Jurídico Inteligente | entidade + schema AboutPage |
| `/demonstracao/` | Agende uma Demonstração do Sistema Jurídico | conversão, 567 palavras |
| `/blog/` | Blog do Yuris (estrutura pronta, **noindex até o 1º post**) | categorias + instruções no código |

Todas com: lede AEO citável, bloco `sp-definicao` ("O que é..."), tabela comparativa, FAQ 4-6 (40-80 palavras), 3-6 links internos, 3 relacionados, BreadcrumbList+FAQPage.

### Documentação
- `docs/seo/pautas-blog.md` — estratégia + 30 pautas em tabela (9 categorias)
- `docs/seo/paginas-programaticas.md` — como escalar novas páginas + fatos proibidos + fila de slugs
- `docs/seo/RELATORIO-SEO-2026-06-11.md` — este arquivo

## 2. Arquivos ALTERADOS

| Arquivo | Mudanças |
|---|---|
| `public/index.php` (landing) | Canonical absoluto `https://yuris.com.br/`; OG completo (url/site_name/locale/image absoluta 1200×630) + Twitter Cards + theme-color; **JSON-LD @graph (Organization + WebSite + SoftwareApplication com AggregateOffer R$220–670)**; **seção FAQ nova (6 perguntas, `<details>`) + schema FAQPage**; logo header/footer → `logo-144.webp` com width/height (e lazy no footer); hero sem `.lp-reveal` (LCP pinta imediato); script `html.js` (resiliência no-JS); "Planos" na nav + drawer; footer reestruturado (5 colunas: + Soluções com as 8 páginas, + Planos/Blog/Sobre/Demonstração/DPO/Gerenciar cookies); H4→span na vitrine (heading dentro de button) e H4→H3 nas integrações; fonts sem Poppins 500; `cookie-consent.js` agora carrega na landing |
| `public/planos.php` | **5 CTAs consertados: `wa.me/55` (sem número!) → `wa.me/5511991170602`** com "Olá Bruno"; head completo (description, canonical, OG/Twitter, favicon-192, theme-color); JSON-LD BreadcrumbList + Product com 4 Offers; logo otimizado; claim "agente IA que responde por você" → "agente de IA configurável (com a chave de API do seu provedor)" |
| `public/includes/legal_page.php` | meta description (antes a descricao não virava meta!), canonical absoluto dinâmico, robots, favicon-192 — vale para privacidade/termos/cookies/lgpd/dpo |
| `public/login.php` | `noindex,follow`; title "Entrar — Yuris"; dica de e-mail neutra (removido "admin@admin.com") |
| `public/master_login.php` | `noindex,nofollow` (portal super-admin fora dos buscadores) |
| `public/dpo.php` | Placeholder sem DPO configurado não vaza mais instruções de `.env` — aponta o titular pro formulário |
| `public/assets/landing.css` | `.lp-reveal` atrás de `html.js` (sem JS, tudo visível); `will-change` removido; gradientes do body → `body::before` fixo (scroll mais leve em mobile); footer-grid 5 colunas; seletores estendidos pra `.lp-vit-title` e `.lp-integ h3` |
| `Dockerfile` | **`zz-yuris.conf`: AllowOverride All + Options -Indexes em public/ (o .htaccess de uploads era IGNORADO em prod!), `Require all denied` em uploads/ direto no Apache, ErrorDocument 404 /404.php, ServerTokens Prod, ServerSignature Off**; `zz-yuris-cache.conf`: Cache-Control (css/js 1 dia, imagens/fonts 30 dias) |
| `.htaccess` (raiz) | ErrorDocument 404 para o ambiente XAMPP dev + aviso de escopo dev-only |

## 3. Validação executada (local)

- ✅ `php -l`: **0 erros** em todos os arquivos criados/alterados
- ✅ Servidor local `php -S -t public` (espelha docroot de prod): todas as rotas novas **200**
- ✅ robots.txt/sitemap.xml/llms.txt/ai/*.md servidos com content-type correto
- ✅ 404.php responde **status 404** + HTML brandado + noindex
- ✅ JSON-LD: **25 blocos, 0 inválidos** (parse real em todas as páginas)
- ✅ Links internos: **19 únicos, 0 quebrados**
- ✅ Páginas privadas seguem protegidas: dashboard/processos/clientes/webhooks → **302 login**; master → 302 master_login
- ✅ Visual verificado (screenshots): landing desktop, FAQ, rodapé 5 colunas, /sistema-juridico/, /crm-juridico/, mobile 375px
- ✅ Revisão adversarial de claims nas 9 páginas novas (2 correções aplicadas: title duplicado, linguagem de captação/OAB)
- ✅ 1 H1 por página; sem H4 dentro de botões; hierarquia de headings corrigida

## 4. O que NÃO foi feito (pendências, por ordem de prioridade)

1. **Deploy** — tudo é local. Subir exige: commit/push (com sua aprovação explícita) + `docker compose build && up -d` no EC2 (o conf novo do Apache só vale após rebuild). Ver §6.
2. **301 yuris.inovaize.com → yuris.com.br** — o canonical já consolida pro Google, mas o 301 definitivo é config de nginx no host (fora do repo). Pendente também: atualizar callback do webhook Evolution se usar o domínio antigo.
3. **`aria-hidden` nos 19 SVGs de mockup da landing** — leitores de tela leem dados fictícios ("Construtora Aurora S/A"). Baixo risco, mexer com calma.
4. **ARIA completo nas tabs da vitrine** (role=tab/tabpanel) — cosmético de a11y.
5. **Self-host das Google Fonts** — elimina o último render-blocking de terceiro.
6. **Blog**: estrutura pronta e noindex; publicar o 1º post (pautas em `docs/seo/pautas-blog.md`) e aí indexar + incluir no sitemap.
7. **Solo e Equipe custam o mesmo R$220** (1-2 vs até 5 usuários) — conferir se é intencional.
8. **DPO_NAME/DPO_EMAIL** no `.env` de produção (a página /dpo.php está com fallback neutro, mas o ideal é ter o contato).
9. Imagens grandes originais mantidas (não deletei nada): `Logo.png` 750 KB, `YURIS.png` 1,9 MB (órfã), `Logo Loguin.png` 462 KB — podem ser otimizadas depois (login.php ainda usa a Loguin).

## 5. Riscos e reversão

- Nada foi commitado; nada foi deletado; produção intocada.
- Reverter qualquer arquivo: copiar de `.backup_seo_20260611/`.
- O conf novo do Dockerfile (`AllowOverride All`) reativa o `.htaccess` de uploads em prod — isso é o comportamento DESEJADO (defesa em profundidade junto com o `Require all denied` do conf).
- `Cache-Control` em css/js é 1 dia (conservador) porque nem todo asset do app interno tem `?v=`.

## 6. Checklist de deploy (quando você decidir subir)

```bash
# no EC2, após git pull (escopo 100% Yuris):
docker compose build && docker compose up -d
# smoke:
curl -s -o /dev/null -w '%{http_code}' https://yuris.com.br/robots.txt        # 200
curl -s -o /dev/null -w '%{http_code}' https://yuris.com.br/sitemap.xml       # 200
curl -s -o /dev/null -w '%{http_code}' https://yuris.com.br/sistema-juridico/ # 200
curl -s -o /dev/null -w '%{http_code}' https://yuris.com.br/uploads/          # 403  <- CRÍTICO (LGPD)
curl -s -o /dev/null -w '%{http_code}' https://yuris.com.br/api/              # 403/404 (sem listagem)
curl -s -o /dev/null -w '%{http_code}' https://yuris.com.br/pagina-que-nao-existe # 404 brandada
```

## 7. Como testar e monitorar (pós-deploy)

| O quê | Onde |
|---|---|
| Indexação + sitemap | Google Search Console → adicionar propriedade `yuris.com.br` → Sitemaps → enviar `https://yuris.com.br/sitemap.xml` → acompanhar "Páginas" |
| Rich results (FAQ, Product, Organization) | https://search.google.com/test/rich-results — testar `/`, `/planos.php` e 2-3 páginas de soluções |
| Schema bruto | https://validator.schema.org |
| robots.txt | GSC → Configurações → robots.txt; ou `curl https://yuris.com.br/robots.txt` |
| OG/WhatsApp preview | https://developers.facebook.com/tools/debug/ com `https://yuris.com.br/` (deve mostrar a og-image 1200×630) |
| Performance / CWV | https://pagespeed.web.dev com a URL de prod (medir antes/depois; logo 750 KB→7,8 KB deve mudar o LCP) |
| Bing | Bing Webmaster Tools → importar do GSC |
| Monitorar IA (GEO) | Perguntar a ChatGPT/Claude/Perplexity "o que é o Yuris sistema jurídico?" periodicamente; os bots precisam de semanas pra recrawlear |

**Métrica-mãe:** GSC → Desempenho → consultas tipo "sistema jurídico", "crm jurídico", "software jurídico" — impressões e cliques nas novas páginas ao longo de 60-90 dias.
