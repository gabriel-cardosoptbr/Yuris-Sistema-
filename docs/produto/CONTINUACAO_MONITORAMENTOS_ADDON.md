# Continuação — Add-on Monitoramentos (pós-compact)

**Data do snapshot:** 2026-05-26 (Etapas 8-11 concluídas neste turno)
**Status geral:** ~95% completo (10 de 11 etapas — falta só push)
**HEAD local:** 16 commits ahead de `origin/main` (sem push ainda)
**Last commit:** `61ed4a6` — feat(monitor-addon): Etapa 10 — propagar monitor_id push_today_cache→push_events

---

## Como retomar após `/compact`

Diga ao Claude algo como:

> "Estou continuando o módulo de Monitoramentos como add-on no Yuris.
> Lê o doc `docs/CONTINUACAO_MONITORAMENTOS_ADDON.md` e vamos pra próxima
> etapa pendente."

O doc abaixo tem TODO o contexto necessário.

---

## Modelo comercial aprovado (D1–D10)

> **Plano do Yuris = só acesso ao sistema.**
> **Monitoramento = add-on cobrado por unidade (por OAB/advogado monitorado).**
> Default em qualquer plano = **0 monitors**.
> Master libera via grant gratuito OU registra compra (sem gateway nesta etapa).
> Distribuição matriz↔filial = híbrida (pool aberto por default + alocação opcional).
> Permissões hardcoded + flag `accounts.configuracoes.advogado_pode_criar_monitor`.
> `pending_approval` consome cota (D4 — evita race).
> Ciclos suportados: mensal, **trimestral**, anual (mig 079 adicionou quarterly).
> Tela "Assinaturas" do Master mostra Plano + Monitor agrupados por conta.
> Campos prep gateway preenchidos no schema (unit_price_cents etc.) mas SEM cobrança automática.

---

## ✅ Etapas concluídas (1-7 + Etapa 5 + fix)

### Etapa 1 — Migrations 072-078 (commit `c278ce1`/`66dcece`)
Aplicadas em `sistema_vendas` (XAMPP local). Backup migrations:
- `072_account_quota_overrides.sql` — tabela de overrides genéricos (vale pra qualquer feature, não só monitor)
- `073_monitor_quota_allocations.sql` — tabela opcional de alocação matriz→filial/advogado
- `074_monitor_audit_log.sql` + **2 triggers** BEFORE UPDATE/DELETE (LGPD Art.37)
- `075_monitor_requests.sql` — fluxo "advogado solicita, admin aprova"
- `076_push_monitors_addon_cols.sql` — ALTER push_monitors: `assigned_user_id`, `assigned_account_id`, `consome_cota`, `origem_criacao`, +4 índices
- `077_seed_plan_features_monitors_limit.sql` — limit_value=0 explícito em todos os planos
- `078_sync_monitoramentos_flag.sql` — flag em `account_vinculos` + `advogado_vinculos`
- `079_billing_cycle_quarterly.sql` — adiciona `quarterly` em ambos enums (subscriptions + overrides)

### Etapa 2 — Helpers (commit `7e38240`)
- `app/Processos/MonitorQuota.php` — `getOwnLimit`, `getAllocationsReceived`, `hasAllocations`, `getEffectiveLimit`, `getCurrentUsage`, `getAggregatedUsage`, `getAvailable`, `getQuotaStatus`, `assertCanCreate`, `resolveMatrizId` (fix posterior)
- `app/Processos/MonitorAudit.php` — wrapper de `monitor_audit_log` (allowlist 14 ações, fail-soft, RequestId)

### Etapa 3 — Permissões (commit `4bf87fe`)
- `app/Processos/MonitorPermission.php`
- Métodos `canCreate`, `canManageQuotaAllocations`, `canApproveRequest`, `canMasterManage`, `canRequestMonitor`
- `isAdvogadoAllowedToCreate` + `setAdvogadoAllowedToCreate` (lê/escreve em `accounts.configuracoes` JSON)
- Asserts equivalentes (lançam 403)

### Etapa 4 — `BillingGuard` estendido (commit `b12c855`)
- `BillingGuard::getLimit` agora soma `plan_features.limit_value + SUM(account_quota_overrides ativos)`
- Novos métodos `getBaseLimit` (só plano), `getOverrideSum` (só overrides)
- Compat retroativa: outros módulos (`max_users`, `max_processos`, etc.) não afetados quando não há overrides ativos
- `MonitorQuota::getOwnLimit` agora delega pra `BillingGuard`

### Etapa 5 — Guard no fluxo cliente + badge UI (commit `8643461`)
- `public/api/push/quota.php` (novo) — endpoint pro cliente buscar status
- `monitors.php` POST chama `MonitorQuota::assertCanCreate` (402 com mensagem amigável)
- `user_filters.php` PATCH: **graceful** — salva perfil mas NÃO cria monitor se cota cheia, retorna warning
- Modal `intimacoes.php`: badge "X contratado(s) / Y em uso / Z disponível" no topo
- Badge fica vermelho + desabilita botões "Salvar perfil" e "Adicionar processo" quando sem cota
- Refresh do badge após cada create/delete/pause

### Etapa 6 — Endpoint Master `/api/master/quotas.php` (commit `363776d`)
- GET retorna status + overrides ativos + revogados
- POST cria override OU `set_total` (modo inline edit — calcula delta, cria grant ou revoga overrides FIFO com split)
- DELETE revoga (soft, revoked_at)
- Audit dual: `MasterAudit` + `MonitorAudit`

### Etapa 7 — UI Master (commits `363776d`, `08cc11a`, `45b0898`, `f77999c`, `9628083`, `15c4c08`)
- Coluna **MONITORS** na lista "Todas as Contas" com cor verde/laranja/vermelho
- Modal **Detalhes** ganhou seção "MONITORAMENTOS" com 3 KPIs + tabela de overrides
- Modal **Editar Conta** ganhou seção com input inline "Contratados [Salvar]" + botão "+Registrar compra/contrato"
- Tela **Assinaturas** mostra Plano + Monitor **agrupados por conta** (1 row por conta, chips empilhados [Plano] [Monitor], items multi-linha)
- Botão **+Nova assinatura de monitor** no header da aba Assinaturas (atalho — abre modal pra criar compra direto)
- Trimestral disponível em 4 selects de ciclo
- Botões Cancelar inline alinhados na coluna Ações

### Bug fix pós-Etapa 7 (commit `c76f9f4`)
- `MonitorQuota::getEffectiveLimit` agora resolve matriz da filial via `account_vinculos` (não `accounts.matriz_id` que é legado/NULL)
- Respeita `sync_enabled=1` AND `sync_monitoramentos=1`
- Filial SP agora herda corretamente a cota da Silvana

---

## ⏳ Etapas pendentes

### ✅ Etapa 8 — Distribuição matriz→filial (commit `13bfd55`)
- `public/api/push/allocations.php` — GET/POST/PATCH/DELETE com validação de pool
- `public/api/push/permissions.php` — toggle flag advogado_pode_criar_monitor
- `public/configuracoes/monitoramentos.php` — 3 abas (Geral / Distribuir cota / Histórico)
- Link no rodapé da sidebar + botão "Gerenciar cota →" no badge do intimacoes.php

### ✅ Etapa 9 — Solicitações `monitor_requests` (commit `f1c3291`)
- `public/api/push/requests.php` — GET/POST/PATCH com scope=mine|pending|all
- Aprovação cria push_monitor em transação + revalida cota (race-safe)
- Aba "Solicitações" em monitoramentos.php com badge de pending
- Botão "Solicitar ao admin" no intimacoes.php (só se !canCreate)
- Notificações via account_notifications (admins on POST, solicitante on resolve)
- `MonitorPermission::assertCanRequestMonitor` adicionado

### ✅ Etapa 10 — Integração com intimações (commit `61ed4a6`)
- Migration 080 — ALTER push_today_cache ADD monitor_id + índice (idempotente)
- `PushTodayCache::upsert` aceita monitor_id no array
- `PushMonitorRunner` passa monitor_id no upsert (origem automática)
- `persist.php` lê monitor_id do cache e propaga pro `PushEvent::upsert`
- Smoke: upsert/select com monitor_id=999 valida coluna + índice

### ✅ Etapa 11 — Testes + polish + (push aguardando aprovação)
Smoke test multi-tenant E2E (este turno):
- Silvana #1 (matriz): limit=1 used=1 avail=0 (cota cheia) ✓
- Filial SP #9 (filial): limit=1 used=0 avail=1 (herda pool da matriz) ✓
- Gabriel #72 (advogado solo): limit=0 avail=0 (sem add-on contratado) ✓
- Isolamento cross-tenant: cada conta só vê seus push_monitors ✓
- D4 (pending reserva cota): INSERT pending → usage +1 ✓
- Allocation matriz→filial: limit muda de pool (1) pra allocated (1) com
  has_allocations=Y ✓
- Vínculo matriz=1↔filial=9 ativo com sync_monitoramentos=1 ✓

**Push pro origin/main:** aguardando aprovação do user (16 commits ahead).

### Extras opcionais (não-bloqueantes)
- KPI no dashboard Master: "MRR de monitoramentos" (soma de unit_price_cents × qtd em overrides purchase ativos com billing_cycle)
- Migration pós-cleanup: adicionar UNIQUE em `push_monitors (account_id, tipo_monitoramento, valor_monitorado, uf, status)` após validar zero duplicatas
- Cron `lgpd_retention_tick` cobrir `monitor_audit_log` (anonimizar `dados_antes/depois` após 5 anos)
- Aggregated usage na matriz: master.php hoje mostra usage só da matriz, deveria somar usage de filiais com `sync_monitoramentos=1`
- Webhook events do módulo (eventos `monitor.*` no catálogo)

---

## Estado dos arquivos no projeto

### Criados pelo add-on
- `app/Processos/MonitorQuota.php`
- `app/Processos/MonitorAudit.php`
- `app/Processos/MonitorPermission.php`
- `public/api/master/quotas.php`
- `public/api/push/quota.php`
- `database/migrations/072_*.sql` a `079_*.sql` (8 migrations)
- `docs/PROPOSTA_MONITORAMENTOS_ADDON.md`
- `docs/RECON_PUSH_MONITOR_2026-05-26.md`
- `docs/RECON_TENANT_PERMS_2026-05-26.md`

### Modificados
- `app/Billing/BillingGuard.php`
- `public/master.php` (várias seções)
- `public/api/master/accounts.php`
- `public/api/master/billing.php`
- `public/api/push/monitors.php`
- `public/api/push/user_filters.php`
- `public/intimacoes.php`
- `public/assets/intimacoes.js`

---

## Estado do banco local (smoke)

```
Silvana (matriz #1):     1 contratado, 1 em uso, 0 disponível
Filial SP (filial #9):   1 contratado (herdado), 0 em uso, 1 disponível
Gabriel (advogado #72):  0 contratado, 0 em uso, 0 disponível
```

Overrides ativos:
- id=8: account=1, value=1, source=purchase

Vínculos:
- Matriz #1 ↔ Filial #9 (account_vinculos, status=active, sync_monitoramentos=1)

---

## Comandos úteis pra retomar

```bash
# Verificar HEAD e working tree
git -C C:/xampp/htdocs/sistema_vendas log --oneline -15
git -C C:/xampp/htdocs/sistema_vendas status

# Smoke test quota (Silvana, Filial SP, Gabriel)
cd C:/xampp/htdocs/sistema_vendas && C:/xampp/php/php.exe -r "
require_once __DIR__ . '/app/Core/Database.php';
require_once __DIR__ . '/app/Billing/BillingGuard.php';
require_once __DIR__ . '/app/Processos/MonitorQuota.php';
use App\Processos\MonitorQuota;
foreach ([1,9,72] as \$aid) {
    \$s = MonitorQuota::getQuotaStatus(\$aid);
    printf('#%d limit=%d used=%d avail=%d' . PHP_EOL, \$aid, \$s['effective_limit'], \$s['current_usage'], \$s['available']);
}"

# Aplicar migration nova (se criar 080+)
C:/xampp/mysql/bin/mysql.exe -u root sistema_vendas < database/migrations/080_*.sql
```

---

## Próximo passo recomendado

**Começar pela Etapa 8** (distribuição matriz→filial) porque é a feature operacional mais óbvia que ainda falta pro modelo híbrido funcionar end-to-end na UI. Depois Etapa 9 (solicitações), depois 10+11.

OU se preferir, **pular pra Etapa 11** (testes + push) já — o que tem hoje já é entregável funcional. Etapas 8-10 viram backlog.
