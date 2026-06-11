# Páginas programáticas — arquitetura e regras (Yuris)

> Como escalar novas landing pages de SEO sem quebrar nada e sem criar thin content.
> Criado em 2026-06-11 junto com a implementação SEO/GEO inicial.

## Como criar uma página nova (5 passos)

1. **Crie a pasta com o slug** em `public/`:
   `public/<slug-com-hifens>/index.php` → vira `https://yuris.com.br/<slug-com-hifens>/`
   *(Funciona sem rewrite — em produção o Apache usa DirectoryIndex. NÃO use `.htaccess` para slug: em prod ele depende do `AllowOverride All` do Dockerfile.)*

2. **Copie o padrão da página exemplar** `public/sistema-juridico/index.php`:
   um array `$SEO_PAGE` + `require __DIR__ . '/../includes/seo_page.php';`
   Os campos estão documentados no docblock de `public/includes/seo_page.php`.

3. **Conteúdo mínimo obrigatório** (anti thin content):
   - 700+ palavras originais, intenção de busca única (não competir com página existente);
   - 1 bloco `<div class="sp-definicao">` com definição direta (AEO);
   - 1 comparativo (tabela `sp-tabela` ou listas);
   - 4-6 FAQs com respostas de 40-80 palavras (viram schema FAQPage automaticamente);
   - 3-6 links internos contextuais + 3 cards "Veja também";
   - title ≤ 60 chars terminando em `| Yuris`; description 140-155 chars única.

4. **Registre a página**:
   - `public/sitemap.xml` → novo `<url>` com lastmod do dia;
   - rodapés: coluna "Soluções" em `public/includes/lp_footer.php` E no footer do `public/index.php` (são duplicados de propósito);
   - se fizer sentido, link contextual a partir de 1-2 páginas irmãs.

5. **Valide**: `C:\xampp\php\php.exe -l public/<slug>/index.php` + abrir no preview local
   (`php -S 127.0.0.1:8090 -t public`) e conferir title/canonical/JSON-LD.

## Fatos que NUNCA podem ser afirmados (fact sheet 2026-06-11)

- ❌ Teste grátis / trial (não existe oferta pública)
- ❌ Números de clientes, depoimentos, avaliações, notas
- ❌ "100% conforme LGPD" → usar **"medidas técnicas e organizacionais... processo contínuo de adequação à LGPD"**
- ❌ Integração com tribunais/PJe/peticionamento/API pública/app mobile/SLA/reembolso
  (reais: webhooks n8n/Make/Zapier; intimações DJEN/DataJud/AASP — AASP com chave do cliente)
- ❌ "Agente IA que responde sozinho" → módulo existe mas é **configurável com chave de API do próprio escritório**
- ❌ Aconselhamento jurídico / substituir advogado
- ❌ Promessa de "conseguir mais clientes" (OAB) — prospecção = organização do fluxo
- ✅ Preços públicos: somente os de `planos.php` (a partir de R$ 220/mês, tudo incluído)

## Slugs candidatos (fila de produção programática)

Cada um SÓ deve ser criado com conteúdo original e ângulo próprio — nunca clonar
uma página existente trocando palavras:

| Slug | Palavra-chave | Ângulo único |
|---|---|---|
| `/sistema-para-advogado-autonomo/` | sistema para advogado autônomo | rotina solo: 1-2 usuários, preço de entrada, sem equipe |
| `/sistema-juridico-para-escritorios-pequenos/` | software jurídico escritório pequeno | transição planilha→sistema, custo previsível |
| `/software-juridico-controle-de-prazos/` | controle de prazos jurídicos | prazo como unidade central; intimação→prazo→tarefa |
| `/atendimento-whatsapp-advogados/` | whatsapp para advogados | conversa vinculada a cliente/processo; fim do WhatsApp pessoal |
| `/intimacoes-djen-datajud/` | monitoramento de intimações | as 3 fontes, dedupe, cota — página técnica |
| `/sistema-juridico-matriz-filial/` | software jurídico multi unidade | matriz/filial, permissões por escopo, visão consolidada |
| `/crm-juridico-para-captacao-de-clientes/` | crm captação advocacia | funil de quem JÁ procura o escritório (cuidado OAB) |
| `/gestao-financeira-escritorio-advocacia/` | gestão financeira advocacia | variação de /financeiro-juridico/ — só criar se a principal já ranquear |

## O que NÃO fazer

- Não criar páginas de cidade ("advogado em Mauá") — o Yuris é SaaS nacional, isso seria spam.
- Não criar 2 páginas pra mesma intenção (canibalização) — antes de criar, confira o sitemap.
- Não publicar página sem revisão humana do conteúdo.
- Não linkar páginas que ainda não existem (404 interno).
