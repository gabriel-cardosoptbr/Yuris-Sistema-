# Auditoria de Banco — Yuris (2026-05-26)

> Auditor: Claude (somente leitura). Escopo: 70 migrations + `schema.sql` + ~95 endpoints + docs.
> Constraints respeitadas: nenhum `mysql` executado, nenhum SQL modificado, nenhum arquivo criado fora deste.

## 1. Resumo Executivo

O `schema.sql` está **gravemente desatualizado** — declara estado pós-migration 027 (2026-05-11), mas existem **70 migrations**, das quais ~43 (028→070) introduzem tabelas/colunas/triggers/renomeações que **não estão refletidas no schema monolítico**. Em particular, migration `067_rename_webhooks_to_endpoints` renomeia `webhooks` → `webhook_endpoints` e o código PHP em produção já usa o nome novo — qualquer deploy zero baseado em `schema.sql` quebra o módulo de Webhooks. Também identificados: charset/collation incompleto no schema, 2 migrations com mesmo número (`067` duplicado), e `scripts/seed_admin.php` insuficiente para multi-tenant bootstrap (não popula `account_id`, omite `role` e `senha_texto`).

**Achados:** 🔴 4 | 🟠 6 | 🟡 5 | 🟢 3 | 🔵 4 (22 totais).

## 2. Estado do schema.sql vs migrations

**Cabeçalho do `schema.sql` (linhas 1–3):** “Gerado após aplicação de todas as migrations (001–027). Última atualização: 2026-05-11.” — explicitamente parado em 027. As próximas 43 migrations (028→070) **não foram replicadas** no monolítico.

**Tabelas em migrations e ausentes em `schema.sql` (33+):**

🔴 `webhook_endpoints` (067 renomeia `webhooks`), `webhook_events` (068), `webhook_deliveries` (069), `webhook_event_queue` (070) — quebram módulo de webhooks pós-deploy do schema sozinho.
🟠 `teams`, `team_members` (028); `taxes` (031); `processo_history`, `processo_prazos`, `processo_tarefas` (005 — sequer estão no monolítico atual); `login_attempts` (037); `plans`, `plan_features`, `subscriptions`, `payment_methods`, `invoices`, `usage_counters`, `super_admins`, `gateway_events_received`, `master_audit_log`, `emails_outbox` (038); `master_expenses` (045); `agent_configs` (048); `legal_documents`, `term_acceptances`, `lgpd_consents` (049); `lgpd_requests`, `lgpd_request_events` (050); `retention_policies`, `anonymization_log` (051); `security_incidents`, `security_incident_events` (054); `data_processors`, `data_processor_history` (055); `pending_reviews` (056); `lgpd_request_modules/findings/attachments/retention_justifications` (057); `whatsapp_group_members`, `whatsapp_reactions` (059); `push_today_cache`, `push_events`, `push_event_user_status`, `push_monitors`, `push_query_logs` (060); `aasp_integrations`, `aasp_credential_audit` (064); `push_processo_links` (066); `advogado_vinculos` (067-duplo); `whatsapp_settings`, `whatsapp_contacts`, `whatsapp_chat_processos` (006/007 — também ausentes apesar de serem anteriores a 027).

**Tabelas em `schema.sql` mas com schema obsoleto vs migrations:**
🟠 `cards`, `processos`, `users`, `dre_accounts`, `task_checklist_items`, `webhooks` (já renomeada) — divergências de colunas já catalogadas em `database/RELATORIO_DIVERGENCIAS.md` (migrations 017→023). Migrations 024 (`account_id`+`role` em users), 027 (multi-tenancy cols), 044, 062, 063 adicionam colunas não presentes no schema.

## 3. Tabelas/colunas faltando no schema

- 🔴 **Colunas adicionadas via ALTER nas migrations 027–065 ausentes em `schema.sql`**: `users.account_id` declarado mas sem FK; `users.codigo_advogado` (032), `users.tipo_advogado` (044), `users.nome_advogado` (062), `users.mfa_enabled/mfa_secret_enc` (047), `tasks.push_event_id` (061), `processos.account_seq` (036), `accounts.cnpj/razao_social/dados_completos` (041), `push_monitors.nome_complementar` (063), `processo_history.author_account_id` (040), etc.
- 🟠 **Colunas LGPD (052)** `user_agent`, `request_id`, `ip` em `master_audit_log`, `account_audit_log`, `processo_history`, `card_history`, `task_history` — todas ALTER, não refletidas.
- 🟡 **Amostra colunas suspeitas em PHP** (sampling de `master/lgpd_anonymize.php`, `master/finance.php`, `master/billing.php`, `webhooks.php`): `subscriptions.status`, `invoices.due_at`, `master_expenses.competencia`, `super_admins.totp_secret_enc` — todas existem em migrations 038/045/047, **nenhuma** em schema.

## 4. Inconsistências de charset/collation

🟠 **Schema.sql declara `CHARSET=utf8mb4` em todas as 39 tabelas mas NUNCA declara `COLLATE utf8mb4_unicode_ci` em nível de tabela** — só no `CREATE DATABASE`. Em MariaDB 10.4, se o servidor padrão for `utf8mb4_general_ci` (frequente em Ubuntu), as tabelas herdarão o collation errado. Migrations corretas (013, 014, 015, 035, 049, 050, 051, 057, etc.) **declaram explicitamente `utf8mb4_unicode_ci`**. Inconsistência potencial entre tabelas existentes (sem collation) e novas (com collation). Migration 042 já corrigiu encoding corrompido em `plans` (sinaliza histórico de problema). Recomendo regenerar o schema com `mysqldump --default-character-set=utf8mb4`.

## 5. Multi-tenancy: tabelas sem account_id

Conforme `docs/SCHEMA_AUDIT.md` (2026-05-21), 17 tabelas com `account_id` direto e 30 herdam via FK — **status OK**, validado por `scripts/test_multitenancy_e2e.php` (12/12). 🟢 Sem novos achados. 🔵 Observação: schema.sql atual mostra `users.account_id` como `DEFAULT NULL` sem FK — migration 016 torna `NOT NULL` + FK, mas o schema declarativo ainda permite usuário órfão. Migrar `schema.sql` deve fechar isso.

## 6. Triggers LGPD: status

Checklist (`docs/CHECKLIST_DEPLOY_PRODUCAO.md` linha 38–39) exige **20 triggers em 9 tabelas** (`trg_%_no_%`). Contabilizei nas migrations: **20 triggers em 10 tabelas**:
- 053 → 16 triggers em 8 tabelas (`master_audit_log`, `account_audit_log`, `processo_history`, `card_history`, `task_history`, `anonymization_log`, `lgpd_request_events`, `term_acceptances`).
- 054 → 2 triggers em `security_incident_events`.
- 055 → 2 triggers em `data_processor_history`.
- 057 → 8 triggers em 4 tabelas LGPD (parciais update + no_delete) — **estes contam à parte**, não estão na lista do checklist.

🟡 Discrepância de contagem: checklist diz “9 tabelas” mas migrations cobrem 10. Migrations 057 acrescentam 8 triggers de imutabilidade parcial extras. Recomendo atualizar `CHECKLIST_DEPLOY_PRODUCAO.md` para “28 triggers em 14 tabelas” se a lista correta for soma.

## 7. Seeds necessários para bootstrap

`scripts/seed_admin.php` (atual):
- INSERT em `users` apenas com `nome, login, senha_hash, perfil, status` — **NÃO popula** `account_id` (NOT NULL pós-016), `role` (24), `senha_texto` (22).
- **Resultado**: vai falhar com `SQLSTATE 23000 Column 'account_id' cannot be null` em produção, OU criar usuário órfão se a constraint estiver fraca.

🔴 **`scripts/seed_admin.php` insuficiente para deploy zero**. Mínimo necessário para subir um master admin novo:
1. Aplicar `schema.sql` regenerado (ou `database/migrations/035_servidor_full_multitenant.sql` conforme `SCHEMA_AUDIT.md` recomenda) + todas as migrations 036→070 em ordem.
2. INSERT em `accounts` (account_id=1, tipo=`matriz`, plano=enterprise/teste).
3. INSERT em `users` com `account_id=1`, `role='owner'`, `senha_hash`, `senha_texto`, `mfa_enabled=0`.
4. INSERT em `super_admins` para acesso ao painel `/master.php` (com `totp_secret_enc` cifrado).
5. Seed mínimo de `plans`/`plan_features` (existe em 038 + 043).
6. Rodar `database/seed_webhook_events.php` (popula catálogo de eventos).
7. INSERT em `legal_documents` versão 1 (privacidade+termos) — exigido para checkbox de aceite no login (`CHECKLIST_DEPLOY_PRODUCAO.md` E.).

## 8. Bloqueadores críticos (🔴 deploy não sobe)

1. 🔴 `schema.sql` desatualizado em ~43 migrations — qualquer `mysql < schema.sql` em servidor virgem cria banco incompleto, sem tabelas SAAS/LGPD/intimações.
2. 🔴 Migrations duplicadas no número `067` (`067_rename_webhooks_to_endpoints.sql` e `067_advogado_vinculos.sql`) — ordem de aplicação ambígua, qualquer ferramenta de migration tracker quebra; renomear uma para `067a`/`067b`.
3. 🔴 `scripts/seed_admin.php` quebra em produção pós-migration 016/024 (não fornece `account_id`/`role`).
4. 🔴 `webhook_endpoints` (módulo de Webhooks) referenciado em PHP mas inexistente no `schema.sql` (renomeação só está na migration 067).

## 9. Plano de correção ordenado

1. (🔴) **Regenerar `schema.sql`** via `mysqldump -u root sistema_vendas --no-data --routines --triggers --default-character-set=utf8mb4` de um ambiente que já rodou todas as 70 migrations. Marcar como ground truth e atualizar cabeçalho.
2. (🔴) **Resolver número duplicado 067**: renomear `067_advogado_vinculos.sql` → `071_advogado_vinculos.sql` (preservar ordem cronológica) **OU** `067b_advogado_vinculos.sql`. Verificar se algum script de aplicação assume nomes únicos.
3. (🔴) **Reescrever `scripts/seed_admin.php`** com: `account_id=1`, `role='owner'`, `senha_texto`, hash forte, criação opcional de `super_admin`, opção `--account-id=N` para multi-tenant.
4. (🟠) Adicionar `COLLATE utf8mb4_unicode_ci` explícito em todas as tabelas do schema regenerado.
5. (🟠) Criar `scripts/deploy_bootstrap.php` consolidando: aplicar schema → aplicar migrations 028→070 em ordem → seed_admin → seed_webhook_events → seed legal_documents v1 → criar super_admin.
6. (🟡) Atualizar `docs/CHECKLIST_DEPLOY_PRODUCAO.md` para 28 triggers em 14 tabelas (incluir 057).
7. (🟡) Criar tabela `schema_migrations(version, applied_at)` para rastrear o que foi aplicado (todas as migrations são idempotentes mas sem ledger não há auditoria de “o que rodou”).
8. (🟢) Adicionar `database/migrations/000_baseline.sql` apontando para o schema regenerado, para deploys novos pularem 001–070.
9. (🔵) Considerar remover `senha_texto` em produção (recomendação de segurança LGPD); manter só em dev.
10. (🔵) `seed_demo.sql` usa `TRUNCATE` em todas as tabelas — perigoso se rodado por engano em produção; adicionar guarda por env var (`require APP_ENV === 'demo'`).

---

**Referências**: `database/schema.sql` (1–609), `database/migrations/001`→`070`, `database/RELATORIO_DIVERGENCIAS.md`, `docs/SCHEMA_AUDIT.md`, `docs/CHECKLIST_DEPLOY_PRODUCAO.md`, `scripts/seed_admin.php`.
