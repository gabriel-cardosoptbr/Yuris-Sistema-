# Relatório de Verificação Pós-Migração
**Data:** 2026-05-11  
**Banco:** sistema_vendas @ 127.0.0.1 (MariaDB via XAMPP)

---

## 1. Status das Migrations 017–023

| # | Arquivo | Coluna/Tabela | Status | Evidência |
|---|---------|---------------|--------|-----------|
| 017 | 017_add_cpf_cnpj_processos.sql | `processos.cpf_cnpj_parte_contraria` | ✅ Aplicada | Coluna presente: `varchar(20) DEFAULT NULL` |
| 018 | 018_add_numero_cnj_processos.sql | `processos.numero_cnj` | ✅ Aplicada | Coluna presente: `varchar(30) DEFAULT NULL` |
| 019 | 019_add_titulo_cards.sql | `cards.titulo` | ✅ Aplicada | Coluna presente: `varchar(255) DEFAULT NULL` |
| 020 | 020_add_dre_campos.sql | `dre_accounts.recorrencia`, `data_referencia`, `descricao` | ✅ Aplicada | Todas as 3 colunas presentes na tabela |
| 021 | 021_add_deleted_at_webhooks.sql | `webhooks.deleted_at` | ✅ Aplicada | Coluna presente: `datetime DEFAULT NULL` |
| 022 | 022_add_senha_texto_users.sql | `users.senha_texto` | ✅ Aplicada | Coluna presente: `varchar(255) DEFAULT NULL` |
| 023 | 023_add_prazo_checklist.sql | `task_checklist_items.prazo` | ✅ Aplicada | Coluna presente: `date DEFAULT NULL` |

**Resultado: Migrations 017–023 estão 100% aplicadas.**

---

## 2. Status das Migrations Antigas com Erro (014, 016)

### Migration 014 — `create_accounts.sql`

| Tabela | Status | Observação |
|--------|--------|------------|
| `accounts` | ✅ Existe | 1 registro: "Escritório Principal" (tipo=matriz, status=active) |
| `account_vinculos` | ✅ Existe | Estrutura correta com FKs para accounts |
| `resource_shares` | ✅ Existe | Estrutura correta |
| `advogado_convites` | ✅ Existe | Estrutura correta, UNIQUE em token_convite |
| `account_notifications` | ✅ Existe | Estrutura correta |
| `account_audit_log` | ❌ **AUSENTE** | Tabela não existe no banco |

### Migration 016 — `add_account_id_resources.sql`

A migration 016 deveria adicionar `account_id` às tabelas de recursos. **Nenhuma dessas colunas foi criada:**

| Tabela | Coluna `account_id` esperada | Status |
|--------|------------------------------|--------|
| `users` | `account_id INT DEFAULT NULL` | ❌ **AUSENTE** |
| `cards` | `account_id INT DEFAULT NULL` | ❌ **AUSENTE** |
| `processos` | `account_id INT DEFAULT NULL` | ❌ **AUSENTE** |
| `contatos` | `account_id INT DEFAULT NULL` | ❌ **AUSENTE** |
| `pipeline_columns` | `account_id INT DEFAULT NULL` | ❌ **AUSENTE** |
| `task_boards` | `account_id INT DEFAULT NULL` | ❌ **AUSENTE** |
| `goals` | `account_id INT DEFAULT NULL` | ❌ **AUSENTE** |
| `whatsapp_instances` | `account_id INT DEFAULT NULL` | ❌ **AUSENTE** |

Adicionalmente, `users.role` também está ausente (esperado pela migration 016 / AuthController):

| Tabela | Coluna `role` esperada | Status |
|--------|------------------------|--------|
| `users` | `role varchar(30) DEFAULT 'user'` | ❌ **AUSENTE** |

**Conclusão: A migration 016 NÃO foi aplicada. A migration 014 foi aplicada parcialmente (falta account_audit_log).**

---

## 3. Divergências Remanescentes

### 3.1 Tabelas ausentes no banco

| Tabela | Referenciada em | Impacto |
|--------|-----------------|---------|
| `account_audit_log` | `CLAUDE.md` Fase 1, Fase 5 (checklist de segurança), migration 015 | Operações críticas não são auditadas — risco de segurança |
| `dre_lancamentos` | Referenciada pelo padrão DRE do sistema | Módulo DRE incompleto |
| `chat_interno_messages` | Nome antigo — substituído por `chat_mensagens` | Sem impacto (renomeada corretamente) |

> **Nota:** O sistema possui `chat_conversas`, `chat_mensagens`, `chat_participantes`, `chat_mencoes` como substitutos da tabela `chat_interno_messages`. Não há impacto funcional real aqui.

### 3.2 Colunas ausentes por tabela

#### Tabela `users` — **CRÍTICO**
| Coluna | Tipo esperado | Status | Impacto |
|--------|---------------|--------|---------|
| `account_id` | `INT DEFAULT NULL` | ❌ Ausente | AuthController tenta fazer JOIN com `accounts` via `users.account_id` — JOIN falha silenciosamente (try/catch), resultando em `account_id = user_id` (fallback single-tenant) |
| `role` | `varchar(30) DEFAULT 'user'` | ❌ Ausente | `$_SESSION['user_role']` lê `$user['role']` que será `NULL` — fallback usa `perfil`. O código em `users.php` testa `SELECT role FROM users` com try/catch, então não quebra, mas `role` nunca é persistido |

#### Tabela `cards` — **CRÍTICO**
| Coluna | Tipo esperado | Status | Impacto |
|--------|---------------|--------|---------|
| `account_id` | `INT DEFAULT NULL` | ❌ Ausente | `Card::list()` detecta ausência via try/catch e usa fallback "sem filtro de tenant" — todos os cards de todas as contas são retornados juntos. `Card::create()` lança `InvalidArgumentException` se `account_id` vier vazio, mas como o fallback de sessão retorna `user_id` como `account_id`, o INSERT tentará gravar uma coluna que não existe → **erro fatal em produção** |

#### Tabela `processos` — **CRÍTICO**
| Coluna | Tipo esperado | Status | Impacto |
|--------|---------------|--------|---------|
| `account_id` | `INT DEFAULT NULL` | ❌ Ausente | Mesmo problema que `cards`: INSERT em `Processo::create()` inclui `account_id` explicitamente na query → **PDOException em toda criação de processo** |

#### Tabela `contatos`
| Coluna | Tipo esperado | Status | Impacto |
|--------|---------------|--------|---------|
| `account_id` | `INT DEFAULT NULL` | ❌ Ausente | Fase 6 (pendente) — sem impacto imediato (endpoints de contatos não foram atualizados ainda) |

#### Tabela `pipeline_columns`
| Coluna | Tipo esperado | Status | Impacto |
|--------|---------------|--------|---------|
| `account_id` | `INT DEFAULT NULL` | ❌ Ausente | Fase 6 (pendente) — sem impacto imediato |

#### Tabela `task_boards`
| Coluna | Tipo esperado | Status | Impacto |
|--------|---------------|--------|---------|
| `account_id` | `INT DEFAULT NULL` | ❌ Ausente | Fase 6 (pendente) — código de `task_boards.php` não usa `AccountContext`, então sem impacto imediato |

#### Tabela `goals`
| Coluna | Tipo esperado | Status | Impacto |
|--------|---------------|--------|---------|
| `account_id` | `INT DEFAULT NULL` | ❌ Ausente | Fase 6 (pendente) — sem impacto imediato |

#### Tabela `whatsapp_instances`
| Coluna | Tipo esperado | Status | Impacto |
|--------|---------------|--------|---------|
| `account_id` | `INT DEFAULT NULL` | ❌ Ausente | Fase 6 (pendente) — sem impacto imediato |

### 3.3 Problemas de tipo

| Tabela | Coluna | Tipo no banco | Tipo esperado pelo código | Status |
|--------|--------|---------------|--------------------------|--------|
| `tasks` | `prazo_tipo` | `enum('legal','interno','administrativo')` | Task.php insere `'interno'` como default — compatível | ✅ OK |
| `users` | `perfil` | `enum('admin','user')` | AuthController e users.php usam 'admin'/'user' | ✅ OK |

### 3.4 Impactos de runtime imediatos

| Cenário | Resultado atual | Causa raiz |
|---------|----------------|------------|
| Login de qualquer usuário | Funciona (fallback single-tenant) | `users.account_id` ausente: try/catch em AuthController retorna `account_id = user_id` |
| Listar cards (`GET /api/cards.php`) | Retorna TODOS os cards sem filtro de tenant | `cards.account_id` ausente: fallback em `Card::list()` remove o WHERE tenant |
| Criar card (`POST /api/cards.php`) | **QUEBRA com PDOException** | `cards.account_id` ausente: INSERT inclui coluna inexistente |
| Criar processo (`POST /api/processes.php`) | **QUEBRA com PDOException** | `processos.account_id` ausente: INSERT inclui coluna inexistente |
| `GET /api/users.php` | Retorna todos os usuários sem isolamento | `users.account_id` ausente: WHERE account_id desativado |
| Dashboard, métricas jurídicas | Funciona (não usa account_id ainda) | Fase 6 pendente — sem filtro de tenant |

---

## 4. Recomendação Final

**O banco NÃO está sincronizado com o código.** São necessárias as seguintes migrations corretivas:

### Migration 024 — Aplicar migration 016 que não foi executada

```sql
-- 024_apply_missing_016.sql
-- Adiciona account_id e role nas tabelas de recursos (migration 016 pendente)

ALTER TABLE users
  ADD COLUMN account_id INT DEFAULT NULL AFTER perfil,
  ADD COLUMN role VARCHAR(30) NOT NULL DEFAULT 'user' AFTER account_id,
  ADD KEY idx_users_account_id (account_id);

-- Atualiza todos os usuários existentes para a conta padrão (id=1)
UPDATE users SET account_id = 1 WHERE account_id IS NULL;
-- Define role baseado no perfil existente
UPDATE users SET role = 'owner' WHERE perfil = 'admin' AND role = 'user';

ALTER TABLE cards
  ADD COLUMN account_id INT DEFAULT NULL AFTER id,
  ADD KEY idx_cards_account_id (account_id);
UPDATE cards SET account_id = 1 WHERE account_id IS NULL;

ALTER TABLE processos
  ADD COLUMN account_id INT DEFAULT NULL AFTER id,
  ADD KEY idx_processos_account_id (account_id);
UPDATE processos SET account_id = 1 WHERE account_id IS NULL;

ALTER TABLE contatos
  ADD COLUMN account_id INT DEFAULT NULL AFTER id,
  ADD KEY idx_contatos_account_id (account_id);
UPDATE contatos SET account_id = 1 WHERE account_id IS NULL;

ALTER TABLE pipeline_columns
  ADD COLUMN account_id INT DEFAULT NULL AFTER id,
  ADD KEY idx_pipeline_columns_account_id (account_id);
UPDATE pipeline_columns SET account_id = 1 WHERE account_id IS NULL;

ALTER TABLE task_boards
  ADD COLUMN account_id INT DEFAULT NULL AFTER owner_id,
  ADD KEY idx_task_boards_account_id (account_id);
UPDATE task_boards SET account_id = 1 WHERE account_id IS NULL;

ALTER TABLE goals
  ADD COLUMN account_id INT DEFAULT NULL AFTER user_id,
  ADD KEY idx_goals_account_id (account_id);
UPDATE goals SET account_id = 1 WHERE account_id IS NULL;

ALTER TABLE whatsapp_instances
  ADD COLUMN account_id INT DEFAULT NULL AFTER id,
  ADD KEY idx_whatsapp_instances_account_id (account_id);
UPDATE whatsapp_instances SET account_id = 1 WHERE account_id IS NULL;
```

### Migration 025 — Criar tabela account_audit_log (faltou na migration 015)

```sql
-- 025_create_account_audit_log.sql
CREATE TABLE IF NOT EXISTS account_audit_log (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id    INT NOT NULL,
  user_id       INT DEFAULT NULL,
  acao          VARCHAR(100) NOT NULL,
  entidade_tipo VARCHAR(50)  DEFAULT NULL,
  entidade_id   INT          DEFAULT NULL,
  payload_antes JSON         DEFAULT NULL,
  payload_depois JSON        DEFAULT NULL,
  ip            VARCHAR(45)  DEFAULT NULL,
  created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_aal_account  (account_id),
  KEY idx_aal_user     (user_id),
  KEY idx_aal_acao     (acao),
  KEY idx_aal_created  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Log de auditoria imutável — apenas INSERT, nunca UPDATE/DELETE';
```

### Prioridade de execução

| Prioridade | Migration | Motivo |
|------------|-----------|--------|
| 🔴 URGENTE | 024 | Criação de cards e processos está QUEBRADA em produção |
| 🟡 ALTA | 025 | Auditoria de segurança inoperante |

### Checklist de segurança (FASE 5) — Status atual

| Item | Status | Observação |
|------|--------|------------|
| `account_id` nunca aceito do body em `/api/cards.php`, `/api/processes.php`, `/api/users.php` | ✅ | Sempre lido da sessão via `AccountContext` |
| `AccountContext::fromSession()` chamado em todos os endpoints autenticados | ✅ Parcial | Falta em `tasks.php`, `task_boards.php`, `dashboard.php`, `juridico_metrics.php` |
| `Card::list()` sem `account_id` retorna array vazio | ✅ | Implementado — mas `account_id` nunca chega pois coluna não existe no banco |
| `Processo::list()` sem `account_id` retorna array vazio | ✅ | Idem |
| Token de convite usa `bin2hex(random_bytes(32))` | ✅ | Confirmado em `advogado_convites` (UNIQUE KEY em `token_convite`) |
| Aceite de convite valida `status='pending'` AND `expires_at > NOW()` | ✅ | Estrutura preparada no banco |
| Aprovação de vínculo valida que aprovador é da Matriz | ⚠️ | Requer verificação no código de `account_vinculos.php` |
| `resource_shares` valida vínculo ativo antes de criar | ⚠️ | Requer verificação no código de `resource_shares.php` |
| `account_audit_log` populado em operações críticas | ❌ | Tabela inexistente |
| `session_regenerate_id(true)` no login | ✅ | Confirmado em `AuthController.php` linha 88 |
