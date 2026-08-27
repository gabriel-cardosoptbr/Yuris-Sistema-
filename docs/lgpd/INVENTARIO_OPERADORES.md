# Inventário de Operadores — Yuris

**Base legal:** LGPD Art. 37 (registro) + Art. 33 (transferência internacional) + Art. 39 (DPA).
**Versão:** 1.0 — 2026-05-23
**Mantenedor:** Encarregado de Proteção de Dados (DPO) da Yuris
**Fonte vivente:** tabela `data_processors` no banco do sistema (ver Painel Master → Operadores).
**Este documento é uma snapshot** — para o estado atual, sempre consultar o Painel Master.

---

## 1. Sumário inicial

| Categoria | Quantos | Com DPA assinado | Transferência intl |
|-----------|---------|------------------|---------------------|
| API externa | 1 | 0 | 0 |
| Hospedagem | 2 | 0 (dispensado×2) | 0 |
| Gateway pagamento | 1 | 0 | 0 |
| SMTP | 1 | 0 | 0 |
| LLM / IA | 1 | 0 | **1** ⚠ |
| **TOTAL inicial** | **6** | **2 dispensados** | **1** |

> Status inicial: maioria pendente.  Plano de ação documentado em `POLITICA_TERCEIROS.md`.

---

## 2. Operadores ativos (estado inicial — seed da migration 055)

### 2.1 Evolution API (WhatsApp)

| Campo | Valor |
|-------|-------|
| Categoria | API externa |
| Papel | Operador |
| País | Brasil |
| Transferência internacional | Não |
| DPA | **Pendente** |
| Dados tratados | PII básica, comunicações |
| Finalidade | Envio/recebimento de mensagens WhatsApp em nome dos tenants |
| Política privacidade | https://doc.evolution-api.com/ |
| Notas | Open-source self-hosted. Cada deploy de tenant tem sua própria política. Conexão TLS verificada via `EVOLUTION_TLS_VERIFY` no `.env`. |

**Ação pendente:** definir se cada tenant assina seu próprio DPA com seu provedor Evolution (modelo recomendado) ou se a Yuris assume responsabilidade contratual.

### 2.2 MariaDB (storage primário)

| Campo | Valor |
|-------|-------|
| Categoria | Hospedagem |
| Papel | Operador |
| País | Brasil |
| Transferência internacional | Não |
| DPA | **Dispensado** — software open-source (GPL), instalado em infra própria/contratada |
| Dados tratados | Toda a base (PII, documentos, financeiro, jurídico, autenticação, comunicações) |
| Finalidade | Armazenamento primário |
| Notas | Auto-hospedado. A segurança depende da infra que hospeda. MFA + ACLs aplicados via configuração interna. |

### 2.3 Apache HTTP Server

| Campo | Valor |
|-------|-------|
| Categoria | Hospedagem |
| Papel | Operador |
| País | Brasil |
| Transferência internacional | Não |
| DPA | **Dispensado** — software open-source (Apache Software Foundation) |
| Dados tratados | PII básica (IP, user-agent em logs) |
| Finalidade | Servidor web (HTTP/HTTPS) |
| Notas | Logs rotacionados conforme infraestrutura (30-90 dias típicos). |

### 2.4 Gateway de Pagamento

| Campo | Valor |
|-------|-------|
| Categoria | Gateway pagamento |
| Papel | Operador |
| País | Brasil (a definir) |
| Transferência internacional | Não (a confirmar quando contratado) |
| DPA | **Pendente** |
| Dados tratados | PII básica, documentos, financeiro |
| Finalidade | Processar cobranças, assinaturas e pagamentos |
| Notas | **Em produção:** atualmente `NullGateway` (dev). Antes de ir live, contratar Stripe / MercadoPago / Asaas, assinar DPA, atualizar este registro com CNPJ, país, política. |

### 2.5 Provedor SMTP

| Campo | Valor |
|-------|-------|
| Categoria | SMTP |
| Papel | Operador |
| País | Brasil (típico) ou EUA (se SendGrid/SES/Mailgun) |
| Transferência internacional | Depende do escolhido |
| DPA | **Pendente** |
| Dados tratados | PII básica (nomes, e-mails) |
| Finalidade | Envio de e-mails transacionais (confirmação, recuperação senha, notificações LGPD) |
| Notas | Atualmente driver `log` (dev). Quando contratar provedor real, atualizar este registro com país, base legal de transferência intl se aplicável. |

### 2.6 LLM / IA (OpenAI / Anthropic / outros)

| Campo | Valor |
|-------|-------|
| Categoria | LLM / IA |
| Papel | Operador |
| País | **Estados Unidos** ⚠ |
| Transferência internacional | **SIM** ⚠ |
| Base legal | Cláusulas contratuais padrão |
| DPA | **Pendente** |
| Certificações | OpenAI: SOC 2 Type II · Anthropic: SOC 2 Type II |
| Dados tratados | PII básica, comunicações, jurídico (depende do uso pelo tenant) |
| Finalidade | Geração de respostas automáticas no agente IA do WhatsApp |
| Retenção do terceiro | ~30 dias (política do provedor) |
| Política privacidade | OpenAI: `https://openai.com/policies/privacy-policy` · Anthropic: `https://www.anthropic.com/legal/privacy` |
| Notas | **IMPORTANTE:** cada tenant que ativar o agente IA está exportando dados para os EUA. Recomendar contratos *zero-retention* quando possível.  Tenants devem informar seus titulares na política de privacidade da própria conta. |

---

## 3. Operadores pendentes de cadastro (mapeados, não populados)

Estes são identificados na auditoria mas dependem de decisão/contratação para entrar no inventário:

- **CDN** — se em produção for usado Cloudflare/Fastly: adicionar (origem EUA, contrato + DPA).
- **Backup off-site** — quando definido (S3, Backblaze, etc.).
- **Monitoramento APM** — se contratado Sentry/Datadog/NewRelic.
- **Analytics** — Google Analytics gera transferência internacional + base legal (consentimento). Avaliar se Plausible/Matomo self-hosted são alternativas mais alinhadas à LGPD.
- **Cloud hosting** — em produção, se hospedado em AWS/Azure/GCP/Linode, registrar contrato + DPA + ler atentamente a cláusula de subcontratação.

---

## 4. Procedimentos relacionados

- **Avaliar novo operador antes de contratar:** seguir `POLITICA_TERCEIROS.md`.
- **Modelo de contrato:** `DPA_TEMPLATE.md` (cláusulas mínimas Art. 39).
- **Quando houver incidente envolvendo operador:** aplicar `PROCEDIMENTO_INCIDENTES.md` + comunicar imediatamente ao operador para apoio na contenção.
- **Renovações de DPA:** badge no Painel Master alerta com 30 dias de antecedência.

---

## 5. Histórico de mudanças

| Data | Mudança | Por |
|------|---------|-----|
| 2026-05-23 | Versão inicial — 6 operadores seedados via migration 055 | (Etapa 9 do roadmap LGPD) |

---

**Próxima revisão prevista:** ao contratar gateway/SMTP real, ou anualmente, o que vier primeiro.
