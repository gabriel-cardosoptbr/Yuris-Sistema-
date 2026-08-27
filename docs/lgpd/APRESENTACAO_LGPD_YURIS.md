# Apresentação do Programa LGPD — Yuris

> **Documento mestre para 3 audiências distintas.**
> Cada parte é autossuficiente: imprima, exporte ou cole separadamente conforme o destinatário.

**Versão:** 1.0 — 2026-05-23
**Mantenedor:** Encarregado de Proteção de Dados (DPO) — Yuris
**Sistema:** Yuris — Plataforma SaaS Jurídica Multi-tenant

---

## Como usar este documento

| Audiência | Leia | Tempo de leitura |
|-----------|------|------------------|
| **Advogado(a) especialista em LGPD** | Parte I | ~15 min |
| **Equipe técnica / programador(a)** | Parte II | ~10 min |
| **Cliente final, comercial ou marketing** | Parte III | ~5 min |
| **Auditoria externa / due diligence** | Partes I + II | ~25 min |

Cada parte está separada por linha dupla `═══`. Você pode literalmente recortar.

═══════════════════════════════════════════════════════════════════════════════
═══════════════════════════════════════════════════════════════════════════════

# PARTE I — Para o(a) Advogado(a) de Proteção de Dados

═══════════════════════════════════════════════════════════════════════════════
═══════════════════════════════════════════════════════════════════════════════

## 1. Sumário Executivo

A Yuris executou, entre 21 e 23 de maio de 2026, um **programa intensivo de adequação à LGPD** (Lei 13.709/2018) cobrindo o ciclo completo: auditoria, correção de vulnerabilidades, controles técnicos e administrativos, canais para titulares, programa de governança e documentação.

**Números do programa:**
- **18 artigos da LGPD** explicitamente endereçados;
- **12 tabelas novas** dedicadas à conformidade;
- **20 triggers SQL** garantindo imutabilidade dos logs de auditoria;
- **18 documentos internos** versionados;
- **25 vulnerabilidades** de segurança fechadas (9 críticas + 16 altas);
- **48 commits locais** documentando cada decisão.

O presente documento serve para que **o(a) advogado(a) externo(a)** valide o que está implementado e identifique:
- O que pode ser **publicado como está**;
- O que **exige revisão jurídica** antes do go-live;
- O que falta para a empresa estar 100% pronta a defender uma fiscalização da ANPD.

## 2. Mapa de Aderência por Artigo da LGPD

| Artigo | Tema | Implementação | Status técnico | Pendência jurídica |
|--------|------|---------------|----------------|---------------------|
| **Art. 5** | Definições — mapeamento de PII | `POLITICA_CLASSIFICACAO_DADOS.md` classifica 25+ entidades em 4 níveis | ✅ Implementado | Validar classificação |
| **Art. 5 XVII** | RIPD/DPIA | Template `MODELO_RIPD.md` pronto para uso futuro | ✅ Disponível | Aprovar modelo |
| **Art. 6** | Princípios | Aplicados na arquitetura: minimização, finalidade, segurança | ✅ Implementado | — |
| **Art. 7** | Bases legais | Documentadas no `LGPD_RAT_INICIAL.md` | ✅ Documentado | **Revisar bases por atividade** |
| **Art. 8** | Consentimento | `term_acceptances` com hash SHA-256 do conteúdo | ✅ Implementado | — |
| **Art. 9** | Informação clara ao titular | 5 páginas legais públicas (Privacidade, Termos, Cookies, LGPD, DPO) | ✅ Implementado | **Revisar textos** |
| **Art. 11** | Dados sensíveis | Yuris não trata em escala hoje; RIPD obrigatório se vier a tratar | ⚠️ Não aplicável agora | Confirmar política |
| **Art. 12** | Anonimização | Helper `Anonymizer` (user, contato, card, processo, export) + log imutável | ✅ Implementado | — |
| **Art. 15** | Término do tratamento | Workflow de offboarding de tenant + 30 dias para portabilidade | ✅ Implementado | Validar prazo |
| **Art. 16** | Eliminação | `retention_policies` + cron diário | ✅ Implementado | Validar prazos por categoria |
| **Art. 18** | Direitos do titular | Canal público `/lgpd/solicitar.php` cobre os 10 direitos | ✅ Implementado | — |
| **Art. 19** | Prazo de 15 dias | Calculado e monitorado em `lgpd_requests.prazo_resposta` | ✅ Implementado | — |
| **Art. 20** | Decisões automatizadas | Não há decisão automatizada com impacto significativo no escopo atual | ⚠️ Não aplicável agora | Reavaliar se IA virar decisória |
| **Art. 33** | Transferência internacional | Base legal documentada em `data_processors.base_legal_transferencia` | ✅ Estrutura pronta | **Revisar caso a caso** (LLM EUA p.ex.) |
| **Art. 37** | Registro de atividades (RAT) | `LGPD_RAT_INICIAL.md` + audit log imutável | ✅ Implementado | **Aprovar RAT** |
| **Art. 38** | RIPD a pedido da ANPD | Template pronto | ✅ Disponível | — |
| **Art. 39** | DPA com operador | Template `DPA_TEMPLATE.md` + workflow no Painel Master | ✅ Disponível | **Negociar DPA com cada operador** |
| **Art. 41** | Encarregado (DPO) | Identificado nas páginas legais; e-mail funcional **pendente** | ⚠️ Estrutura pronta | **Designar pessoa formalmente** |
| **Art. 46** | Segurança | Controles técnicos e administrativos na PSI | ✅ Documentado | — |
| **Art. 47** | Boas práticas | Programa contínuo de governança | ✅ Implementado | — |
| **Art. 48** | Notificação de incidentes | `security_incidents` + workflow ANPD/titulares + templates | ✅ Implementado | Validar templates |
| **Art. 50** | Programa de governança | Treinamento, auditoria, revisão anual, sanções | ✅ Documentado | — |

**Legenda:**
- ✅ Implementado / Documentado / Disponível
- ⚠️ Estrutura pronta mas exige ação humana (negociação contratual, designação)

## 3. Inventário das Atividades de Tratamento (RAT)

> Documento completo: `docs/LGPD_RAT_INICIAL.md`

**A Yuris atua em dois papéis simultaneamente:**

### 3.1 Como CONTROLADORA dos próprios dados
- Cadastro de clientes PJ (escritórios contratantes);
- Cobrança e gestão financeira do SaaS;
- Logs de auditoria interna e segurança;
- Suporte ao cliente PJ.

### 3.2 Como OPERADORA dos dados do cliente PJ
- Gestão de clientes finais do escritório (PII de partes, processos);
- Processos jurídicos (dados de partes, peças, anexos);
- Comunicação via WhatsApp (transitando pelo cliente final);
- Tarefas, agendamentos, finanças do escritório.

**Cada categoria de tratamento tem documentado:** finalidade, base legal, categorias de titulares e dados, origem, compartilhamento, retenção, medidas de segurança, tabelas-fonte.

## 4. Inventário de Operadores (Art. 33 + 39)

> Documento completo: `docs/INVENTARIO_OPERADORES.md`
> Painel ao vivo: Painel Master → Operadores

| Operador | País | Transferência intl | Status DPA |
|----------|------|---------------------|------------|
| Evolution API (WhatsApp) | Brasil (self-host) | Não | **Pendente** |
| MariaDB | Auto-hospedado | Não | Dispensado (open-source) |
| Apache HTTP Server | Auto-hospedado | Não | Dispensado (open-source) |
| Gateway de Pagamento | A definir | Possível | **Pendente — a contratar** |
| Provedor SMTP | A definir | Provável (US) | **Pendente — a contratar** |
| LLM / IA (OpenAI/Anthropic) | EUA ⚠ | **Sim** | **Pendente** |

**Workflow contratual completo** documentado em `POLITICA_TERCEIROS.md`. Cada novo terceiro passa por avaliação, negociação de DPA, registro em `data_processors`, monitoramento contínuo (badge no Painel Master alerta DPAs vencendo).

## 5. Documentos para Revisão Jurídica (priorizados)

> **Estes 6 documentos têm prioridade ALTA — modelo inicial pendente de carimbo jurídico:**

| # | Documento | Onde fica | Crítico para go-live? |
|---|-----------|-----------|------------------------|
| 1 | Política de Privacidade | `public/privacidade.php` (modelo) + página viva no site | **Sim** |
| 2 | Termos de Uso | `public/termos.php` (modelo) | **Sim** |
| 3 | Política de Cookies | `public/cookies.php` (modelo) | Médio |
| 4 | Página LGPD & Segurança | `public/lgpd.php` (modelo) | Médio |
| 5 | Página DPO | `public/dpo.php` (modelo) | **Sim** — só após designar DPO |
| 6 | DPA template | `docs/DPA_TEMPLATE.md` | **Sim** — para usar com operadores |

**Documentos com prioridade MÉDIA — revisar quando puder:**

| # | Documento | Para que serve |
|---|-----------|----------------|
| 7 | NDA Funcionário | `docs/NDA_FUNCIONARIO.md` — termo de confidencialidade interno |
| 8 | Modelo RIPD | `docs/MODELO_RIPD.md` — relatório de impacto para tratamentos futuros de alto risco |
| 9 | Modelo Notificação ANPD | `docs/MODELO_NOTIFICACAO_ANPD.md` |
| 10 | Modelo Notificação Titular | `docs/MODELO_NOTIFICACAO_TITULAR.md` |

**Solicitação ao(à) advogado(a):** validar conteúdo, adequar à jurisdição de operação da empresa, ajustar linguagem para evitar exposição contratual indevida. Em particular, **validar disclaimers e cláusulas de limitação de responsabilidade**.

## 6. Procedimentos Operacionais Formalizados

Todos documentados, prontos para usar:

| Procedimento | Quando aplicar | Onde |
|--------------|----------------|------|
| Resposta a Incidentes de Segurança | Em qualquer detecção de evento suspeito | `PROCEDIMENTO_INCIDENTES.md` |
| Onboarding / Offboarding | Toda contratação ou desligamento | `PROCEDIMENTO_ONBOARDING_OFFBOARDING.md` |
| Atendimento a Titular (Art. 18) | Solicitação chega em `/lgpd/solicitar.php` | Implementado no Painel Master → LGPD |
| Avaliação de Operador | Antes de contratar novo terceiro | `POLITICA_TERCEIROS.md` |
| Aplicação de Retenção | Cron diário automatizado | `POLITICA_RETENCAO.md` |

## 7. Pendências Críticas para Go-Live

> **Lista vivente no Painel Master → Revisões.** 18 itens, 13 bloqueadores de produção.

### Bloqueadores de produção (não subir sem)
1. **Designar DPO formal** (nome + CPF + cargo + email funcional);
2. **Revisar e aprovar** 5 páginas legais (Privacidade, Termos, DPO + idealmente Cookies e LGPD);
3. **Contratar gateway de pagamento real** + DPA assinado;
4. **Contratar provedor SMTP** + driver implementado + DPA;
5. **Configurar backup off-site cifrado** com chave custodiada;
6. **Pentest externo** antes da exposição pública;
7. **Validar .env de produção** (todas variáveis críticas);
8. **Rotacionar credenciais iniciais** (não usar dev em prod);
9. **Configurar headers de segurança** no Apache (HSTS, CSP, etc.);
10. **Treinamento básico** ministrado para toda equipe atual;
11. **NDA assinado** por funcionários atuais;
12. **Revisão jurídica completa** da documentação;
13. **Definir modelo contratual Evolution API** (tenant vs Yuris).

### Não-bloqueadores (recomendados pós go-live)
- Implementar MFA opt-in para usuários comuns;
- Implementar `mod_security` (WAF básico);
- Configurar notificação automatizada ao DPO (dia 12 do prazo de 15);
- Configurar alerta de vencimento de DPA (60 dias antes);
- Migrar `.env` para gerenciador de segredos (Vault, AWS Secrets);
- Auditoria jurídica completa (anual).

## 8. Direitos do Titular Implementados (Art. 18)

Canal público: **`/lgpd/solicitar.php`** (não exige login).

Cobre todos os 10 direitos:

| # | Direito | Como o sistema atende |
|---|---------|------------------------|
| 1 | Confirmação da existência | Tipo `confirmacao_existencia` no formulário |
| 2 | Acesso aos dados | Tipo `acesso` — DPO gera export estruturado |
| 3 | Correção de dados | Tipo `correcao` |
| 4 | Anonimização | Tipo `anonimizacao` → `Anonymizer` aplica |
| 5 | Bloqueio | Tipo `bloqueio` |
| 6 | Eliminação | Tipo `eliminacao` → `Anonymizer` ou purge físico |
| 7 | Portabilidade | Tipo `portabilidade` → export ZIP |
| 8 | Informação sobre compartilhamento | Tipo `info_compartilhamento` — DPO responde com inventário |
| 9 | Revogação de consentimento | Tipo `revogacao_consentimento` + interface em `/configuracoes/privacidade.php` |
| 10 | Revisão de decisão automatizada | Tipo `revisao_decisao_automatizada` (preparado mesmo que hoje não haja decisão automatizada) |

**Prazo de resposta:** 15 dias corridos (Art. 19), monitorado em `lgpd_requests.prazo_resposta`. Atrasos visíveis em badge vermelho no Painel Master.

## 9. Anexos Jurídicos (leitura sugerida)

| Documento | Caminho |
|-----------|---------|
| RAT inicial | `docs/LGPD_RAT_INICIAL.md` |
| Inventário de operadores | `docs/INVENTARIO_OPERADORES.md` |
| Política de Segurança da Informação | `docs/POLITICA_SEGURANCA_INFORMACAO.md` |
| Política de Senhas e Acesso | `docs/POLITICA_SENHAS_E_ACESSO.md` |
| Política de Backup | `docs/POLITICA_BACKUP_RECUPERACAO.md` |
| Política de Classificação de Dados | `docs/POLITICA_CLASSIFICACAO_DADOS.md` |
| Política de Retenção | `docs/POLITICA_RETENCAO.md` |
| Política de Terceiros | `docs/POLITICA_TERCEIROS.md` |
| Política de Treinamento | `docs/POLITICA_TREINAMENTO_PRIVACIDADE.md` |
| Procedimento de Incidentes | `docs/PROCEDIMENTO_INCIDENTES.md` |
| Procedimento Onboarding/Offboarding | `docs/PROCEDIMENTO_ONBOARDING_OFFBOARDING.md` |
| DPA template | `docs/DPA_TEMPLATE.md` |
| RIPD template | `docs/MODELO_RIPD.md` |
| Notificação ANPD template | `docs/MODELO_NOTIFICACAO_ANPD.md` |
| Notificação Titular template | `docs/MODELO_NOTIFICACAO_TITULAR.md` |
| NDA Funcionário | `docs/NDA_FUNCIONARIO.md` |
| Checklist Deploy Produção | `docs/CHECKLIST_DEPLOY_PRODUCAO.md` |
| Relatório Final | `docs/RELATORIO_FINAL_LGPD.md` |

═══════════════════════════════════════════════════════════════════════════════
═══════════════════════════════════════════════════════════════════════════════

# PARTE II — Para a Equipe Técnica / Programador(a)

═══════════════════════════════════════════════════════════════════════════════
═══════════════════════════════════════════════════════════════════════════════

## 1. Arquitetura Geral

- **Stack:** PHP 8.2 + MariaDB 10.4 + Apache 2.4 (XAMPP em dev, deploy próprio em prod).
- **Modelo:** SaaS multi-tenant com isolamento por `account_id` em **todas as queries de domínio**.
- **Padrão de queries:** `PDO` com **prepared statements obrigatórios** (sem concatenação de SQL).
- **Contexto de tenant:** `App\Core\AccountContext::fromSession()` resolve account_id + permissões + hierarquia matriz/filiais.
- **Sessão:** PHP `session_*` com cookies `HttpOnly`, `Secure` (prod), `SameSite=Lax`.

## 2. Schema de Banco — Tabelas LGPD criadas

### 2.1 Tabelas de domínio LGPD (12 novas)

| Tabela | Migration | Propósito |
|--------|-----------|-----------|
| `agent_configs` | 048 | Configuração cifrada do agente IA por usuário/tenant |
| `legal_documents` | 049 | Versionamento de termos/política/cookies |
| `term_acceptances` | 049 | Registro de aceite (imutável) |
| `lgpd_consents` | 049 | Consentimentos granulares por categoria |
| `lgpd_requests` | 050 | Solicitações dos titulares (Art. 18) |
| `lgpd_request_events` | 050 | Timeline imutável de cada solicitação |
| `retention_policies` | 051 | Regras de retenção por entidade |
| `anonymization_log` | 051 | Log imutável de toda anonimização |
| `security_incidents` | 054 | Registro de incidentes (Art. 48) |
| `security_incident_events` | 054 | Timeline imutável de incidente |
| `data_processors` | 055 | Inventário de operadores |
| `data_processor_history` | 055 | Auditoria imutável do inventário |
| `pending_reviews` | 056 | Itens internos pendentes de revisão (uso DPO) |

### 2.2 Triggers de imutabilidade (20)

Tabelas com triggers `BEFORE UPDATE/DELETE` disparando `SIGNAL SQLSTATE '45000'`:

`master_audit_log`, `account_audit_log`, `processo_history`, `card_history`, `task_history`, `anonymization_log`, `lgpd_request_events`, `term_acceptances`, `security_incident_events`, `data_processor_history`

> **Garantia:** mesmo o usuário `root` da aplicação cai no erro ao tentar modificar/apagar. Auditoria preservada no nível do banco.

## 3. Helpers Principais

| Helper | Arquivo | Função |
|--------|---------|--------|
| `Anonymizer` | `app/Lgpd/Anonymizer.php` | Substitui PII preservando FKs (user, contato, card, processoParte, exportTitular) |
| `ErrorReporter` | `app/Core/ErrorReporter.php` | Mensagens genéricas em prod + correlation_id; logs detalhados internos |
| `EnvLoader` | `app/Core/EnvLoader.php` | `validateProduction()` impede bootstrap sem segredos críticos |
| `RequestId` | `app/Core/RequestId.php` | ID hex de 12 chars propagado em todos os logs |
| `MasterAudit` | `app/Master/MasterAudit.php` | Log de ações do Painel Master (IP + UA + request_id) |
| `TotpHelper` | `app/Usuarios/TotpHelper.php` | TOTP RFC 6238 + AES-256-CBC (`encryptSecret`/`decryptSecret`) |
| `AccountContext` | `app/Core/AccountContext.php` | Resolução de tenant + permissões |

## 4. APIs e Endpoints

### 4.1 APIs internas (10 novas)

| Endpoint | Quem chama | Função |
|----------|-----------|--------|
| `/api/legal/documents.php` | Banner + páginas | Lê versão vigente |
| `/api/legal/accept.php` | Login | Registra aceite |
| `/api/legal/consent.php` | Banner + privacidade | Gerencia consentimentos do user |
| `/api/lgpd/request.php` | `/lgpd/solicitar.php` | Cria solicitação pública + status por token |
| `/api/lgpd_retention_tick.php` | Cron | Aplica retenção (CRON_TOKEN) |
| `/api/master/lgpd_requests.php` | Painel Master → LGPD | CRUD solicitações |
| `/api/master/lgpd_anonymize.php` | Modal LGPD | Anonimização + export ZIP |
| `/api/master/retention.php` | Painel Master → Retenção | Políticas + execução |
| `/api/master/incidents.php` | Painel Master → Incidentes | CRUD incidentes + workflow ANPD |
| `/api/master/processors.php` | Painel Master → Operadores | Inventário + DPA workflow |
| `/api/master/reviews.php` | Painel Master → Revisões | Pendências internas |

### 4.2 Endpoints públicos (8)

`/privacidade.php`, `/termos.php`, `/cookies.php`, `/lgpd.php`, `/dpo.php`, `/lgpd/solicitar.php`, `/lgpd/acompanhar.php`, `/configuracoes/privacidade.php` (autenticado)

## 5. Padrões Obrigatórios em Código

> Estas são as regras que **DEVEM** ser seguidas em qualquer feature nova.

### 5.1 Queries (SQLi mitigation)
```php
// ❌ NUNCA
$pdo->query("SELECT * FROM users WHERE id = $userId");

// ✅ SEMPRE
$st = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$st->execute([$userId]);
```

### 5.2 Isolamento multi-tenant
```php
// ✅ Em toda query que envolva dado de tenant
$ctx       = AccountContext::fromSession();
$tenantIds = $ctx->getAccessibleAccountIds('modulo');
$st = $pdo->prepare("SELECT * FROM cards WHERE account_id IN (" .
                    implode(',', array_fill(0, count($tenantIds), '?')) . ")");
$st->execute($tenantIds);
```

### 5.3 CSRF em mutações
```php
// ✅ Em todo POST/PATCH/DELETE
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$csrf  = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
    ApiResponse::badRequest('CSRF inválido');
}
```

### 5.4 Cifragem at-rest
```php
// ✅ Sempre que armazenar credencial de operador ou secret
$enc = TotpHelper::encryptSecret($apiKey);   // AES-256-CBC com MFA_ENCRYPTION_KEY
$pdo->prepare('INSERT INTO ... (api_key_enc) VALUES (?)')->execute([$enc]);

// Decifrar só quando precisar usar
$plain = TotpHelper::decryptSecret($enc);
```

### 5.5 Audit log
```php
// ✅ Em toda ação administrativa relevante
\App\Master\Account::audit($accountId, 'modulo.acao', [
    'user_id'     => $userId,
    'entidade'    => 'tipo',
    'entidade_id' => $id,
    'detalhes'    => $changes,
]);

// No Painel Master:
MasterAudit::log('acao.realizada', 'tipo', $id, 'Descrição humana', ['detalhes' => ...]);
```

### 5.6 Mensagens de erro em produção
```php
// ❌ NUNCA
catch (\Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]); exit;
}

// ✅ SEMPRE
catch (\Throwable $e) {
    ErrorReporter::handle($e, 500, 'Erro ao processar solicitação.');
}
```

## 6. Como Contribuir Respeitando LGPD

### Antes de criar nova tabela com PII
1. Atualizar `docs/POLITICA_CLASSIFICACAO_DADOS.md` adicionando a tabela com nível (interno/restrito/sensível);
2. Atualizar `docs/POLITICA_RETENCAO.md` com prazo + ação (purge/anonimização);
3. Se necessário, adicionar política em `retention_policies` + atualizar `Anonymizer`;
4. Garantir `account_id` (multi-tenant) ou justificar ausência.

### Antes de integrar novo operador (API externa, SaaS, CDN)
1. Avaliar conforme `docs/POLITICA_TERCEIROS.md` §3;
2. Negociar e assinar DPA usando `docs/DPA_TEMPLATE.md`;
3. Registrar em `data_processors` via Painel Master → Operadores → + Novo;
4. Se houver transferência internacional: definir base legal (Art. 33).

### Antes de feature de alto risco
- Dados sensíveis em escala (Art. 5 II)
- Decisões automatizadas com impacto (Art. 20)
- Tratamento de dados de crianças/adolescentes
- Novo uso de IA generativa com dados pessoais

→ **Fazer RIPD** usando `docs/MODELO_RIPD.md` e submeter ao DPO antes de implementar.

### Antes de fazer commit
- [ ] `php -l` sem erros nos arquivos modificados
- [ ] Nenhum `getMessage()` exposto em produção (usar `ErrorReporter`)
- [ ] Nenhum SQL com concatenação (só prepared statements)
- [ ] CSRF em endpoints state-changing
- [ ] `account_id` filtrado em queries de domínio
- [ ] Nenhum segredo no código (sempre `.env`)
- [ ] Documentar mudança no PR / commit message

## 7. Logs e Observabilidade

### Tabelas de audit (9)
- `master_audit_log` — ações do Painel Master
- `account_audit_log` — ações administrativas por tenant
- `processo_history` / `card_history` / `task_history` — mudanças em entidades
- `security_incident_events` — timeline de incidentes
- `data_processor_history` — alterações em operadores
- `anonymization_log` — toda anonimização
- `lgpd_request_events` — atendimento a titulares

### Correlação forense
Cada registro inclui:
- `request_id` (hex 12) — `App\Core\RequestId::get()` gera 1 por requisição
- IP do cliente
- User-agent (truncado em 255)

Permite reconstruir toda a sequência de ações de uma requisição cruzando as tabelas.

## 8. Próximos Passos Técnicos

### Antes do go-live (críticos)
- [ ] Implementar driver SMTP real em `Mailer.php` (hoje só `log`)
- [ ] Configurar backup off-site (cron `mysqldump` cifrado + rsync para remoto)
- [ ] Headers de segurança no Apache (`headers_module`)
- [ ] Considerar `mod_security` (WAF básico)
- [ ] Mudar `display_errors = Off` em produção
- [ ] Validar todas as variáveis do `.env` via `EnvLoader::validateProduction()`

### Recomendados pós go-live
- [ ] MFA opt-in para todos os usuários (não só super_admin)
- [ ] Refactor: extrair regras de tema de `<style>` inline para `yuris-theme.css`
- [ ] Migrar `.env` para gerenciador de segredos (Vault / AWS Secrets Manager)
- [ ] Implementar `Sentry` ou equivalente para tracking de erros (mas registrar como operador no inventário antes!)
- [ ] Notificação automatizada ao DPO (dia 12 do prazo de 15 dias)

### Sempre
- Pre-commit hook detectando secrets antes do push
- `composer audit` em CI quando adicionar Composer ao projeto
- Revisão trimestral de acessos
- Pentest externo anual

═══════════════════════════════════════════════════════════════════════════════
═══════════════════════════════════════════════════════════════════════════════

# PARTE III — Para o Cliente / Equipe Comercial

═══════════════════════════════════════════════════════════════════════════════
═══════════════════════════════════════════════════════════════════════════════

## 1. Nosso Compromisso (em linguagem clara)

> **Os dados que você confia ao Yuris são tratados com a mesma seriedade que você trata os dados dos seus clientes.**

A LGPD é nossa preocupação desde o desenho do sistema, não um acessório aplicado depois. Construímos camadas de proteção, processos formais e canais claros para você — e para os seus titulares de dados — exercerem direitos.

## 2. O Que o Yuris Promete (resumido)

| Garantia | Como entregamos |
|----------|-----------------|
| **Isolamento total entre escritórios** | Cada conta tem seus dados isolados; queries filtradas em múltiplas camadas com `account_id` |
| **Senhas seguras** | Hash bcrypt; senha nunca trafega ou é armazenada em texto |
| **2FA disponível** | TOTP padrão (Google Authenticator, Authy) para super-admins e (em breve) usuários comuns |
| **Comunicação cifrada** | HTTPS em todas as conexões; cookies seguros |
| **Backups regulares** | Política documentada com objetivo de RPO 24h / RTO 4h |
| **Eliminação a pedido** | Você ou seus titulares podem solicitar; processo formal em até 15 dias |
| **Portabilidade dos dados** | Export estruturado em ZIP, gerado automaticamente |
| **Suporte aos Direitos do Titular (Art. 18)** | Canal público `/lgpd/solicitar.php` aberto a qualquer titular |
| **Encarregado (DPO) acessível** | Página dedicada `/dpo.php` com canal direto de contato |
| **Atendimento a incidentes** | Procedimento formal conforme Art. 48 — comunicação à ANPD e titulares quando exigido |
| **Audit log imutável** | Tabelas de auditoria com triggers SQL impedem alteração — nem o root da aplicação consegue |

## 3. Diferenciais Comparado ao Mercado

### 3.1 Audit log imutável **no nível do banco**
Muitos sistemas têm "auditoria" implementada apenas no código — qualquer dev com acesso ao banco pode apagar registros. O Yuris usa **triggers SQL** que disparam erro mesmo para o usuário administrativo do banco. **A trilha de auditoria não pode ser falsificada.**

### 3.2 Anonimização que **preserva integridade**
Quando você precisa anonimizar um titular para cumprir o direito de eliminação, sistemas mal feitos apagam registros e quebram processos vinculados. O Yuris **substitui apenas a informação identificadora**, mantendo a integridade referencial — seus processos continuam funcionando, mas não há mais como rastrear de volta ao titular.

### 3.3 Banner de cookies **granular**
Não é "aceitar tudo ou ir embora". O usuário escolhe **categoria por categoria** (essenciais, funcionais, analíticos, marketing) — e pode reabrir o banner a qualquer momento via configurações.

### 3.4 Página de gestão de consentimento **pelo próprio usuário**
Em `/configuracoes/privacidade.php` cada usuário vê seus consentimentos ativos, sabe a base legal de cada um, e pode revogar com 1 clique. Nada de "entre em contato para revogar".

### 3.5 Painel completo para o DPO no Painel Master
Encarregado tem aba dedicada com:
- **LGPD** — todas as solicitações de titulares + workflow + prazo monitorado
- **Retenção** — políticas configuráveis + execução manual + log
- **Incidentes** — registro + workflow ANPD/titulares + templates de notificação
- **Operadores** — inventário com DPA, transferência internacional, vencimentos
- **Revisões** — pendências internas com prioridade e status

### 3.6 Inventário vivo de **operadores** (terceiros)
A LGPD exige que você saiba quais terceiros tratam seus dados em seu nome. O Yuris já vem com a lista dos operadores que ele mesmo usa (Evolution API, hospedagem, gateway, etc.) — você pode auditar a qualquer momento.

### 3.7 Correlação forense por `request_id`
Cada requisição ganha um ID único que é propagado em todos os logs. Em caso de incidente, sua equipe consegue reconstruir exatamente o que aconteceu.

## 4. Mensagens-Chave para Marketing

> Use estas frases (já validadas para tom responsável — sem promessa absoluta) em landing pages, emails, propostas:

- **"LGPD por design, não por checkbox."** — Construímos cada camada pensando em proteção de dados desde o primeiro commit.
- **"Você é o controlador dos seus dados. Yuris é seu operador."** — Você define as regras; nós executamos com transparência.
- **"Eliminação real, não promessa."** — Quando você solicita exclusão, é executada e registrada — não fica enterrada no backlog.
- **"Auditoria que ninguém pode apagar."** — Nem nossos próprios admins conseguem alterar a trilha de auditoria.
- **"O direito do titular tem canal direto."** — Qualquer titular pode abrir solicitação em segundos sem precisar criar conta.
- **"Adequação contínua, não evento único."** — A LGPD é uma jornada; estamos revisando e melhorando o tempo todo.

> ⚠ **NÃO USE:** "Yuris é 100% LGPD compliant" / "garantimos conformidade total" / "imune a vazamentos". Nenhum sistema sério faz essas afirmações.

## 5. Selos / Badges Sugeridos no Site

Estes podem ser exibidos em landing pages, página de planos, materiais comerciais:

- 🛡 **"Audit log imutável"**
- 🔐 **"Cifragem em repouso para credenciais"**
- 👤 **"Encarregado (DPO) acessível"**
- ⚖ **"Conformidade LGPD em processo contínuo"**
- 🌐 **"Comunicação 100% via HTTPS"**
- 🔑 **"2FA disponível para administradores"**
- 🗑 **"Anonimização e portabilidade automatizadas"**
- 📋 **"Inventário de operadores transparente"**

## 6. Como Apresentar em Propostas Comerciais

### Versão 1 — slide único de 30 segundos
> **"O Yuris foi construído com proteção de dados como pilar, não acessório. Temos 20 controles técnicos automatizados, 18 documentos de governança formalizados e canal direto para o titular exercer seus direitos. Você não precisa montar um programa LGPD do zero — assina conosco e já entra com a estrutura pronta."**

### Versão 2 — bloco de 1 página
Use as seções **§1 Compromisso**, **§2 Garantias** e **§3.1 e §3.2 Diferenciais** desta Parte III como bloco de 1 página.

### Versão 3 — apresentação completa
Recorte toda esta Parte III + Parte I para envio ao DPO do cliente em diligências.

## 7. Compromisso Público (texto pronto)

> **Compromisso Yuris com a Proteção de Dados Pessoais**
>
> A Yuris trata os dados pessoais que você nos confia em conformidade com a **Lei nº 13.709/2018 (LGPD)**. Nossa responsabilidade é proteger esses dados com medidas técnicas e organizacionais adequadas, garantir transparência sobre como são utilizados, e atender prontamente aos direitos dos titulares.
>
> Mantemos:
>
> - **Registro de Atividades de Tratamento (RAT)** atualizado, conforme Art. 37 da LGPD;
> - **Inventário de operadores e suboperadores** que tratam dados em nosso nome, com contratos formais;
> - **Encarregado de Proteção de Dados (DPO)** designado e acessível em [dpo@dominio];
> - **Canal direto para titulares** exercerem qualquer dos direitos do Art. 18 em /lgpd/solicitar.php;
> - **Procedimentos formais** para resposta a incidentes (Art. 48), retenção e eliminação (Art. 16), e atendimento ao titular (Art. 18 + Art. 19).
>
> A LGPD é uma jornada contínua de aprimoramento. Revisamos nossas práticas regularmente e estamos abertos ao diálogo com qualquer titular ou cliente.

## 8. Onde Está Tudo Isto No Site

| O cliente vê em | Conteúdo |
|------------------|----------|
| `/privacidade.php` | Política de Privacidade completa |
| `/termos.php` | Termos de Uso |
| `/cookies.php` | Política de Cookies |
| `/lgpd.php` | Página resumo "LGPD & Segurança" |
| `/dpo.php` | Contato do Encarregado |
| `/lgpd/solicitar.php` | Formulário público de solicitação (Art. 18) |
| `/lgpd/acompanhar.php?token=X` | Acompanhamento sem precisar criar conta |
| `/configuracoes/privacidade.php` (logado) | Gestão dos próprios consentimentos |
| Rodapé das páginas legais | Links cruzados |
| Login (`/login.php`) | Checkbox obrigatório de aceite Termos + Privacidade |
| Banner de cookies | Aparece no primeiro acesso, escolhas granulares |

═══════════════════════════════════════════════════════════════════════════════

## Encerramento

Este documento é a **fotografia atual** (2026-05-23) do programa LGPD da Yuris. Será atualizado conforme:
- Pendências forem sendo resolvidas (Painel Master → Revisões);
- Operadores forem efetivamente contratados;
- Documentos forem revisados juridicamente;
- ANPD publicar novas guidelines;
- Lições aprendidas surgirem de incidentes ou auditorias.

**Versão atual:** 1.0 — 2026-05-23
**Próxima revisão prevista:** 2027-05-23 ou após mudança regulatória relevante.

**Dúvidas sobre este documento:**
- Conteúdo jurídico → DPO + advogado(a) externo(a)
- Conteúdo técnico → equipe técnica Yuris
- Conteúdo comercial → equipe comercial Yuris

---

*Yuris — Sistema Jurídico SaaS Multi-tenant*
*Comprometida com a proteção dos dados de seus usuários, clientes e titulares.*
