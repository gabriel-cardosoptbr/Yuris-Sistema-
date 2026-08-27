# Relatório de Divergências — Schema vs. Código PHP

**Data:** 2026-05-11  
**Metodologia:** Leitura completa de todos os arquivos de migration (001–016), schema.sql e
todos os arquivos em `app/Models/*.php`, `public/api/*.php`, `app/Controllers/AuthController.php`
e `app/Services/WebhookDispatcher.php`.

---

## 1. Resumo Executivo

| Categoria | Quantidade |
|-----------|-----------|
| Colunas ausentes no schema (causam erro SQL em runtime) | **7** |
| Tabelas ausentes no schema | **0** |
| Outros problemas (tipos, inconsistências de nome) | **1** |
| Migrations criadas para corrigir | **7** (017 a 023) |

Todas as divergências são **colunas ausentes** — o código PHP as referencia diretamente
em INSERT, UPDATE ou SELECT, mas as migrations declarativas nunca as criaram.
Em alguns casos o código possui workarounds em runtime (`ALTER TABLE` dentro de `try/catch`),
o que mascara o problema mas gera race conditions e dificulta manutenção.

---

## 2. Tabelas Ausentes

**Nenhuma.** Todas as tabelas referenciadas pelo código existem nas migrations:

`users`, `cards`, `processos`, `contatos`, `pipeline_columns`, `goals`, `webhooks`,
`webhook_logs`, `user_permissions`, `dre_accounts`, `dre_codes`, `whatsapp_instances`,
`whatsapp_chats`, `whatsapp_messages`, `whatsapp_contacts`, `whatsapp_settings`,
`whatsapp_chat_processos`, `chat_conversas`, `chat_participantes`, `chat_mensagens`,
`chat_mencoes`, `contato_vinculos`, `accounts`, `account_vinculos`, `resource_shares`,
`advogado_convites`, `account_notifications`, `account_audit_log`, `task_boards`,
`task_board_members`, `task_columns`, `tasks`, `task_links`, `task_checklist_items`,
`task_comments`, `task_history`, `task_attachments`, `task_time_entries`,
`task_recurrences`, `task_reminders`, `card_history`, `card_checklist_items`,
`processo_history`, `processo_prazos`, `processo_tarefas`.

---

## 3. Colunas Ausentes por Tabela

### 3.1 `processos` — coluna `cpf_cnpj_parte_contraria`

**Arquivo afetado:** `app/Models/Processo.php` (linhas 96–133)  
**Severidade:** CRÍTICA — causa SQL error em todo `POST /api/processes.php` e `PUT /api/processes.php`

O método `Processo::create()` lista `cpf_cnpj_parte_contraria` explicitamente no INSERT:
```sql
INSERT INTO processos (account_id, numero, cliente_nome, parte_contraria,
  cpf_cnpj_parte_contraria, tipo_acao, vara_comarca, ...)
```
O método `Processo::update()` inclui `'cpf_cnpj_parte_contraria'` no array `$allowed`.
A migration `004_create_processes.sql` não contém esta coluna.  
A UI (`public/processos.php`) exibe campo `cpf_cnpj_parte_contraria` no formulário.

**Correção:** `017_add_cpf_cnpj_processos.sql`

---

### 3.2 `processos` — coluna `numero_cnj`

**Arquivo afetado:** `app/Models/TaskLink.php` (linha 23)  
**Severidade:** ALTA — causa SQL error ao resolver o nome de processos vinculados a tarefas

```php
'processo' => ['processos', 'numero_cnj'],
```
O método `resolveNome()` faz `SELECT numero_cnj FROM processos WHERE id = ?`.
O schema cria apenas a coluna `numero` (genérica), não `numero_cnj`.

**Correção:** `018_add_numero_cnj_processos.sql`

---

### 3.3 `cards` — coluna `titulo`

**Arquivo afetado:** `app/Models/TaskLink.php` (linha 24)  
**Severidade:** ALTA — causa SQL error ao resolver o nome de cards vinculados a tarefas

```php
'card' => ['cards', 'titulo'],
```
O método `resolveNome()` faz `SELECT titulo FROM cards WHERE id = ?`.
O schema de cards não possui coluna `titulo` — o campo equivalente é `cliente_nome`.

**Correção:** `019_add_titulo_cards.sql` (adiciona coluna e a popula com `cliente_nome`)

---

### 3.4 `dre_accounts` — colunas `recorrencia`, `data_referencia`, `descricao`

**Arquivo afetado:** `app/Models/DREAccount.php` (linhas 28–55)  
**Severidade:** CRÍTICA — causa SQL error em todo POST/PUT do módulo financeiro (DRE)

O modelo `DREAccount::create()` e `DREAccount::update()` fazem INSERT/UPDATE com
`recorrencia` e `data_referencia`:
```sql
INSERT INTO dre_accounts (codigo,nome,tipo,valor_fixo,recorrencia,data_referencia,ativo)
UPDATE dre_accounts SET codigo=:codigo, nome=:nome, ... recorrencia=:recorrencia,
  data_referencia=:data_referencia, ...
```
A migration `002_create_dre_accounts.sql` não inclui estas colunas.

Adicionalmente, `TaskLink.php` (linha 25) tenta `SELECT descricao FROM dre_accounts`
para resolver o nome de um lançamento DRE vinculado a uma tarefa. A tabela não tem
coluna `descricao`.

**Correção:** `020_add_dre_accounts_extra_cols.sql`

---

### 3.5 `webhooks` — coluna `deleted_at`

**Arquivo afetado:** `app/Services/WebhookDispatcher.php` (linha 181), `public/api/webhooks.php`  
**Severidade:** ALTA — webhooks nunca são realmente excluídos; o sistema continua disparando webhooks deletados

`WebhookDispatcher::fire()` filtra `WHERE ativo = 1 AND deleted_at IS NULL`.  
`webhooks.php` soft-deleta via `UPDATE webhooks SET deleted_at = NOW()`.  
A listagem filtra `WHERE w.deleted_at IS NULL`.  
A `schema.sql` não inclui `deleted_at` em webhooks.

O código possui workaround em runtime dentro de `try/catch` (linha 57–62 do webhooks.php),
mas isso mascara o problema e não é confiável em todos os cenários de deploy.

**Correção:** `021_add_deleted_at_webhooks.sql`

---

### 3.6 `users` — coluna `senha_texto`

**Arquivo afetado:** `public/api/users.php` (linhas 34, 108–111, 141, 200, 215)  
**Severidade:** MÉDIA — o workaround já existe, mas a coluna deve ser declarativa

O endpoint `users.php` verifica a existência de `senha_texto` com `SELECT` e a adiciona
via `ALTER TABLE` em runtime se não existir. É usada para armazenar e retornar a senha
em texto plano na UI de administração de usuários.

A `schema.sql` e nenhuma migration incluem esta coluna formalmente.

**Nota de segurança:** Armazenar senhas em texto plano é prática insegura. Manter
somente se for requisito explícito de negócio, com banco devidamente protegido.

**Correção:** `022_add_senha_texto_users.sql`

---

### 3.7 `task_checklist_items` — coluna `prazo`

**Arquivo afetado:** `app/Models/TaskChecklist.php` (linha 14–43), `public/api/task_checklist.php`  
**Severidade:** MÉDIA — workaround já existe; coluna deve ser declarativa

O modelo `TaskChecklist::create()` aceita e insere o campo `prazo` (DATE).
O endpoint `task_checklist.php` passa `$input['prazo']` ao criar um item.
A migration `013_create_tasks.sql` não inclui `prazo` em `task_checklist_items`.

O código já possui um workaround via `INFORMATION_SCHEMA` + `ALTER TABLE` dinâmico,
mas isso é frágil e não aparece no schema declarativo.

**Correção:** `023_add_prazo_task_checklist_items.sql`

---

## 4. Outros Problemas

### 4.1 `tasks.prazo_tipo` — ENUM vs. uso pelo código (sem divergência real)

A migration `013_create_tasks.sql` declara:
```sql
prazo_tipo ENUM('legal','interno','administrativo') DEFAULT 'interno'
```
O código PHP (`Task::create()`, `tasks.php`) insere valores `'legal'`, `'interno'`,
`'administrativo'` — todos dentro do ENUM. Sem divergência real.

O CLAUDE.md mencionava "prazo_tipo como VARCHAR vs ENUM em tasks" como possível
problema, mas a inspeção mostra que o schema usa ENUM com os valores corretos
e o código os respeita. Não é necessária migration.

### 4.2 `TaskLink.php` — mapa de resolução de nomes usa colunas incorretas

O arquivo `app/Models/TaskLink.php` usa este mapa para resolver nomes em vínculos de tarefas:
```php
'processo'    => ['processos', 'numero_cnj'],   // ← coluna não existia (corrigido por 018)
'card'        => ['cards',     'titulo'],        // ← coluna não existia (corrigido por 019)
'dre_account' => ['dre_accounts', 'descricao'], // ← coluna não existia (corrigido por 020)
```
Todos os três problemas são resolvidos pelas migrations 018, 019 e 020 respectivamente.

### 4.3 `dre_accounts` — coluna `nome` usada como label mas `descricao` é o campo de TaskLink

A migration 020 adiciona `descricao` como TEXT separado. Para vínculos existentes onde
apenas `nome` está preenchido, o TaskLink retornará `NULL` e exibirá `#ID`. Recomenda-se
popular `descricao` com `nome` após aplicar a migration (incluído na migration 020).

### 4.4 Endpoints ainda sem filtro de `account_id` (risco de vazamento de dados entre tenants)

Conforme documentado no CLAUDE.md (FASE 6), os seguintes endpoints ainda não possuem
filtro por `account_id`, expondo dados de todos os tenants para qualquer usuário autenticado:

| Endpoint | Tabela | Risco |
|----------|--------|-------|
| `public/api/columns.php` | `pipeline_columns` | Médio |
| `public/api/task_boards.php` | `task_boards` | Médio |
| `public/api/goals.php` | `goals` | Médio |
| `public/api/dashboard.php` | `cards`, `goals` | Alto |
| `public/api/juridico_metrics.php` | `processos` | Alto |
| `public/api/whatsapp/instances.php` | `whatsapp_instances` | Alto |

Esses endpoints precisam ser atualizados para usar `AccountContext::fromSession()` e
filtrar por `account_id`. Não requerem nova migration — apenas alteração de código PHP.

---

## 5. Lista de Migrations Criados

| Arquivo | Tabela | Coluna(s) Adicionada(s) | Impacto |
|---------|--------|------------------------|---------|
| `017_add_cpf_cnpj_processos.sql` | `processos` | `cpf_cnpj_parte_contraria` | Corrige SQL error em POST/PUT de processos |
| `018_add_numero_cnj_processos.sql` | `processos` | `numero_cnj` | Corrige resolução de nome em TaskLink para tipo 'processo' |
| `019_add_titulo_cards.sql` | `cards` | `titulo` | Corrige resolução de nome em TaskLink para tipo 'card' |
| `020_add_dre_accounts_extra_cols.sql` | `dre_accounts` | `recorrencia`, `data_referencia`, `descricao` | Corrige SQL error em POST/PUT de DRE + resolução de nome em TaskLink |
| `021_add_deleted_at_webhooks.sql` | `webhooks` | `deleted_at` | Permite soft-delete e filtro correto no dispatcher |
| `022_add_senha_texto_users.sql` | `users` | `senha_texto` | Torna declarativa a coluna já criada via workaround em runtime |
| `023_add_prazo_task_checklist_items.sql` | `task_checklist_items` | `prazo` | Torna declarativa a coluna já criada via workaround em runtime |

---

## 6. Comando SQL para Aplicar as Migrations

Execute no MySQL na ordem abaixo (dentro do banco `sistema_vendas`):

```sql
-- Conectar ao banco:
-- C:\xampp\mysql\bin\mysql.exe -u root -p sistema_vendas

SOURCE C:/xampp/htdocs/sistema_vendas/database/migrations/017_add_cpf_cnpj_processos.sql;
SOURCE C:/xampp/htdocs/sistema_vendas/database/migrations/018_add_numero_cnj_processos.sql;
SOURCE C:/xampp/htdocs/sistema_vendas/database/migrations/019_add_titulo_cards.sql;
SOURCE C:/xampp/htdocs/sistema_vendas/database/migrations/020_add_dre_accounts_extra_cols.sql;
SOURCE C:/xampp/htdocs/sistema_vendas/database/migrations/021_add_deleted_at_webhooks.sql;
SOURCE C:/xampp/htdocs/sistema_vendas/database/migrations/022_add_senha_texto_users.sql;
SOURCE C:/xampp/htdocs/sistema_vendas/database/migrations/023_add_prazo_task_checklist_items.sql;
```

Todas as migrations usam `ADD COLUMN IF NOT EXISTS` — são **idempotentes** e seguras
para executar em banco que já tenha as colunas (por exemplo, adicionadas pelo workaround
de runtime).

### Verificação pós-aplicação

```sql
-- processos
DESCRIBE processos;
-- Deve mostrar: cpf_cnpj_parte_contraria, numero_cnj, (e as demais)

-- cards
DESCRIBE cards;
-- Deve mostrar: titulo, (e account_id, contato_id, checklist_percentual, deleted_by)

-- dre_accounts
DESCRIBE dre_accounts;
-- Deve mostrar: recorrencia, data_referencia, descricao

-- webhooks
DESCRIBE webhooks;
-- Deve mostrar: deleted_at

-- users
DESCRIBE users;
-- Deve mostrar: account_id, role, senha_texto

-- task_checklist_items
DESCRIBE task_checklist_items;
-- Deve mostrar: prazo
```
