# Continuação — Add-on Monitoramentos (pós-compact)

**Data do snapshot:** 2026-05-26
**Status geral:** ~70% completo (5 de 11 etapas + extras)
**HEAD local:** 12 commits ahead de `origin/main` (sem push ainda)
**Last commit:** `c76f9f4` — fix(monitor-addon): filial herda cota da matriz via account_vinculos

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
- `app/Helpers/MonitorQuota.php` — `getOwnLimit`, `getAllocationsReceived`, `hasAllocations`, `getEffectiveLimit`, `getCurrentUsage`, `getAggregatedUsage`, `getAvailable`, `getQuotaStatus`, `assertCanCreate`, `resolveMatrizId` (fix posterior)
- `app/Helpers/MonitorAudit.php` — wrapper de `monitor_audit_log` (allowlist 14 ações, fail-soft, RequestId)

### Etapa 3 — Permissões (commit `4bf87fe`)
- `app/Helpers/MonitorPermission.php`
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

### Etapa 8 — Distribuição matriz→filial (UI)
Backend e tabela existem (`monitor_quota_allocations`), mas falta:
- Endpoint `public/api/push/allocations.php` (POST cria, DELETE revoga, GET lista)
- Página `/configuracoes/monitoramentos.php` (matriz/filial)
- Aba **Distribuir cota** com:
  - KPIs: total contratado / total alocado / livre no pool
  - Tabela de filiais com input "alocar X" + botão "Salvar"
  - Histórico de alocações
- Tela do admin_filial: vê só cota alocada (se houver) ou pool aberto

### Etapa 9 — Solicitações `monitor_requests` (UI)
Tabela existe (mig 075), mas falta:
- Endpoint `public/api/push/requests.php` (POST cria, PATCH aprova/recusa, GET lista)
- Botão **"Solicitar monitor"** no modal `intimacoes.php` quando advogado sem permissão direta
- Aba "Solicitações" no `/configuracoes/monitoramentos.php` (admin aprova/recusa)
- Notificação via `AccountNotification` quando solicitação criada/aprovada/recusada
- Cota é reservada na hora da solicitação (D4) — já considerada em `getCurrentUsage`

### Etapa 10 — Integração com intimações
- `PushEvent::upsert` já tem campo `monitor_id` mas `persist.php` não propaga. Adicionar.
- Quando intimação aparece em `push_today_cache` → `push_events`, preencher `monitor_id` do origem.
- Útil pra rastrear ROI: "monitor X gerou N intimações no mês"

### Etapa 11 — Testes + polish final + push
- Smoke test multi-tenant completo: Silvana matriz, Filial SP, Gabriel solo, todos com diferentes cotas
- Validar que tenant A não vê monitors de tenant B
- Validar `pending_approval` decrementando saldo
- Validar webhooks operacionais (se forem implementados):
  - `monitor.created`, `monitor.canceled`, `monitor.quota_granted`, etc.
- Smoke test e2e
- **Push pro origin/main** (12+ commits)

### Extras opcionais (não-bloqueantes)
- KPI no dashboard Master: "MRR de monitoramentos" (soma de unit_price_cents × qtd em overrides purchase ativos com billing_cycle)
- Migration pós-cleanup: adicionar UNIQUE em `push_monitors (account_id, tipo_monitoramento, valor_monitorado, uf, status)` após validar zero duplicatas
- Cron `lgpd_retention_tick` cobrir `monitor_audit_log` (anonimizar `dados_antes/depois` após 5 anos)
- Aggregated usage na matriz: master.php hoje mostra usage só da matriz, deveria somar usage de filiais com `sync_monitoramentos=1`
- Webhook events do módulo (eventos `monitor.*` no catálogo)

---

## Estado dos arquivos no projeto

### Criados pelo add-on
- `app/Helpers/MonitorQuota.php`
- `app/Helpers/MonitorAudit.php`
- `app/Helpers/MonitorPermission.php`
- `public/api/master/quotas.php`
- `public/api/push/quota.php`
- `database/migrations/072_*.sql` a `079_*.sql` (8 migrations)
- `docs/PROPOSTA_MONITORAMENTOS_ADDON.md`
- `docs/RECON_PUSH_MONITOR_2026-05-26.md`
- `docs/RECON_TENANT_PERMS_2026-05-26.md`

### Modificados
- `app/Helpers/BillingGuard.php`
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
require_once __DIR__ . '/app/Models/Database.php';
require_once __DIR__ . '/app/Helpers/BillingGuard.php';
require_once __DIR__ . '/app/Helpers/MonitorQuota.php';
use App\Helpers\MonitorQuota;
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
