# Recon Tenant/Permissões — Yuris (2026-05-26)

## 1. accounts (estrutura + tipos)
Colunas: `id`, `nome`, `razao_social`, `cnpj`, `email`, `telefone`, `cidade`, `estado`, `tipo`, `matriz_id`, `codigo_vinculo`, `plano`, `status`, `configuracoes` (JSON), `created_at`, `updated_at`, `deleted_at`.
- `tipo` ENUM: `('matriz','filial','advogado')` default `matriz`.
- `status` ENUM: `('active','trial','overdue','suspended','cancelled','inactive')`.
- `matriz_id` é FK pra `accounts.id` (NULL na matriz; preenchido em filiais legadas — em paralelo, matriz "tem" filiais via `account_vinculos` ativo).
- `plano` aqui é STRING legacy (`basico|profissional|enterprise`); o billing real vem via `subscriptions`.

## 2. account_vinculos
`id, matriz_account_id (FK), filial_account_id (FK), status enum(pending|active|suspended|rejected), solicitado_por, aprovado_por, solicitado_em, aprovado_em, suspenso_em, motivo_suspensao, created_at, updated_at, sync_enabled, sync_processos, sync_cards, sync_tarefas, sync_updated_at`. UNIQUE (`matriz_account_id`,`filial_account_id`). Vincula matriz↔filial; sync_* gateia visibilidade por módulo (sync_enabled é toggle mestre).

## 3. advogado_vinculos
`id, host_account_id (matriz OU filial), advogado_account_id, status enum(pending|active|suspended|rejected), solicitado_por, aprovado_por, datas, sync_enabled, sync_processos, sync_cards, sync_tarefas`. Estrutura espelha `account_vinculos`, mas conecta uma host (matriz ou filial) a uma conta `tipo=advogado`. Host vê os dados do advogado quando vínculo está active.

## 4. users (role, perfil)
Colunas chave: `account_id`, `perfil` ENUM(`admin`,`user`) default `user`; `role` VARCHAR(50) default `user`; `codigo_advogado` (ADV-XXXXXX único), `oab`, `oab_uf`, `nome_advogado`, `is_advogado` (tinyint).
`perfil` é o legado binário (admin vs user). `role` é o nível granular usado em hierarquia (`owner > admin > manager > user > viewer`, ver `AccountContext::hasMinRole`). Login: `user_role = user.role ?? (perfil==='admin' ? 'owner' : 'user')`.

## 5. AccountContext (métodos)
Sempre instanciado via `fromSession()` (401 sem sessão). Públicos: `getAccountId`, `getAccountTipo`, `getRole`, `getUserId`, `isMatriz`, `isFilial`, `isOwner`, `isOwnerOrAdmin`, `hasMinRole`, `isSuperAdmin`, `getSuperAdminLevel`, `assertSuperAdmin`, `assertAccountActive` (cache 60s; deslogga sessão se status ∈ suspended/cancelled/inactive; super_admin imune), `getPipelineAccountId`, `isPipelineInherited`, `getAccessibleAccountIds($module)`, `getAccessibleUsers`, `buildAccountInClause`, `buildResourceFilter`, `getResourcePermission`, `assertCanRead/Write`, `assertIsOwnerOfResource`.
`getAccessibleAccountIds($module)`: matriz → próprio + filiais ativas (filtrado por sync_enabled + sync_<flag do módulo>); filial/advogado → próprio; advogados vinculados via `AdvogadoVinculo::advogadosAtivosDeHost`; soma contas que liberaram `resource_shares` com `resource_type='module'` e `module_key=$module`.

## 6. TenantGuard (métodos)
Estáticos: `assertProcessoAcessivel($ctx,$id)`, `assertCardAcessivel`, `assertTaskAcessivel` (via board_id), `requireSameOriginOrCsrf($methods)` (adicionado 2026-05-26 — same-origin OR token CSRF). Uso típico no topo do endpoint de recurso filho: instancia `$ctx`, chama `TenantGuard::assert...($ctx,$id)` → 403 se fora.

## 7. Permissões (hardcoded vs tabela)
Híbrido. (a) Hierarquia `role` é hardcoded em `hasMinRole`. (b) Página por usuário: tabela `user_permissions(user_id, page, account_id)`; `_SESSION['user_permissions']=['*']` pra owner/admin, lista pra demais (lida em `sidebar.php` via `_sidebarCan($page)`). (c) Não existe allowlist tipo `monitoring.view`/`cards.create` — permissões granulares são por página/módulo, e features de plano são `plan_features.feature_key`.

## 8. Master Panel
Entrada: `public/master_login.php` (portal isolado, visual roxo, rate-limit + TOTP) → grava `_SESSION['master_mode']=true`. `public/master.php` redireciona se faltar `master_mode` OU `is_super_admin`. Tabela `super_admins(user_id, nivel ENUM(viewer|operator|super), ativo, mfa_*)`. `MasterAudit::log($acao,$type,$id,$desc,$meta)` grava em `master_audit_log` (imutável via triggers — UPDATE/DELETE proibidos LGPD).

## 9. resource_shares (compartilhamento)
`resource_type ENUM(card,processo,contato,task_board,module)`, `resource_id`, `module_key` (preenchido qd type=module), `from_account_id`, `to_account_id` (NULL=todas vinculadas), `to_user_id` (NULL=conta inteira), `permission_level ENUM(view|edit|full)`, `status (active|revoked)`, `criado_por`, `revoked_*`. Matriz "compartilha" processos com filial via row em `resource_shares` OU via herança automática (`getAccessibleAccountIds` injeta filiais). Module shares = "libera aba X pra essa conta".

## 10. plans + subscriptions
`plans(id, slug, nome, preco_mensal_cents, preco_anual_cents, trial_dias, ativo, destaque, ordem)` — sem coluna `monitors_limit` direta. `plan_features(plan_id, feature_key, limit_value INT NULL, is_enabled)` UNIQUE(plan_id,feature_key) — é o feature-flag/quota por plano. `subscriptions(account_id, plan_id, status ENUM trialing|active|past_due|canceled|unpaid|incomplete, billing_cycle, trial_ends_at, current_period_*, gateway, gateway_subscription_id)`. Conta tem plano via subscription ativa; `accounts.plano` (string) é legado paralelo. `BillingGuard::assertCanCreate($acc,$featureKey,$countTable)` lê plan_features, conta uso e responde 402.

## 11. Recomendações para cota
1. **Feature-flag**: criar `plan_features` rows `monitors.limit` por plano (limit_value=N). Quem aplica/checa: `BillingGuard::assertCanCreate($ctx->getAccountId(),'monitors.limit','push_monitors')` antes de `PushMonitor::create`.
2. **Override por conta (Master)**: nova tabela `account_quota_overrides(account_id, feature_key, limit_value, source ENUM master|trial|promo, expires_at, set_by_super_admin_id)` — BillingGuard lê override antes do plan_features. Operação no Master grava com `MasterAudit::log('quota.override.set',...)`.
3. **Distribuição matriz→escopos**: tabela `monitor_quota_allocations(parent_account_id, target_account_id NULL, target_user_id NULL, target_advogado_account_id NULL, allocated, reserved_at)` — matriz distribui para filiais/advogados/users. Antes do `create`, validar `sum(allocations descendentes) + uso(escopo) <= limite` usando `AccountContext::getAccessibleAccountIds('processos')` pra resolver hierarquia.
4. **Visibilidade**: respeitar `sync_*` flags ao agregar consumo (igual o resto do sistema faz hoje em `getAccessibleAccountIds`).
5. **Auditoria**: cada mudança de cota (Master ou matriz redistribuindo) → `MasterAudit::log` ou novo `ProcessoAudit`-style helper espelhando padrão imutável (trigger BEFORE UPDATE/DELETE).
6. **Endpoints**: aplicar `TenantGuard::requireSameOriginOrCsrf()` + `AccountContext::fromSession()->assertAccountActive()` no topo, com `assertSuperAdmin` em rotas `/api/master/*`.
