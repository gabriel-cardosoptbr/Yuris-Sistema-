# Recon Push/Monitoramento — Yuris (2026-05-26)

## 1. Tabelas (schema)

Migrations relevantes: `060_intimacoes_module.sql`, `061_tasks_push_event_link.sql`,
`063_push_monitors_nome_complementar.sql`, `064_aasp_integration.sql`,
`065_aasp_data_processor.sql`, `066_push_processo_links.sql`.

**`push_monitors`** (a tabela central) — colunas:
- `id` INT UNSIGNED PK auto
- `account_id` INT UNSIGNED **NOT NULL** (sem FK explícita)
- `advogado_id` INT UNSIGNED NULL · `processo_id` INT UNSIGNED NULL · `created_by` INT UNSIGNED NULL
- `source_id` VARCHAR(40) NOT NULL DEFAULT `'djen'`
- `tipo_monitoramento` ENUM `oab|processo|nome|customizado` NOT NULL DEFAULT `oab`
- `valor_monitorado` VARCHAR(120) NOT NULL
- `nome_complementar` VARCHAR(200) NULL (mig 063 — AND filter)
- `uf` CHAR(2) NULL · `tribunal` VARCHAR(20) NULL
- `status` ENUM `ativo|pausado|erro|arquivado` NOT NULL DEFAULT `ativo`
- `prioridade` TINYINT UNSIGNED NOT NULL DEFAULT 5
- `intervalo_minutos` INT UNSIGNED NOT NULL DEFAULT 120
- `ultima_consulta_em`, `proxima_consulta_em` DATETIME NULL
- `ultimo_hash_resultado` VARCHAR(64) · `ultimo_erro` VARCHAR(255) · `erros_consecutivos` INT
- `created_at`, `updated_at` DATETIME
- **Sem UNIQUE constraint** (mesmo tenant pode criar OAB duplicada)
- Índices: `(account_id, status)`, `(status, proxima_consulta_em)`, `(advogado_id)`, `(processo_id)`, `(valor_monitorado)`

Demais tabelas: `push_today_cache` (cache 24h, UNIQUE `account_id+hash_conteudo`); `push_events` (persistente, UNIQUE `account_id+hash_conteudo`, FK em `tasks.push_event_id`); `push_event_user_status` (UNIQUE `event_id+user_id`, FK CASCADE); `push_query_logs`; `push_processo_links` (UNIQUE `account_id+numero_processo`); `aasp_integrations` (UNIQUE `account_id+nome`, chave AES-256-GCM); `aasp_credential_audit`.

## 2. Models PHP

- `PushMonitor` — `create()`, `setNomeComplementar()`, `listForAccount(accountId)`, `dueNow(limit)` (cross-tenant, só cron), `markSuccess()`, `markError()` (backoff 2^N até 24h, status='erro' após 5 falhas), `delete(id,acc)`
- `PushEvent` — `upsert()` (com auto-link via `PushProcessoLink`), `listForAccount()`, `findByIdForAccount()`
- `PushEventUserStatus` — `setLida/toggleFavorita/setPrazo/setComentario`
- `PushTodayCache`, `PushQueryLog`, `PushProcessoLink`, `AaspIntegration` (encrypt/rotate/audit)

## 3. Services (`app/Services/Push/`)

- `ProviderInterface` — contrato comum (`fetchPublications(filters,opts)`)
- `DjenProvider` — chama `comunicaapi.pje.jus.br/api/v1/comunicacao`
- `AaspProvider` — chama `intimacaoapi.aasp.org.br/api/(Associado|Empresa)`
- `PublicationHasher` — SHA-256 conteúdo + hash de filtros
- `PushMonitorRunner` — varre `PushMonitor::dueNow()`, popula `push_today_cache`, notifica via `AccountNotification`
- `AaspSyncRunner` — analogo p/ AASP, decifra chave just-in-time, marca `markSyncSuccess/Error`

## 4. Endpoints API (`public/api/push/`)

- `monitors.php` — GET lista (próprio `account_id` só) · POST cria · PATCH (status/intervalo/prioridade/tribunal) · DELETE
- `search.php` — POST busca real-time DJEN (modo `cache_hoje` ou `manual`)
- `list.php` — GET feed persistente + cache_hoje fundidos
- `persist.php` — POST upsert + ações: `read|unread|favorite|deadline|comment|link_process|unlink_process|create_task|create_prazo`
- `tick.php` — GET cron (token=CRON_TOKEN ou CLI), roda PushMonitorRunner + AaspSyncRunner
- `user_filters.php` — GET/PATCH perfil DJEN do user, cria monitores automáticos (combina OAB+nome)
- `users.php` — GET select de responsável
- `search_processos.php` — GET autocomplete p/ vincular intimação a processo
- **Não existem** `link_processo.php` nem `criar_tarefa.php` separados (essas ações vivem dentro de `persist.php`)

## 5. Frontend (UI)

- `public/intimacoes.php` — abas "DJEN" e "AASP"; botão `Monitoramento` no header abre `monitorsModal` (linhas 1395–1462)
- Modal tem 3 seções: **Meu perfil de busca** (UF/OAB/Nome — submit em `myProfSave` → POST `/user_filters.php` com `auto_monitor:true`); **Monitorar processo específico** (`monNewSubmit` → POST `/monitors.php`); **Ativos/Pausados** (lista renderizada via `loadMonitors()`)
- `public/assets/intimacoes.js` — `openMonitors/createMonitor/renderMonitors/handleMonitorAction(pause|resume|delete)`

## 6. Limite/quota atual

**NÃO existe limite específico para monitoramentos.** A UI valida apenas `intervalo_minutos` (30–1440) e `prioridade` (1–10). `BillingGuard` (`app/Helpers/BillingGuard.php`) JÁ existe e é genérico (lê `subscriptions.plan_id` → `plan_features.feature_key` → `usage_counters` com SAFE-FAIL), mas **não há `feature_key='max_monitors'` seedado** em nenhum plano (migrations 038, 043). Atualmente seedado por plano: `max_users`, `max_processos`, `max_cards`, `max_filiais`, `whatsapp_enabled`, `chat_interno`, `webhooks`, `integracoes_api`. `monitors.php` POST **não chama** `BillingGuard::assertCanCreate()`.

## 7. Multi-tenant (`account_id` em `push_monitors`)

- `account_id` **SIM**, NOT NULL · `created_by` **SIM**, NULL (vira NULL se user deletado)
- `user_id` **NÃO** (não há ownership direto por user — só `created_by`)
- `monitors.php` GET usa `PushMonitor::listForAccount($accountId)` — filtra **somente o account_id da sessão** (não usa `getAccessibleAccountIds()` p/ matriz ver filiais)
- Cron `tick.php` chama `dueNow()` cross-tenant, mas todo INSERT/UPDATE respeita o `account_id` do próprio monitor

## 8. Integração com intimações

- `push_events.monitor_id` FK lógica (não enforced) para `push_monitors.id`
- Quando intimação chega via runner, o item **vai pra cache** (`push_today_cache`), não pra `push_events` — só persiste quando user interage (`persist.php`). Hoje **o `monitor_id` não é propagado** do cache pro event no upsert (campo `mon` em PushEvent::upsert aceita, mas `persist.php` não passa)
- Auto-link processo→event acontece via `PushProcessoLink::findProcessoId()` dentro de `PushEvent::upsert()`

## 9. UX atual (modal criar monitor)

- Modal único `monitorsModal` (intimacoes.php:1395). Sem segregação por filial/advogado. Sem dropdown de "atribuir a advogado X". Sem coluna "criado por" visível na lista (`renderMonitors`). Sem filtro por filial. Sem badge de cota usada/total.

## 10. Docs existentes

- `docs/INTEGRACAO_AASP.md` (cabeçalho): documenta integração AASP comercial+técnica, regras LGPD, modos associado/empresa, chave AES-256-GCM, sync a cada 2h
- Nenhum doc dedicado a "push monitor architecture" ou cotas

## 11. Gaps pra cota: o que falta

1. **Nenhuma coluna de cota em `plans`/`plan_features`** — precisa seed `max_monitors` (basico=N, profissional=M, enterprise=NULL)
2. **`monitors.php` POST não chama BillingGuard** — adicionar `BillingGuard::assertCanCreate($accountId, 'max_monitors', 'push_monitors')` no início do `case 'POST'`
3. **Falta agregação matriz→filiais** — `PushMonitor::listForAccount` filtra um único `account_id`; matriz não vê total consumido por filiais. Cota distribuída por filial precisa **nova tabela** (ex: `account_monitor_quotas` com `parent_account_id`, `child_account_id`, `quota_alocada`)
4. **Sem coluna `assigned_to_user_id`** — atribuição a advogado específico hoje só por `advogado_id` (NULL) ou `created_by`. Distribuição "matriz aloca N monitores ao advogado X" precisa coluna nova ou tabela `monitor_assignments`
5. **`user_filters.php` cria monitor automático sem checar cota** — também precisa guard
6. **Sem endpoint p/ Painel Master** ajustar cota por tenant (`accounts.max_monitors_override` ou via `plan_features` override)
7. **UI do modal não mostra "Você usou X de Y monitores"** — `renderMonitors` precisa receber `quota_total` da API
8. **Sem hook de pré-aviso** quando filial atinge 80% da cota alocada
