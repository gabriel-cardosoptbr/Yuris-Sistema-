# Schema Audit — Yuris Multi-Tenant

> Auditoria completa: alinhamento entre banco MySQL, Models PHP e endpoints REST.
> Última auditoria: `2026-05-21`. Confirmada com `scripts/test_multitenancy_e2e.php` (12/12 ✅).

## TL;DR

- **47 tabelas** no banco local
- **17 tabelas com `account_id` direto** (tenant explícito)
- **30 tabelas sem `account_id`** — todas com caminho de herança válido via FK
- **Conta nova nasce zerada** em 11/11 áreas críticas (validado por script)
- **Isolamento por tenant**: enforced via `AccountContext::fromSession()` em todos os endpoints REST

## Tabelas e estratégia de isolamento

### Grupo A — tenant direto (`account_id` na própria tabela)

| Tabela | Linhas | Endpoint principal | Módulo |
|---|---:|---|---|
| `accounts` | 2 | `/api/accounts.php` | — |
| `account_audit_log` | 5 | (interno) | — |
| `account_notifications` | 8 | `/api/account_notifications.php` | — |
| `cards` | 25 | `/api/cards.php` | `prospeccao` |
| `chat_conversas` | 3 | `/api/chat/conversas.php` | `chat_interno` |
| `contatos` | 31 | `/api/contatos.php` (via vínculos) | `prospeccao` |
| `dre_accounts` | 20 | `/api/dre_accounts.php` | `financas` |
| `dre_codes` | 0 | `/api/dre_codes.php` | `financas` |
| `goals` | 1 | `/api/goals.php` | `dashboard` |
| `pipeline_columns` | 5 | `/api/columns.php` | `prospeccao` |
| `processos` | 59 | `/api/processes.php` | `processos` |
| `task_boards` | 5 | `/api/task_boards.php` | `tarefas` |
| `taxes` | 1 | `/api/taxes.php` | `financas` |
| `teams` | 3 | `/api/teams.php` | — |
| `users` | 9 | `/api/users.php` | — |
| `webhooks` | 0 | `/api/webhooks.php` | — |
| `whatsapp_instances` | 3 | `/api/whatsapp/instances.php` | `chat` |
| `whatsapp_settings` | 4 | (interno) | `chat` |

### Grupo B — herda tenant via FK (sem `account_id` próprio)

| Tabela filha | Tabela pai (com `account_id`) | Validado em |
|---|---|---|
| `card_checklist_items` | `cards` | `card_checklist.php` → `TenantGuard::assertCardAcessivel` |
| `card_history` | `cards` | implícito via Card endpoint |
| `chat_mencoes` | `chat_conversas` | `chat/mencoes.php` valida participação |
| `chat_mensagens` | `chat_conversas` | `chat/mensagens.php` valida participação |
| `chat_participantes` | `chat_conversas` | idem |
| `contato_vinculos` | `contatos` | `whatsapp/contato_vinculos.php` agora JOIN + `account_id IN (...)` |
| `processo_history` | `processos` | `processo_history.php` → `TenantGuard::assertProcessoAcessivel` |
| `processo_prazos` | `processos` | `processo_prazos.php` → `TenantGuard` |
| `processo_tarefas` | `processos` | `processo_tarefas.php` → `TenantGuard` |
| `tasks` | `task_boards` | `TaskBoard::canView` + `TenantGuard::assertTaskAcessivel` |
| `task_attachments` | `tasks` → `task_boards` | `task_attachments.php` → `TenantGuard` |
| `task_board_members` | `task_boards` | `TaskBoard::findById($id, $tenantIds)` |
| `task_checklist_items` | `tasks` | `task_checklist.php` → `TenantGuard` |
| `task_columns` | `task_boards` | endpoint usa `TaskBoard::canView` |
| `task_comments` | `tasks` | `task_comments.php` → `TenantGuard` |
| `task_history` | `tasks` | implícito |
| `task_links` | `tasks` | `task_link_search.php` filtra na busca |
| `task_recurrences` | `tasks` | endpoint usa board |
| `task_reminders` | `tasks` | endpoint usa board |
| `task_time_entries` | `tasks` | endpoint usa board |
| `team_members` | `teams` | `teams.php` valida tenant |
| `user_permissions` | `users` | herda do user logado |
| `webhook_logs` | `webhooks` | `webhooks.php?action=logs` agora INNER JOIN com `account_id IN (...)` |
| `whatsapp_chats` | `whatsapp_instances` | `whatsapp/chats.php` valida instância |
| `whatsapp_chat_processos` | `whatsapp_chats` | herdado |
| `whatsapp_contacts` | `whatsapp_instances` | `whatsapp/contacts.php` valida instância |
| `whatsapp_messages` | `whatsapp_instances` | `whatsapp/messages.php` valida instância |

### Grupo C — infra multi-tenant (relacionamento entre contas)

| Tabela | Função |
|---|---|
| `account_vinculos` | Vínculo matriz↔filial — usa `matriz_account_id` + `filial_account_id`, sem `account_id` único |
| `resource_shares` | Compartilhamento entre contas — usa `from_account_id` + `to_account_id` |
| `advogado_convites` | Convites por token — `from_account_id` |

## Correções aplicadas nesta auditoria

| # | Problema | Solução |
|---|---|---|
| 1 | `whatsapp/messages.php` lia mensagens sem validar tenant da instância | Agora chama `findOrCreate($name, '', $accountId)` + valida `instance.account_id == $accountId` |
| 2 | `whatsapp/chats.php` idem | Idem |
| 3 | `whatsapp/contacts.php` idem | Idem |
| 4 | `whatsapp/contato_vinculos.php` buscava contato global por telefone | JOIN com `account_id IN (tenantIds)` em todas as 4 estratégias de busca |
| 5 | `webhooks.php?action=logs` listava todos os logs | INNER JOIN com `webhooks.account_id IN (tenantIds)` |
| 6 | `TaskBoard::findById` não filtrava tenant | Aceita `int\|array` accountIds opcional |
| 7 | `Card::update` não incluía `titulo` no allowed | Adicionado |
| 8 | `Card::create` não populava `titulo` | Auto-popula com `cliente_nome` se não informado |
| 9 | Modelo `ResourceShare::$validTypes` não incluía `'module'` | Inline merge no `create()` |
| 10 | `dre_codes` corrompida no engine | Recriada via migration 033 |

## Validação automatizada

### Script de teste e2e

```bash
C:\xampp\php\php.exe scripts/test_multitenancy_e2e.php
```

12 cenários, todos passando:
1. Estado inicial
2. Criar filial + admin
3. Solicitar e aprovar vínculo
4. Matriz vê processos da filial ✓
5. Filial NÃO vê processos da matriz ✓
6. Matriz compartilha 1 processo com filial
7. Filial vê próprio + compartilhado, **não** os outros ✓
8. Advogado externo (conta isolada)
9. Advogado vê **apenas** o processo convidado ✓
10. Compartilhar com user específico (`to_user_id`) ✓
11. Module share libera aba inteira ✓
12. Cleanup automático

### Script de validação de isolamento

Criar conta nova e confirmar que vê 0 em todas as áreas:

```php
// Resultado validado: 11/11 tabelas zeradas para conta nova
processos              0 OK
cards                  0 OK
contatos               0 OK
task_boards            0 OK
pipeline_columns       0 OK
dre_accounts           0 OK
goals                  0 OK
taxes                  0 OK
webhooks               0 OK
whatsapp_instances     0 OK
teams                  0 OK
```

## Para subir no servidor

### Banco

Rodar **um** arquivo único:

```
database/migrations/035_servidor_full_multitenant.sql
```

Idempotente, cria tudo do zero ou só preenche o que falta. Validado localmente.

### Código PHP (upload)

```
app/Helpers/AccountContext.php
app/Helpers/TenantGuard.php
app/Models/Account.php
app/Models/AccountNotification.php
app/Models/AccountVinculo.php
app/Models/AdvogadoConvite.php
app/Models/Card.php
app/Models/DREAccount.php
app/Models/DRECode.php
app/Models/PipelineColumn.php
app/Models/Processo.php
app/Models/ResourceShare.php
app/Models/TaskBoard.php
app/Models/WhatsAppInstance.php
public/api/*.php
public/api/whatsapp/*.php
public/api/chat/*.php
public/escritorios.php
public/processos.php
public/usuarios.php
public/dashboard.php (se alterado)
```

### Checklist de validação no servidor

Após deploy, rodar este SELECT no servidor pra confirmar que está alinhado com o local:

```sql
SELECT t.TABLE_NAME, t.TABLE_ROWS,
       CASE WHEN EXISTS(SELECT 1 FROM information_schema.COLUMNS c
                        WHERE c.TABLE_SCHEMA=t.TABLE_SCHEMA AND c.TABLE_NAME=t.TABLE_NAME AND c.COLUMN_NAME='account_id')
            THEN 'SIM' ELSE 'não' END AS tem_account_id
FROM information_schema.TABLES t
WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_TYPE='BASE TABLE'
ORDER BY t.TABLE_NAME;
```

Comparar com a lista do **Grupo A** acima. As 17 tabelas listadas devem aparecer com `SIM`.

E depois criar uma conta de teste e verificar que ela está zerada:

```sql
-- substitua 999 pelo id da conta nova
SELECT 'processos', COUNT(*) FROM processos WHERE account_id = 999;
SELECT 'cards', COUNT(*) FROM cards WHERE account_id = 999;
SELECT 'contatos', COUNT(*) FROM contatos WHERE account_id = 999;
-- etc
```

Tudo deve dar 0.
