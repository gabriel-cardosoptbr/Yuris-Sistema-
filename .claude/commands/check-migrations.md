# Verificar Status das Migrations

Você é um DBA assistente. Sua tarefa é verificar se todas as migrations do projeto estão aplicadas no banco de dados MySQL do XAMPP.

## Passo 1 — Listar migrations no disco

Liste todos os arquivos em `database/migrations/` e extraia os nomes ordenados.

## Passo 2 — Consultar tabelas no banco

Execute via bash o seguinte comando para listar as tabelas reais do banco:

```bash
C:\xampp\mysql\bin\mysql.exe -u root sistema_vendas -e "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'sistema_vendas' ORDER BY TABLE_NAME;"
```

Se o banco tiver senha, use `-p` e peça ao usuário.

## Passo 3 — Consultar colunas críticas das migrations recentes

Para cada migration a partir da 024, verifique se as colunas que ela adiciona existem:

```bash
C:\xampp\mysql\bin\mysql.exe -u root sistema_vendas -e "
SELECT TABLE_NAME, COLUMN_NAME 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = 'sistema_vendas'
  AND (
    (TABLE_NAME = 'accounts'            AND COLUMN_NAME IN ('plano','configuracoes','codigo_vinculo'))
    OR (TABLE_NAME = 'account_vinculos' AND COLUMN_NAME IN ('solicitado_por','aprovado_por','solicitado_em'))
    OR (TABLE_NAME = 'resource_shares'  AND COLUMN_NAME IN ('to_user_id','criado_por','revoked_at'))
    OR (TABLE_NAME = 'advogado_convites' AND COLUMN_NAME IN ('convidado_user_id','responded_at','revoked_at'))
    OR (TABLE_NAME = 'account_notifications' AND COLUMN_NAME = 'lida_em')
    OR (TABLE_NAME = 'users'            AND COLUMN_NAME IN ('account_id','role'))
    OR (TABLE_NAME = 'tasks'            AND COLUMN_NAME IN ('prazo','tipo'))
    OR (TABLE_NAME = 'chat_conversas'   AND COLUMN_NAME IN ('account_id','team_id'))
    OR (TABLE_NAME = 'whatsapp_chats'   AND COLUMN_NAME = 'team_id')
  )
ORDER BY TABLE_NAME, COLUMN_NAME;
"
```

## Passo 4 — Verificar tabelas esperadas vs reais

As tabelas que DEVEM existir após todas as migrations (001–030) são:

```
account_audit_log, account_notifications, account_vinculos, accounts,
advogado_convites, card_checklist_items, card_history, cards,
chat_conversas, chat_mencoes, chat_mensagens, chat_participantes,
contato_vinculos, contatos, dre_accounts, dre_codes, goals,
pipeline_columns, processo_history, processo_prazos, processo_tarefas,
processos, resource_shares, task_attachments, task_board_members,
task_boards, task_checklist_items, task_columns, task_comments,
task_history, task_links, task_recurrences, task_reminders,
task_time_entries, tasks, team_members, teams, user_permissions,
users, webhook_logs, webhooks, whatsapp_chat_processos, whatsapp_chats,
whatsapp_contacts, whatsapp_instances, whatsapp_messages, whatsapp_settings
```

Total esperado: **47 tabelas**.

## Passo 5 — Relatório final

Apresente um relatório com:

1. **✅ Migrations aplicadas** — tabelas e colunas presentes conforme esperado
2. **❌ Migrations pendentes** — tabelas ou colunas ausentes, indicando qual arquivo .sql precisa ser executado
3. **⚠️ Anomalias** — tabelas no banco que não correspondem a nenhuma migration conhecida
4. **Próxima ação** — comando SQL exato para executar a migration pendente, se houver

Se tudo estiver ok, confirme: "Todas as migrations 001-030 estão aplicadas corretamente."
