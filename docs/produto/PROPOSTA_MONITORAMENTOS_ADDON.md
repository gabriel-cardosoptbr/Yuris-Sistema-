# Proposta de Arquitetura — Monitoramentos como Add-on

**Data:** 2026-05-26
**Versão:** 1.0 (rascunho — aguardando aprovação)
**Autor:** Claude (síntese de 2 sub-agentes de recon + análise arquitetural)
**Status:** 🟡 Plano apresentado, **NADA implementado**. Aguarda aprovação.

---

## Sumário executivo

O Yuris **já tem** um módulo de push/monitoramento funcional (`push_monitors`, services Push/, endpoints `/api/push/*`, integrações DJEN+AASP) — porém **sem cota nenhuma**. Qualquer usuário com permissão hoje cria monitor à vontade.

### Modelo comercial (definido em 2026-05-26)

> **Plano do sistema = só acesso ao Yuris.**
> **Monitoramento = produto separado, cobrado por unidade (por OAB/advogado monitorado).**

Implicações na arquitetura:
- **Plano padrão NÃO inclui monitoramentos.** Todo cliente começa com **0 monitors disponíveis** — incluindo Enterprise.
- A cota só sobe via **(a) Master liberar manualmente** (grant gratuito/promo) ou **(b) "compra" registrada** (preparado pra gateway futuro, sem implementar agora).
- `plan_features.monitors.limit` **NÃO é seedado** — fica off (ou seedado como 0 explícito). Toda cota efetiva vem de `account_quota_overrides`.
- A tabela `account_quota_overrides` ganha o `source` `'purchase'` justamente pra essa preparação comercial — registramos quantos monitors o cliente contratou, sem (ainda) executar cobrança.

A proposta é construir **uma camada de cota POR CIMA da infraestrutura existente**, reusando 3 padrões consagrados do sistema:
1. **`BillingGuard` + `plan_features`** — pattern já usado pra `max_users`/`max_processos`/`max_cards`/`max_filiais`. Adicionamos `monitors.limit` mas com lógica revisada: default 0, override é a fonte canônica.
2. **`AccountContext::getAccessibleAccountIds`** — resolve matriz/filial/advogado de forma consistente.
3. **`MasterAudit` + triggers de imutabilidade LGPD** — auditoria para mudanças críticas.

**Não vamos criar `monitorings`** (já é `push_monitors`); **não vamos criar `monitoring_logs` genéricos** (existe `master_audit_log` + padrão `ProcessoAudit`). Criamos só o que falta: **3 tabelas novas** + **estender 1 existente** + **2 helpers** (cota + audit).

Estimativa total: **5-8 dias úteis** de dev intermediário seguindo o plano, em 11 etapas.

---

## Sumário do documento (21 itens conforme briefing)

1. [Diagnóstico da estrutura atual](#1-diagnóstico-da-estrutura-atual)
2. [Se já existe módulo de monitoramento/intimações/OAB](#2-se-já-existe-módulo-de-monitoramento)
3. [Tabelas existentes relacionadas](#3-tabelas-existentes-relacionadas)
4. [Arquivos relacionados](#4-arquivos-relacionados)
5. [Fluxo atual de intimações](#5-fluxo-atual-de-intimações)
6. [Proposta de arquitetura do módulo de monitoramentos](#6-proposta-de-arquitetura)
7. [Tabelas novas ou alterações necessárias](#7-tabelas-novas-e-alterações)
8. [Campos necessários](#8-campos-detalhados-por-tabela)
9. [Regras de cota](#9-regras-de-cota)
10. [Regras de permissões](#10-regras-de-permissões)
11. [Fluxos de usuário](#11-fluxos-de-usuário)
12. [Telas necessárias](#12-telas-necessárias)
13. [Riscos técnicos](#13-riscos-técnicos)
14. [Riscos de segurança](#14-riscos-de-segurança)
15. [Riscos multi-tenant](#15-riscos-multi-tenant)
16. [Riscos LGPD](#16-riscos-lgpd)
17. [Ordem segura de implementação](#17-ordem-segura-de-implementação)
18. [Arquivos que serão criados](#18-arquivos-que-serão-criados)
19. [Arquivos que serão alterados](#19-arquivos-que-serão-alterados)
20. [SQL/migrations propostas](#20-sql--migrations-propostas)
21. [Decisões que preciso de você](#21-decisões-que-preciso-de-você-antes-de-implementar)

---

## 1. Diagnóstico da estrutura atual

| Componente | Status | Detalhe |
|---|---|---|
| Tabela `push_monitors` | ✅ Existe (mig 060-063) | NOT NULL `account_id`, `created_by`, `tipo_monitoramento` enum (`oab\|processo\|nome\|customizado`), `status` enum (`ativo\|pausado\|erro\|arquivado`). **Sem UNIQUE** por OAB+UF. |
| Filtragem multi-tenant | ⚠️ Parcial | `PushMonitor::listForAccount()` filtra **só o `account_id` da sessão**. Matriz **não vê** filiais hoje. |
| Quota/limite | ❌ Inexistente | Zero check. `BillingGuard` existe mas **não é chamado** em `monitors.php` POST nem `user_filters.php`. |
| Plan features | ✅ Pattern existe | `max_users/max_processos/max_cards/max_filiais` seedados. **Falta `monitors.limit`**. |
| Master override de quota | ❌ Inexistente | Não há mecanismo pra Master liberar 200 monitors pra cliente VIP sem mexer no plano. |
| Distribuição matriz→filial | ❌ Inexistente | Sem tabela de alocação. Sem UI. Sem regra. |
| Solicitação de monitor | ❌ Inexistente | Sem fluxo "advogado pede aprovação". |
| Auditoria de cota | ⚠️ Parcial | `MasterAudit` existe para Master. Operacional (matriz/filial) não tem log dedicado. |
| Atribuição a usuário/advogado | ⚠️ Parcial | `push_monitors.advogado_id` existe (NULL), `created_by` existe. **Sem `assigned_user_id`**. |

---

## 2. Se já existe módulo de monitoramento

**Sim, plenamente funcional.** Migrations `060_intimacoes_module.sql` até `066_push_processo_links.sql` + AASP em `064-065`. Módulo entrega:

- Página `/intimacoes.php` com 2 abas (DJEN, AASP)
- Modal `monitorsModal` (linhas 1395-1462 de `intimacoes.php`) com 3 seções: meu perfil, monitorar processo, lista ativos/pausados
- Sync via DJEN (público) + AASP (autenticado por chave AES-256-GCM cifrada at-rest)
- Cron `tick.php` a cada 10 min (PushMonitorRunner + AaspSyncRunner)
- Cache 24h em `push_today_cache`
- Persistência em `push_events` quando user interage
- Auto-link de processo via `PushProcessoLink`
- Ações: ler/favoritar/prazo/comentar/vincular processo/criar tarefa

**O que NÃO tem:** controle comercial de "quantos monitors posso criar".

---

## 3. Tabelas existentes relacionadas

| Tabela | Pertinência pra cota |
|---|---|
| `push_monitors` | 🎯 **Central** — vai receber `assigned_user_id`, `consome_cota`, `tipo_origem` |
| `push_events`, `push_today_cache`, `push_query_log`, `push_processo_links` | Operacionais, **sem mudança** |
| `aasp_integrations`, `aasp_credential_audit` | Integração externa, sem mudança |
| `accounts` | Tenant raiz — verifica `tipo` (matriz/filial/advogado) |
| `account_vinculos`, `advogado_vinculos` | Hierarquia matriz↔filial e host↔advogado — **usar como está** via `AccountContext` |
| `users` | `account_id`, `role`, `perfil`, `is_advogado`, `oab` — quem pode fazer o quê |
| `user_permissions` | Permissão por página — adicionar página `monitoramentos` |
| `plans`, `plan_features` | 🎯 **Plug-in da cota** via novo `feature_key='monitors.limit'` |
| `subscriptions` | `accounts.id → subscription.plan_id` resolve qual cota se aplica |
| `master_audit_log` | Auditoria Master de overrides (imutável) |
| `super_admins` | Quem é super_admin |
| `resource_shares` | Compartilhamento avulso — pode reusar pro "matriz libera N monitors pra filial X" |

---

## 4. Arquivos relacionados

### Models
- `app/Models/PushMonitor.php` (já existe — vai mudar)
- `app/Models/PushEvent.php`, `PushTodayCache`, `PushQueryLog`, `PushProcessoLink`, `AaspIntegration` (não mudam)

### Services
- `app/Services/Push/PushMonitorRunner.php` (não muda — só consome monitors ativos)
- `app/Services/Push/AaspSyncRunner.php` (idem)
- `app/Services/Push/DjenProvider.php`, `AaspProvider.php`, `PublicationHasher.php`, `ProviderInterface.php` (não mudam)

### Helpers
- `app/Helpers/BillingGuard.php` (vai precisar de pequeno ajuste pra ler override)
- `app/Helpers/AccountContext.php` (vai ganhar mapping `'monitoramentos'` em `getAccessibleAccountIds`)
- `app/Helpers/MasterAudit.php` (sem mudança — vai ser chamado)

### Endpoints API
- `public/api/push/monitors.php` (POST/PATCH/DELETE vão ganhar quota check)
- `public/api/push/user_filters.php` (PATCH com `auto_monitor:true` vai ganhar quota check)
- `public/api/push/quota.php` ← **NOVO** (GET retorna total/usado/disponível pro UI)
- `public/api/master/quotas.php` ← **NOVO** (Master overrides)
- `public/api/push/allocations.php` ← **NOVO** (matriz distribui)
- `public/api/push/requests.php` ← **NOVO** (advogado solicita)

### Frontend
- `public/intimacoes.php` (modal vai ganhar badge "X de Y monitors usados")
- `public/assets/intimacoes.js` (renderMonitors recebe quota_total/usado)
- `public/master.php` (nova aba "Cotas de Monitoramento")

---

## 5. Fluxo atual de intimações

```
┌─────────────────────────────────────────────────────────────────┐
│ Usuário em /intimacoes.php abre modal "Monitoramento"          │
│   • Salva perfil DJEN (OAB/UF/Nome) → user_filters.php PATCH   │
│     com auto_monitor:true → cria push_monitor automaticamente  │
│   • OU clica "Monitorar processo" → monitors.php POST          │
│                                                                 │
│ Cron tick.php (cada 10min)                                     │
│   • PushMonitor::dueNow() lista todos vencidos cross-tenant    │
│   • Pra cada monitor: chama DjenProvider/AaspProvider          │
│   • Resultado vai pra push_today_cache (24h)                   │
│   • Notifica via AccountNotification                           │
│                                                                 │
│ Usuário abre intimações em /intimacoes.php                     │
│   • Lista feed: push_today_cache + push_events fundidos        │
│   • Interage (ler/favoritar/comentar/criar tarefa)             │
│   • Persiste em push_events via persist.php                    │
└─────────────────────────────────────────────────────────────────┘
```

A camada de cota **se encaixa no momento da criação** do monitor (modal/auto_monitor) e **não toca** o fluxo de execução do cron nem visualização das intimações.

---

## 6. Proposta de arquitetura

### Princípio: 4 layers, cada uma resolvendo 1 problema

```
┌─────────────────────────────────────────────────────────────────┐
│ LAYER 1 — DEFINIÇÃO DE COTA (quanto pode)                      │
│   plan_features.feature_key='monitors.limit'  (limite padrão)  │
│   account_quota_overrides  (override Master pra tenant VIP)    │
│   → BillingGuard::getLimit() resolve qual usar                 │
├─────────────────────────────────────────────────────────────────┤
│ LAYER 2 — DISTRIBUIÇÃO (quem usa o que)                        │
│   monitor_quota_allocations  (matriz aloca pra filial/adv)     │
│   push_monitors.assigned_user_id  (atribuição direta)          │
│   → MonitorQuota::getAccessibleUsage() calcula por escopo      │
├─────────────────────────────────────────────────────────────────┤
│ LAYER 3 — USO (criar/pausar/cancelar)                          │
│   push_monitors (estendida com tipo_origem, consome_cota)      │
│   BillingGuard::assertCanCreate() barra antes de POST          │
│   → status canceled libera cota; paused mantém                 │
├─────────────────────────────────────────────────────────────────┤
│ LAYER 4 — AUDITORIA + SOLICITAÇÕES                             │
│   monitor_audit_log (imutável — trigger BEFORE UPDATE/DELETE)  │
│   monitor_requests (advogado pede, admin aprova)               │
│   → MonitorAudit::log() helper                                 │
└─────────────────────────────────────────────────────────────────┘
```

### Decisões importantes

| Decisão | Escolha | Por quê |
|---|---|---|
| Tabela `monitorings` nova vs estender `push_monitors` | **Estender** | Já tem dados, integrações vivas, models maduros |
| `monitoring_quotas` nova vs `plan_features + override` | **plan_features + `account_quota_overrides`** | Pattern já consagrado, suporta múltiplos add-ons futuros |
| Distribuição: pool aberto ou alocação fixa | **Híbrido**: pool por default, alocação opcional | Mais simples no caso comum; matriz reserva só se quiser |
| Atribuição advogado: nova tabela vs FK em `push_monitors` | **FK** `assigned_user_id INT NULL` | Cardinality 1:1, simples; tabela só pra histórico |
| Log: master_audit_log único ou tabela própria | **Tabela própria** `monitor_audit_log` | `master_audit_log` é só ações Master; operacionais (matriz/filial) merecem tabela dedicada |

---

## 7. Tabelas novas e alterações

### 7.1 NOVA: `account_quota_overrides`
Override de qualquer `plan_features.feature_key` por conta específica, controlado pelo Master.

### 7.2 NOVA: `monitor_quota_allocations`
Alocação opcional matriz→filial/advogado. Se vazia, cota é pool aberto.

### 7.3 NOVA: `monitor_audit_log`
Histórico operacional imutável (criação, ativação, pausa, cancel, alocação, etc.).

### 7.4 NOVA: `monitor_requests`
Solicitações de monitoramento por advogado/filial sem cota direta.

### 7.5 ALTERAR: `push_monitors`
- `+ assigned_user_id INT UNSIGNED NULL` (FK lógica pra users.id — quem é o "responsável" pelo monitor)
- `+ assigned_account_id INT UNSIGNED NULL` (se a alocação foi pra uma filial inteira em vez de user específico)
- `+ consome_cota TINYINT(1) NOT NULL DEFAULT 1` (flag pra monitor "grátis" tipo trial/promo não consumir)
- `+ origem_criacao ENUM('user','admin','master_seed','request_approved','auto_profile') NOT NULL DEFAULT 'user'`
- `+ UNIQUE (account_id, tipo_monitoramento, valor_monitorado, uf, deleted_at)` — evita duplicidade lógica
- `+ index (assigned_user_id)`, `+ index (assigned_account_id)`

### 7.6 `plan_features` — comportamento revisado (modelo comercial)
**Decisão atualizada 2026-05-26:** monitoramento NÃO é incluído em nenhum plano. Default é 0 pra todos os clientes.

**3 opções de implementação:**
- **A (recomendada):** NÃO seedar `plan_features.monitors.limit`. `BillingGuard::getLimit()` retorna `0` (ou NULL → trata como 0) quando feature_key ausente. Default é negativo → cliente precisa de override pra ter qualquer monitor.
- **B:** Seedar `plan_features` com `limit_value=0` em todos os planos (deixa explícito no banco que "esse plano dá 0 monitors"). Mais defensivo, mais auditável.
- **C:** Mesma opção B, mas com `limit_value=1` no plano "Teste Grátis" pra cliente conseguir experimentar 1 monitor durante trial sem precisar de Master.

Sugestão: **B** (zero seedado, sem brinde no trial). Cliente em trial pede ao Master se quiser testar. Mais clean.

---

## 8. Campos detalhados por tabela

### 8.1 `account_quota_overrides`
```sql
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
account_id INT UNSIGNED NOT NULL
feature_key VARCHAR(60) NOT NULL          -- 'monitors.limit' (mas vale pra qualquer feature)
limit_value INT NULL                       -- NULL = ilimitado
source ENUM('master_grant','trial','promo','migration') NOT NULL DEFAULT 'master_grant'
expires_at DATETIME NULL                   -- override temporário (trial)
set_by_super_admin_id INT UNSIGNED NULL
revoked_at DATETIME NULL
revoked_by_super_admin_id INT UNSIGNED NULL
observacoes VARCHAR(500) NULL
created_at DATETIME DEFAULT CURRENT_TIMESTAMP
updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
UNIQUE (account_id, feature_key, source, revoked_at)  -- só 1 ativo por (acc, key, source)
INDEX (account_id, feature_key, revoked_at)
```

### 8.2 `monitor_quota_allocations`
```sql
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
parent_account_id INT UNSIGNED NOT NULL      -- matriz
target_account_id INT UNSIGNED NULL          -- filial OU conta de advogado
target_user_id INT UNSIGNED NULL             -- user específico (advogado da matriz/filial)
allocated INT UNSIGNED NOT NULL              -- quantos monitors reservados
status ENUM('active','revoked') NOT NULL DEFAULT 'active'
created_by INT UNSIGNED NOT NULL             -- admin que alocou
revoked_at DATETIME NULL
revoked_by INT UNSIGNED NULL
observacoes VARCHAR(500) NULL
created_at DATETIME DEFAULT CURRENT_TIMESTAMP
updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
INDEX (parent_account_id, status)
INDEX (target_account_id, status)
INDEX (target_user_id, status)
CHECK (target_account_id IS NOT NULL OR target_user_id IS NOT NULL)
```

### 8.3 `monitor_audit_log`
```sql
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
account_id INT UNSIGNED NOT NULL             -- escopo da ação
monitor_id INT UNSIGNED NULL                 -- se aplicável (NULL pra ações de cota)
acao ENUM(
  'quota_granted','quota_reduced','quota_allocated','quota_revoked',
  'monitor_created','monitor_activated','monitor_paused','monitor_canceled',
  'monitor_updated','monitor_failed',
  'request_created','request_approved','request_denied',
  'permission_changed'
) NOT NULL
descricao VARCHAR(500) NULL
actor_user_id INT UNSIGNED NULL              -- quem executou
actor_role VARCHAR(50) NULL                  -- snapshot
actor_super_admin_id INT UNSIGNED NULL       -- se foi Master
ip VARCHAR(45) NULL
user_agent VARCHAR(255) NULL
dados_antes JSON NULL
dados_depois JSON NULL
request_id VARCHAR(60) NULL                  -- correlação LGPD
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
INDEX (account_id, created_at)
INDEX (monitor_id, created_at)
INDEX (acao, created_at)
```

**Triggers de imutabilidade (LGPD Art.37):** copiar padrão de `master_audit_log`:
```sql
CREATE TRIGGER trg_monitor_audit_no_update BEFORE UPDATE ON monitor_audit_log
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'monitor_audit_log: UPDATE proibido (LGPD)';
CREATE TRIGGER trg_monitor_audit_no_delete BEFORE DELETE ON monitor_audit_log
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'monitor_audit_log: DELETE proibido (LGPD)';
```

### 8.4 `monitor_requests`
```sql
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
account_id INT UNSIGNED NOT NULL             -- conta de quem solicita
requesting_user_id INT UNSIGNED NOT NULL
tipo_monitoramento ENUM('oab','processo','nome','customizado') NOT NULL
valor_monitorado VARCHAR(120) NOT NULL
uf CHAR(2) NULL
nome_complementar VARCHAR(200) NULL
source_id VARCHAR(40) NOT NULL DEFAULT 'djen'
justificativa VARCHAR(500) NULL
status ENUM('pending','approved','denied','canceled') NOT NULL DEFAULT 'pending'
approved_by INT UNSIGNED NULL
approved_at DATETIME NULL
denied_at DATETIME NULL
motivo_recusa VARCHAR(300) NULL
resulting_monitor_id INT UNSIGNED NULL       -- preenche quando aprovado
created_at DATETIME DEFAULT CURRENT_TIMESTAMP
updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
INDEX (account_id, status)
INDEX (requesting_user_id, status)
```

### 8.5 `push_monitors` (ALTER)
```sql
ALTER TABLE push_monitors
  ADD COLUMN assigned_user_id INT UNSIGNED NULL AFTER created_by,
  ADD COLUMN assigned_account_id INT UNSIGNED NULL AFTER assigned_user_id,
  ADD COLUMN consome_cota TINYINT(1) NOT NULL DEFAULT 1 AFTER status,
  ADD COLUMN origem_criacao ENUM('user','admin','master_seed','request_approved','auto_profile')
    NOT NULL DEFAULT 'user' AFTER consome_cota,
  ADD INDEX idx_assigned_user (assigned_user_id),
  ADD INDEX idx_assigned_account (assigned_account_id);

-- UNIQUE soft: mesmo account+tipo+valor+uf não pode existir 2x ativo
CREATE UNIQUE INDEX uniq_monitor_logico
  ON push_monitors (account_id, tipo_monitoramento, valor_monitorado, uf, status);
-- (status no índice permite múltiplas linhas se status='arquivado')
```

---

## 9. Regras de cota

### Cálculo
```
limite_efetivo(account) =
    -- soma de todos overrides ativos (Master grant + purchases ativas)
    -- assim cliente pode ter "grant 2 (promoção)" + "purchase 5 (contratou)" = 7
    SUM(account_quota_overrides.limit_value
        WHERE account_id = X
          AND revoked_at IS NULL
          AND (expires_at IS NULL OR expires_at > NOW()))

    -- fallback se nenhum override existir → 0 (modelo comercial: plano não inclui)
    -- (plan_features.monitors.limit fica seedado como 0 explícito; vide §7.6 opção B)

usado(account) =
    COUNT(push_monitors WHERE account_id IN (escopo)
                          AND status IN ('ativo','pausado','erro')
                          AND consome_cota=1
                          AND deleted_at IS NULL)
    +
    COUNT(monitor_requests WHERE account_id IN (escopo)
                             AND status='pending')   -- se D4=SIM

disponivel(account) = limite_efetivo - usado
```

**Atenção pro modelo comercial:** soma de overrides em vez de MAX. Isso permite:
- Cliente compra 5 (source=purchase) + Master dá 1 promo (source=promo) = 6 disponíveis.
- Quando promo expira, ainda tem 5.
- Quando rotaciona contrato (purchase antigo revogado + purchase novo), os 2 não se acumulam (revoke desativa).

### Status × consumo de cota
| Status | Consome cota? | Justificativa |
|---|---|---|
| `ativo` | ✅ Sim | Em uso |
| `pausado` | ✅ Sim | Vaga reservada (recomendação do briefing) |
| `erro` | ✅ Sim | Continua reservado até admin agir |
| `arquivado` | ❌ Não | Equivalente a canceled |
| `pending_approval` (novo) | ✅ Sim (opcional) | Decisão sua — proposto: SIM |

### Regras hard
1. `BillingGuard::assertCanCreate` é chamado ANTES de cada `PushMonitor::create`.
2. Se `disponivel <= 0` → HTTP 402 Payment Required + mensagem "Limite de monitoramentos atingido".
3. `auto_monitor:true` em `user_filters.php` é tratado como criação normal (passa pelo guard).
4. Cancelar (`status=arquivado`) libera cota imediatamente.
5. Master pode REDUZIR `account_quota_overrides.limit_value` abaixo do uso — sistema **avisa** mas não cancela monitores automaticamente. Próximo create bloqueia.
6. Master pode revogar override (set `revoked_at`) — limite cai para `plan_features` default.

### Alocação opcional matriz→filial
- Se `monitor_quota_allocations` tem rows pra uma filial: filial só pode usar até esse limite, mesmo que matriz tenha pool maior.
- Se tabela tá vazia pra essa filial: pool aberto (filial usa do total do tenant).
- Validação no `BillingGuard` estendido: `limite_filial = min(allocated, limite_tenant)`.

---

## 10. Regras de permissões

### Páginas (tabela `user_permissions`)
Adicionar página `monitoramentos` ao allowlist. `_sidebarCan('monitoramentos')` controla acesso à UI.

### Permissões granulares (novo padrão — opcional)
Hoje sistema é "tudo ou nada por página". Pra cota, talvez precise granularidade. **Decisão sua** (vide §21).

#### Opção A — Granular (recomendado)
Adicionar campo `user_permissions.permission` ENUM(`'view','create','manage','master'`) — só pra página `monitoramentos`. Modelo:

| Role default | Permissão padrão | Override pelo admin? |
|---|---|---|
| super_admin | `master` | Não |
| owner | `manage` | Sim |
| admin (perfil=admin) | `manage` | Sim |
| user (com is_advogado=1) | `view` ou `create` (depende do toggle "advogado pode criar próprio") | Sim |
| user comum | `view` | Sim |
| viewer | `view` | Sim |

#### Opção B — Hardcoded simples
Sem nova permission column. Lógica no código:
- `isSuperAdmin()` → master ops
- `isOwnerOrAdmin()` → cria/gerencia
- `is_advogado=1 + accounts.settings.advogado_pode_criar_monitor` → cria pra si
- demais → só view

Recomendação: **Opção B** pra v1 (mais rápido, sem mudança de UX). **Opção A** pra v2 se cliente pedir.

### Toggle "advogado pode criar próprio"
- Campo novo em `accounts.configuracoes` (JSON existente): `{"advogado_pode_criar_monitor": true/false}` (default `true`)
- Matriz/admin define no Configurações → Tenant
- Se `false`: advogado só pode SOLICITAR via `monitor_requests`

---

## 11. Fluxos de usuário

### Fluxo 1a — Master libera grant (gratuito/promo)
```
super_admin → /master.php aba "Cotas de Monitoramento"
  → seleciona conta X
  → escolhe modalidade "Grant gratuito" → input "Liberar quantos monitors?": 2
  → opcional: "Expira em": 30 dias (trial)
  → opcional: "Observação": "Promoção lançamento Q2-2026"
  → submit → POST /api/master/quotas.php
      {account_id:X, feature_key:'monitors.limit', limit_value:2,
       source:'master_grant', expires_at:'2026-06-25', observacoes:'...'}
  → INSERT account_quota_overrides
  → MasterAudit::log('quota.override.set', 'account', X, ...)
  → MonitorAudit::log('quota_granted', account=X, dados_depois={limit:2, source:'master_grant'})
  → conta X agora tem 2 monitors disponíveis (até 25/06/2026)
```

### Fluxo 1b — Master registra "compra" (preparação pra gateway)
```
super_admin → /master.php aba "Cotas de Monitoramento"
  → seleciona conta X
  → escolhe modalidade "Compra/Contrato" → input "Quantidade contratada": 10
  → opcional: "Preço unitário": R$ 49,90 (só metadado — não cobra agora)
  → opcional: "Ciclo": mensal/anual (metadado)
  → submit → POST /api/master/quotas.php
      {account_id:X, feature_key:'monitors.limit', limit_value:10,
       source:'purchase', observacoes:'Contrato 2026/01 — 10 OABs'}
  → INSERT account_quota_overrides com source='purchase'
  → MasterAudit::log('quota.override.set', source='purchase', ...)
  → MonitorAudit::log('quota_granted', source='purchase', dados_depois={limit:10})
  → conta X agora tem 10 monitors (saldo permanente até revogar)

Quando gateway entrar em produção (futuro):
  → fluxo passa a ser disparado pelo webhook do gateway (ex: invoice.paid)
  → cria account_quota_overrides automaticamente
  → audit log capta source='purchase' + gateway_subscription_id
```

### Fluxo 1c — Cliente sem cota tenta criar (UX explicativa)
```
admin_matriz sem cota → /intimacoes.php → modal "Monitoramento"
  → tenta criar OAB → BillingGuard nega (limite=0)
  → response 402 + mensagem amigável:
    "Você ainda não contratou monitoramentos. Cada OAB monitorada é um
     produto separado do seu plano. Fale com seu gerente comercial para
     contratar ou clique aqui pra solicitar."
  → CTA "Solicitar contratação" abre form que abre ticket via e-mail ao DPO/comercial
```

### Fluxo 2 — Matriz cadastra monitor
```
admin_matriz → /intimacoes.php → modal "Monitoramento" → "Nova OAB"
  → POST /api/push/monitors.php {tipo:'oab', valor:'12345', uf:'SP'}
  → BillingGuard::assertCanCreate(account_id, 'monitors.limit', 'push_monitors')
    → calcula usado=7, limite=10, disponivel=3 → OK
  → valida UNIQUE (account, tipo, valor, uf, status≠arquivado) → OK
  → INSERT push_monitors com origem_criacao='admin', consome_cota=1
  → MonitorAudit::log('monitor_created', account, monitor_id, ...)
  → response {created: true, quota_used: 8, quota_total: 10}
```

### Fluxo 3 — Matriz aloca pra filial
```
admin_matriz → /configuracoes/monitoramentos.php → aba "Distribuir cota"
  → seleciona filial Y → input "Alocar quantos?": 3
  → POST /api/push/allocations.php
      {parent_account_id: matriz, target_account_id: filial_Y, allocated: 3}
  → valida saldo: limite_matriz=10, usado_descendentes=7, livre=3 → OK
  → INSERT monitor_quota_allocations
  → MonitorAudit::log('quota_allocated', ..., dados_depois={target: filial_Y, qtd: 3})
  → filial Y agora vê "3 monitors disponíveis"
```

### Fluxo 4 — Filial cria monitor
```
admin_filial → mesma UI de "novo monitor"
  → POST /api/push/monitors.php
  → BillingGuard checa cota do escopo filial (allocations + uso atual)
    → se filial tem alocação fixa de 3 e usa 3 → 402
    → se filial sem alocação fixa → fallback pra pool do tenant
  → cria, audit log
```

### Fluxo 5 — Advogado solicita
```
advogado sem permissão direta → modal "Solicitar monitoramento"
  → POST /api/push/requests.php
      {tipo:'oab', valor:'67890', uf:'SP', justificativa:'meus processos'}
  → INSERT monitor_requests (status=pending)
  → notifica admin via AccountNotification
  → MonitorAudit::log('request_created', ...)
admin_matriz → /configuracoes/monitoramentos.php aba "Solicitações"
  → aprova → PATCH /api/push/requests.php {id, action: approve}
  → checa cota disponível
  → cria push_monitor com origem_criacao='request_approved'
  → updates monitor_requests.status=approved, resulting_monitor_id
  → notifica solicitante
  → MonitorAudit::log('request_approved', ...)
```

### Fluxo 6 — Cancelamento
```
admin → monitors.php DELETE /id
  → UPDATE push_monitors SET status='arquivado', deleted_at=NOW()
  → MonitorAudit::log('monitor_canceled', ..., dados_antes={status:'ativo'}, dados_depois={status:'arquivado'})
  → cota volta a refletir (saldo +1)
```

---

## 12. Telas necessárias

### 12.1 Painel Master (`master.php`)
Nova aba **Cotas de Monitoramento**:
- Lista tenants (busca por nome/CNPJ)
- Por linha: nome | plano (só info) | grant ativo | purchases ativas | total liberado | usado | disponível | ações [+ liberar grant | + registrar compra | revogar | histórico]
- Modal "Liberar grant gratuito": input qtd + checkbox "expira em X dias" + textarea observação
- Modal "Registrar compra": input qtd + preço unitário (metadado, sem cobrança) + ciclo + nota (ex: nº contrato)
- KPIs topo: total monitors liberados (todos tenants), total usado, % uso, tenants sem cota, MRR estimado de monitoramentos (qtd × preço médio se preenchido), monitors com erro

### 12.2 Matriz/Tenant — `/configuracoes/monitoramentos.php` (NOVO)
Aba dentro de configurações da conta. **3 abas internas**:
1. **Visão geral**: KPIs (liberado/usado/saldo) + lista de monitors do tenant + uso por filial
2. **Distribuir cota**: tabela filiais + input "alocar X" + histórico de alocações
3. **Solicitações**: pendentes com botão aprovar/recusar

Toggle no topo: "Permitir advogados criarem próprio monitor: [sim/não]"

### 12.3 Filial — mesma página, escopo filial
Vê só sua cota alocada + monitors da filial. Sem aba "Distribuir cota".

### 12.4 Advogado — mesma página, simplificada
Vê só seus monitors. Botão "Cadastrar" se `accounts.configuracoes.advogado_pode_criar_monitor=true`. Botão "Solicitar" sempre. Sem alocação.

### 12.5 Modal de monitor existente (`intimacoes.php`)
**Ajuste mínimo, não quebra nada:**
- Adicionar badge no header do modal: `"3 de 10 monitors usados"`
- Se sem cota: botão "Cadastrar OAB" desabilitado + tooltip "Sua conta atingiu o limite. Solicite mais com o admin."

---

## 13. Riscos técnicos

| Risco | Probabilidade | Impacto | Mitigação |
|---|---|---|---|
| `BillingGuard` foi escrito assumindo `count(*)` simples — não cobre escopo matriz/filial | Alto | Médio | Estender `BillingGuard::getCurrentCount` aceitando `$scopeQuery` ou criar `MonitorQuota` helper dedicado |
| UNIQUE em `push_monitors` rompe dados existentes (duplicatas reais) | Médio | Médio | Migration valida + relata dupes ANTES de criar UNIQUE; admin decide manualmente |
| `auto_monitor:true` em `user_filters.php` é chamado em hot path (salvar perfil) — adicionar guard pode falhar UX se sem cota | Médio | Alto (bloqueia user logando) | Auto-monitor deve ser try/catch + msg amigável "cota cheia, monitor não criado" sem quebrar perfil |
| Cron `tick.php` lista monitors cross-tenant — cota não afeta cron, mas se reduzirem cota abaixo do uso e tentar "limpar excedentes", quebra runner | Baixo | Alto | Master apenas reduz limit; nunca cancela monitors automaticamente. Doc explícito |
| 4 tabelas novas + 1 ALTER em prod = downtime se mal feito | Baixo | Médio | Migration aditiva-only (sem DROP), aplicar em horário de baixo uso, rollback documentado |

---

## 14. Riscos de segurança

| Risco | Mitigação |
|---|---|
| User A acessa `/api/master/quotas.php` sem ser super_admin | `AccountContext::fromSession()->assertSuperAdmin()` no topo + IP allowlist (recomendado prod) |
| Filial A vê cota de filial B | Endpoints checam que `target_account_id` pertence ao tenant do caller via `account_vinculos` |
| Advogado cria monitor pra outra OAB que não a dele | Validar `tipo='oab' AND valor=user.oab` se origem='user' (ou liberar admin) |
| CSRF em POST `/api/push/allocations.php` | `TenantGuard::requireSameOriginOrCsrf()` (já existe — adicionado na auditoria de hoje) |
| Race condition: 2 requests simultâneos criam monitor além do limite | `BillingGuard` precisa de lock pessimista ou retry pattern. Sugestão: `SELECT ... FOR UPDATE` na contagem |
| `monitor_audit_log` cresce sem teto | Política LGPD de retenção: anonimizar `dados_antes/depois` após 5 anos (config em `lgpd_retention_policies`) |

---

## 15. Riscos multi-tenant

| Risco | Mitigação |
|---|---|
| `getAccessibleAccountIds('monitoramentos')` retorna filiais sem flag `sync_*` ativa | Adicionar flag `sync_monitoramentos` em `account_vinculos` (mig) OU reusar `sync_enabled` como master switch. **Decisão sua (§21)** |
| Matriz aloca pra filial DE OUTRO tenant (UI confusa) | Backend valida: `target_account_id IN (account_vinculos WHERE matriz_account_id = caller)` |
| Pool aberto + alocação fixa coexistem — saldo da matriz pode ficar negativo se mal calculado | Helper `MonitorQuota::getEffectiveQuota($ctx, $scope)` faz `min(allocated_or_pool, available)` |
| Migration adiciona colunas em prod com locks longos | Usar `ALGORITHM=INPLACE, LOCK=NONE` no ALTER (suportado MariaDB 10.4+) |

---

## 16. Riscos LGPD

| Risco | Mitigação |
|---|---|
| OAB monitorada é dado pessoal de advogado terceiro | Já existe — sistema atual já lida. `monitor_audit_log` precisa entrar em `lgpd_retention_policies` |
| Logs antigos com PII do advogado | `Anonymizer` (já existe, helper) precisa varrer `monitor_audit_log.dados_antes/depois` JSON depois de N anos |
| Master vê cotas de todos os tenants (cross-tenant view) | Já é o caso pra `master_audit_log` — documentado em `RAT_INICIAL.md` |
| `monitor_requests.justificativa` pode conter dados sensíveis | Mesma política — anonymizer cobre |

---

## 17. Ordem segura de implementação

Sequência **não-destrutiva** dividida em 11 etapas. Cada etapa é commitável independente e testável.

| # | Etapa | Esforço | Reversível? |
|---|---|---|---|
| 1 | Migrations: 072_account_quota_overrides, 073_monitor_quota_allocations, 074_monitor_audit_log + triggers, 075_monitor_requests, 076_push_monitors_addon_cols | 3h | Sim (drop colunas, drop tabelas) |
| 2 | Seed `plan_features` com `monitors.limit` (5 rows) + página `monitoramentos` no allowlist | 30min | Sim (DELETE) |
| 3 | Helpers: `MonitorQuota` (cálculo) + `MonitorAudit` (log) | 2h | Sim (deletar arquivos) |
| 4 | Estender `BillingGuard` para ler `account_quota_overrides` + escopo accessible | 2h | Sim (revert código) |
| 5 | Plugar guard em `monitors.php` POST + `user_filters.php` PATCH (try/catch graceful) | 1h | Sim |
| 6 | Endpoint Master: `/api/master/quotas.php` (CRUD overrides) | 3h | Sim |
| 7 | UI Master: nova aba em `master.php` | 4h | Sim |
| 8 | Endpoint distribuição: `/api/push/allocations.php` + UI matriz `/configuracoes/monitoramentos.php` | 5h | Sim |
| 9 | Endpoint solicitações: `/api/push/requests.php` + UI advogado | 4h | Sim |
| 10 | Badge "X de Y" no modal `intimacoes.php` + `quota.php` endpoint | 1h | Sim |
| 11 | Testes manuais multi-tenant + smoke + docs/commit | 3h | — |

**Total estimado: ~28h** (3-4 dias intensivos ou 5-7 dias com revisão).

---

## 18. Arquivos que serão criados

### Migrations
- `database/migrations/072_account_quota_overrides.sql`
- `database/migrations/073_monitor_quota_allocations.sql`
- `database/migrations/074_monitor_audit_log.sql`
- `database/migrations/075_monitor_requests.sql`
- `database/migrations/076_push_monitors_addon_cols.sql`
- `database/migrations/077_seed_plan_features_monitors_limit.sql`

### Models
- `app/Models/AccountQuotaOverride.php`
- `app/Models/MonitorQuotaAllocation.php`
- `app/Models/MonitorRequest.php`

### Helpers
- `app/Helpers/MonitorQuota.php` (cálculo de cota)
- `app/Helpers/MonitorAudit.php` (logger wrapper)

### Endpoints API
- `public/api/push/quota.php` (GET)
- `public/api/push/allocations.php` (CRUD)
- `public/api/push/requests.php` (CRUD)
- `public/api/master/quotas.php` (CRUD overrides)

### Páginas
- `public/configuracoes/monitoramentos.php`

### Docs
- `docs/MONITORAMENTOS_ADDON.md` (manual de uso pós-implementação)

---

## 19. Arquivos que serão alterados

| Arquivo | Mudança |
|---|---|
| `app/Models/PushMonitor.php` | `create()` aceita `assigned_user_id`, `origem_criacao`, `consome_cota` |
| `app/Helpers/BillingGuard.php` | `getLimit()` consulta `account_quota_overrides` antes de `plan_features` |
| `app/Helpers/AccountContext.php` | mapping `'monitoramentos'` → `sync_monitoramentos` (ou `sync_enabled`) |
| `app/Helpers/Anonymizer.php` | cobrir `monitor_audit_log.dados_antes/depois` |
| `public/api/push/monitors.php` | POST: chamar `BillingGuard::assertCanCreate` + `MonitorAudit::log` |
| `public/api/push/user_filters.php` | PATCH: try/catch graceful no auto_monitor |
| `public/intimacoes.php` | modal `monitorsModal` ganha badge de cota |
| `public/assets/intimacoes.js` | `renderMonitors` recebe quota_total/usado |
| `public/master.php` | nova aba "Cotas de Monitoramento" + handlers JS |
| `public/includes/sidebar.php` | item "Monitoramentos" nas configurações |
| `database/schema.sql` | regenerado após migrations (mysqldump padrão) |

---

## 20. SQL / migrations propostas

### Esboço (NÃO RODAR ANTES DE APROVAR)

```sql
-- ====================================================
-- 072_account_quota_overrides.sql
-- ====================================================
CREATE TABLE IF NOT EXISTS account_quota_overrides (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id INT UNSIGNED NOT NULL,
  feature_key VARCHAR(60) NOT NULL,
  limit_value INT NULL,                       -- NULL = ilimitado (raro)
  source ENUM('master_grant','purchase','trial','promo','migration')
    NOT NULL DEFAULT 'master_grant',
  -- Campos preparatórios pra cobrança futura (sem implementar gateway agora):
  unit_price_cents INT NULL,                  -- ex: 4990 = R$ 49,90/mês por monitor
  billing_cycle ENUM('monthly','yearly','one_off') NULL,
  contract_ref VARCHAR(120) NULL,             -- nº contrato/proposta interno
  gateway_subscription_id VARCHAR(120) NULL,  -- preenche quando gateway plugar
  -- /preparatórios
  expires_at DATETIME NULL,
  set_by_super_admin_id INT UNSIGNED NULL,
  revoked_at DATETIME NULL,
  revoked_by_super_admin_id INT UNSIGNED NULL,
  observacoes VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_aqo_acc_key (account_id, feature_key, revoked_at),
  INDEX idx_aqo_source (source, revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 073_monitor_quota_allocations.sql
-- ====================================================
CREATE TABLE IF NOT EXISTS monitor_quota_allocations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_account_id INT UNSIGNED NOT NULL,
  target_account_id INT UNSIGNED NULL,
  target_user_id INT UNSIGNED NULL,
  allocated INT UNSIGNED NOT NULL,
  status ENUM('active','revoked') NOT NULL DEFAULT 'active',
  created_by INT UNSIGNED NOT NULL,
  revoked_at DATETIME NULL,
  revoked_by INT UNSIGNED NULL,
  observacoes VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_mqa_parent (parent_account_id, status),
  INDEX idx_mqa_target_acc (target_account_id, status),
  INDEX idx_mqa_target_user (target_user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 074_monitor_audit_log.sql
-- ====================================================
CREATE TABLE IF NOT EXISTS monitor_audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id INT UNSIGNED NOT NULL,
  monitor_id INT UNSIGNED NULL,
  acao ENUM(
    'quota_granted','quota_reduced','quota_allocated','quota_revoked',
    'monitor_created','monitor_activated','monitor_paused','monitor_canceled',
    'monitor_updated','monitor_failed',
    'request_created','request_approved','request_denied',
    'permission_changed'
  ) NOT NULL,
  descricao VARCHAR(500) NULL,
  actor_user_id INT UNSIGNED NULL,
  actor_role VARCHAR(50) NULL,
  actor_super_admin_id INT UNSIGNED NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  dados_antes JSON NULL,
  dados_depois JSON NULL,
  request_id VARCHAR(60) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_mal_acc_created (account_id, created_at),
  INDEX idx_mal_mon_created (monitor_id, created_at),
  INDEX idx_mal_acao_created (acao, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //
CREATE TRIGGER trg_monitor_audit_no_update BEFORE UPDATE ON monitor_audit_log
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='monitor_audit_log: UPDATE proibido (LGPD Art.37)';
END//
CREATE TRIGGER trg_monitor_audit_no_delete BEFORE DELETE ON monitor_audit_log
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='monitor_audit_log: DELETE proibido (LGPD Art.37)';
END//
DELIMITER ;

-- ====================================================
-- 075_monitor_requests.sql
-- ====================================================
CREATE TABLE IF NOT EXISTS monitor_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id INT UNSIGNED NOT NULL,
  requesting_user_id INT UNSIGNED NOT NULL,
  tipo_monitoramento ENUM('oab','processo','nome','customizado') NOT NULL,
  valor_monitorado VARCHAR(120) NOT NULL,
  uf CHAR(2) NULL,
  nome_complementar VARCHAR(200) NULL,
  source_id VARCHAR(40) NOT NULL DEFAULT 'djen',
  justificativa VARCHAR(500) NULL,
  status ENUM('pending','approved','denied','canceled') NOT NULL DEFAULT 'pending',
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  denied_at DATETIME NULL,
  motivo_recusa VARCHAR(300) NULL,
  resulting_monitor_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_mr_acc_status (account_id, status),
  INDEX idx_mr_user_status (requesting_user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================
-- 076_push_monitors_addon_cols.sql
-- ====================================================
ALTER TABLE push_monitors
  ADD COLUMN assigned_user_id INT UNSIGNED NULL AFTER created_by,
  ADD COLUMN assigned_account_id INT UNSIGNED NULL AFTER assigned_user_id,
  ADD COLUMN consome_cota TINYINT(1) NOT NULL DEFAULT 1 AFTER status,
  ADD COLUMN origem_criacao ENUM('user','admin','master_seed','request_approved','auto_profile')
    NOT NULL DEFAULT 'user' AFTER consome_cota,
  ADD INDEX idx_pm_assigned_user (assigned_user_id),
  ADD INDEX idx_pm_assigned_acc (assigned_account_id);

-- Antes de criar UNIQUE, identificar duplicatas:
-- SELECT account_id, tipo_monitoramento, valor_monitorado, uf, COUNT(*)
--   FROM push_monitors
--   WHERE status<>'arquivado'
--   GROUP BY 1,2,3,4 HAVING COUNT(*)>1;
-- (Aprovar individualmente antes do UNIQUE)

-- ====================================================
-- 077_seed_plan_features_monitors_limit.sql
-- ====================================================
-- DECISÃO COMERCIAL (2026-05-26): plano NÃO inclui monitoramento.
-- Seedamos limit_value=0 explícito em TODOS os planos pra deixar claro no
-- banco que "nenhum plano libera monitor por default" — toda cota efetiva
-- vem de account_quota_overrides (source=purchase OU master_grant).
INSERT INTO plan_features (plan_id, feature_key, limit_value, is_enabled)
SELECT id, 'monitors.limit', 0, 1
FROM plans
WHERE id NOT IN (
  SELECT plan_id FROM plan_features WHERE feature_key='monitors.limit'
);
```

---

## 21. Decisões que preciso de você (antes de implementar)

### D1 — `plan_features.monitors.limit`: seedar ou não? ✅ ATUALIZADA com modelo comercial
**Modelo definido:** plano NÃO inclui monitoramento — cobrado por unidade à parte.

Subdecisão técnica (3 opções, §7.6):
- **A:** Não seedar nada. `BillingGuard` retorna 0 quando feature_key ausente.
- **B (recomendada):** Seedar `limit_value=0` explícito em todos os planos. Mais auditável no banco.
- **C:** Mesma B, mas trial ganha 1 monitor brinde por 14 dias.

Sua escolha: A / **B** / C ?

### D2 — Distribuição matriz↔filial: pool aberto ou alocação fixa?
- **Recomendo:** híbrido. Pool aberto por default. Matriz PODE OPCIONALMENTE reservar X pra cada filial (preenchendo `monitor_quota_allocations`).
- Alternativa: apenas pool aberto (sem `monitor_quota_allocations`).
- Alternativa: apenas alocação fixa (matriz obrigada a distribuir).

### D3 — Permissões: granular ou hardcoded?
- **Recomendo Opção B** (hardcoded simples baseado em role + flag `accounts.configuracoes.advogado_pode_criar_monitor`). Mais rápido, sem migration.
- Opção A: granular via `user_permissions.permission ENUM('view','create','manage','master')`. Mais flexível, mais código.

### D4 — Status `pending_approval` consome cota?
- **Recomendo SIM** — reserva imediata pra evitar race condition (advogado solicita, admin demora dias, durante esse tempo outro consome cota → quando admin aprova, estoura).
- Alternativa: NÃO consome (só ativo/pausado/erro).

### D5 — Sync flag para o módulo
- **Recomendo:** adicionar `sync_monitoramentos` em `account_vinculos` + `advogado_vinculos` (mig 078), seguindo padrão de outros módulos.
- Alternativa: reusar `sync_enabled` (master switch) — mais simples mas menos granular.

### D6 — Master Panel: rota
- **Recomendo:** nova aba dentro de `/master.php` (sem rota nova).
- Alternativa: rota dedicada `/master_cotas.php`.

### D7 — Toggle "advogado pode criar próprio monitor"
- Onde fica? `accounts.configuracoes` JSON existente OU coluna nova `accounts.advogado_pode_criar_monitor TINYINT`?
- **Recomendo:** JSON (zero migration, já existe).

### D8 — Início pela etapa qual?
Opções:
- **Opção α (recomendado):** seguir etapas 1→11 em ordem.
- **Opção β:** começar pelo Master (etapas 1+2+3+6+7), depois o resto.
- **Opção γ:** MVP-only: etapas 1+2+3+4+5 (cota funciona via `BillingGuard` mas sem UI de gestão). Master usa SQL direto pra liberar.

---

### D9 — NOVO — Forma de "compra" antes do gateway entrar
Como o cliente sai de 0 → N monitors **sem gateway**?

- **A (recomendada):** Apenas Master via UI. Equipe comercial recebe contrato/PIX/boleto offline e o super_admin registra `source='purchase'` no Painel Master.
- **B:** Mesma A + endpoint público `/api/checkout/monitors.php` (form pra cliente preencher dados de pagamento manual; admin valida e libera). Mais self-service.
- **C:** Apenas A, mas com botão "Solicitar contratação" no app que dispara e-mail pro comercial. Fluxo do `monitor_requests` ganha um tipo novo `'contratar'` além de `'usar'`.

Sua escolha: **A** / B / C ?

### D10 — NOVO — Campos preparatórios pra gateway: criar agora ou depois?
A migration 072 (`account_quota_overrides`) inclui campos `unit_price_cents`, `billing_cycle`, `contract_ref`, `gateway_subscription_id` mesmo sem gateway pra:
- Permitir Master registrar valor contratado (vira KPI de MRR estimado no Painel Master)
- Plug futuro: webhook do gateway preenche `gateway_subscription_id` automaticamente

- **A (recomendada):** Criar agora. Custo zero (colunas NULL), benefício alto.
- **B:** Deixar pra mais tarde. ALTER TABLE futuro.

Sua escolha: **A** / B ?

## Próximo passo

**Me responde os 10 itens da §21** (mesmo que seja "vai como recomendado em tudo") e eu arranco a implementação a partir da Etapa 1.

**Lembrete dos boundaries que vou respeitar:**
- ❌ Sem commit sem você aprovar
- ❌ Sem push
- ❌ Sem PR
- ❌ Sem migration rodando direto no banco antes de você ver o SQL exato
- ❌ Sem quebrar fluxo atual de intimações
- ❌ Sem implementar pagamento/gateway
- ❌ Sem misturar com `subscriptions` ou alterar plano comercial
- ✅ Cada etapa será 1 commit temático separado
- ✅ Validação `php -l` em cada arquivo modificado
- ✅ Smoke test multi-tenant entre etapas

---

**Documentos de recon que embasaram esta proposta:**
- [`docs/RECON_PUSH_MONITOR_2026-05-26.md`](RECON_PUSH_MONITOR_2026-05-26.md)
- [`docs/RECON_TENANT_PERMS_2026-05-26.md`](RECON_TENANT_PERMS_2026-05-26.md)
