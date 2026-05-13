# CLAUDE.md — Guia de Implementação: Matriz / Filial / Advogado Associado

> **Para o Claude Code (VSCode):** Este arquivo contém todas as instruções para implementar
> o sistema de multi-tenancy com Matriz, Filial e Advogado Associado no projeto Yuris.
> Execute as tarefas na ordem indicada. Cada fase é independente e testável.

---

## Visão Geral da Implementação

### O que foi gerado
Esta implementação adiciona **segregação multi-tenant** ao sistema, permitindo:
- Contas do tipo **Matriz** (escritório principal)
- Contas do tipo **Filial** (vinculadas a uma Matriz via código de vínculo)
- **Advogado Associado** (externo, convidado por token para acompanhar processos/clientes específicos)
- **Compartilhamento seletivo** de cards, processos e contatos entre contas vinculadas

### Padrão de mercado adotado
- **Shared-database / Shared-schema com tenant_id por coluna** (igual Clio, HubSpot, Pipedrive)
- **Invitation token com expiração** usando `bin2hex(random_bytes(32))` (igual Figma, GitHub)
- **Hierarquia pai-filho de 1 nível** Matriz → Filial (igual Google Workspace, Slack Connect)
- **Resource-level sharing com permission_level** (igual Notion "Share page", Google Drive ACL)

---

## FASE 1 — Executar Migrations no Banco de Dados

> **Estado atual:** banco reiniciado com migrations 001–026 já aplicadas.
> Só é necessário executar a migration 027, que alinha as colunas que os
> Models PHP precisam e que não constavam nas migrations anteriores.

### 1.1 Abrir o MySQL no XAMPP
```bash
# Via terminal (ajuste o caminho se necessário)
C:\xampp\mysql\bin\mysql.exe -u root -p sistema_vendas
```

### 1.2 Executar a migration pendente
```sql
SOURCE C:/xampp/htdocs/sistema_vendas/database/migrations/027_add_missing_multitenancy_cols.sql;
```

### 1.3 Verificar resultado
```sql
-- Confirmar que conta padrão foi criada e tem codigo_vinculo
SELECT id, nome, tipo, plano, codigo_vinculo, status FROM accounts;

-- Confirmar que todos os usuários têm account_id e role
SELECT id, nome, account_id, role FROM users;

-- Confirmar colunas novas em accounts
DESCRIBE accounts;

-- Confirmar colunas novas em account_vinculos
DESCRIBE account_vinculos;

-- Confirmar colunas novas em resource_shares
DESCRIBE resource_shares;

-- Confirmar colunas novas em advogado_convites
DESCRIBE advogado_convites;

-- Confirmar colunas novas em account_notifications
DESCRIBE account_notifications;
```

**Resultado esperado após migration 027:**
- `accounts` com colunas `plano` e `configuracoes`; `codigo_vinculo` preenchido em todas as linhas
- `account_vinculos` com colunas `solicitado_por`, `aprovado_por`, `solicitado_em`, `aprovado_em`, `suspenso_em`, `motivo_suspensao`
- `resource_shares` com colunas `to_user_id`, `criado_por`, `revoked_at`, `revoked_by`
- `advogado_convites` com colunas `convidado_user_id`, `responded_at`, `revoked_at`, `revoked_by`
- `account_notifications` com coluna `lida_em`

---

## FASE 2 — Verificar Arquivos Novos

> O Claude Code deve confirmar que estes arquivos existem antes de prosseguir.

### Novos arquivos criados:
```
app/
├── Helpers/
│   └── AccountContext.php          ← CORE: extrai account_id da sessão, valida acesso
├── Models/
│   ├── Account.php                 ← CRUD de contas + audit
│   ├── AccountVinculo.php          ← vínculos Matriz↔Filial
│   ├── ResourceShare.php           ← compartilhamento de recursos
│   ├── AdvogadoConvite.php         ← convites para advogado externo
│   └── AccountNotification.php     ← notificações internas

public/api/
├── accounts.php                    ← GET/PUT dados da conta
├── account_vinculos.php            ← GET/POST/PATCH/DELETE vínculos
├── resource_shares.php             ← GET/POST/DELETE compartilhamentos
├── advogado_convites.php           ← GET/POST/PATCH/DELETE convites
└── account_notifications.php       ← GET/PATCH notificações

database/migrations/
├── 014_create_accounts.sql
├── 015_create_sharing_system.sql
└── 016_add_account_id_resources.sql
```

### Arquivos modificados:
```
app/Controllers/AuthController.php  ← carrega account_id + account_tipo + user_role na sessão
app/Models/Card.php                 ← list() exige account_id, create() injeta account_id
app/Models/Processo.php             ← list() exige account_id, create() injeta account_id
public/api/cards.php                ← usa AccountContext, filtra por account_id
public/api/processes.php            ← usa AccountContext, filtra por account_id
public/api/users.php                ← usa AccountContext, filtra por account_id, injeta em create
```

---

## FASE 3 — Testar Endpoints Principais

> Use o terminal, Postman, Insomnia, ou Bruno para testar.

### 3.1 Login e verificação de sessão
```bash
# Fazer login e verificar que a sessão contém account_id
# O AuthController agora carrega: user_id, account_id, account_tipo, user_role
curl -c cookies.txt -d "login=admin&password=senha123&csrf_token=TOKEN" \
  http://localhost/sistema_vendas/public/auth/login.php -X POST
```

### 3.2 Dados da conta
```bash
# GET /api/accounts.php — retorna conta atual + filiais
curl -b cookies.txt http://localhost/sistema_vendas/public/api/accounts.php

# GET código de vínculo (apenas owner/admin)
curl -b cookies.txt "http://localhost/sistema_vendas/public/api/accounts.php?action=codigo"
```

### 3.3 Criar vínculo Matriz→Filial
```bash
# 1. Pegue o codigo_vinculo da Matriz (login como admin da Matriz):
curl -b cookies.txt "http://localhost/sistema_vendas/public/api/accounts.php?action=codigo"
# Resposta: { "codigo_vinculo": "abc123xyz..." }

# 2. Faça login como admin da Filial e solicite o vínculo:
curl -b filial_cookies.txt \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: TOKEN" \
  -d '{"codigo_vinculo": "abc123xyz..."}' \
  http://localhost/sistema_vendas/public/api/account_vinculos.php -X POST

# 3. Faça login como admin da Matriz e aprove:
curl -b matriz_cookies.txt \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: TOKEN" \
  -d '{"id": 1, "action": "aprovar"}' \
  http://localhost/sistema_vendas/public/api/account_vinculos.php -X PATCH
```

### 3.4 Compartilhar um processo com a filial
```bash
curl -b cookies.txt \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: TOKEN" \
  -d '{
    "resource_type": "processo",
    "resource_id": 1,
    "to_account_id": 2,
    "permission_level": "view"
  }' \
  http://localhost/sistema_vendas/public/api/resource_shares.php -X POST
```

### 3.5 Convidar advogado associado
```bash
curl -b cookies.txt \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: TOKEN" \
  -d '{
    "resource_type": "processo",
    "resource_id": 1,
    "convidado_email": "advogado@externo.com.br",
    "convidado_nome": "Dr. João Silva",
    "permission_level": "view",
    "mensagem": "Você foi convidado para acompanhar este processo.",
    "expires_em_dias": 30
  }' \
  http://localhost/sistema_vendas/public/api/advogado_convites.php -X POST
# Retorna: { "success": true, "id": 1, "accept_url": "http://..." }
```

### 3.6 Advogado aceita convite (rota pública)
```bash
# GET: ver detalhes do convite
curl "http://localhost/sistema_vendas/public/api/advogado_convites.php?token=TOKEN_AQUI"

# PATCH: aceitar
curl -H "Content-Type: application/json" \
  -d '{"token": "TOKEN_AQUI", "action": "aceitar"}' \
  http://localhost/sistema_vendas/public/api/advogado_convites.php -X PATCH
```

---

## FASE 4 — Criar Conta do Tipo Filial (SQL direto)

Para criar uma nova conta Filial e associar um usuário a ela:

```sql
-- Cria conta filial
INSERT INTO accounts (nome, tipo, codigo_vinculo, status)
VALUES ('Filial São Paulo', 'filial', UUID(), 'active');

-- Pega o ID gerado
SET @filial_id = LAST_INSERT_ID();

-- Cria usuário para a filial (ou atualiza existente)
-- Opção A: novo usuário
INSERT INTO users (account_id, nome, login, senha_hash, perfil, role, status)
VALUES (@filial_id, 'Admin Filial SP', 'admin.filial.sp', '$2y$10$...hash...', 'admin', 'owner', 'active');

-- Opção B: mover usuário existente para a filial
-- UPDATE users SET account_id = @filial_id WHERE id = ?;
```

---

## FASE 5 — Checklist de Segurança (Validar Antes de Produção)

O Claude Code deve verificar cada item:

- [ ] `account_id` nunca aparece como parâmetro aceito do body nos endpoints `/api/cards.php`, `/api/processes.php`, `/api/users.php`
- [ ] `AccountContext::fromSession()` é chamado no início de TODOS os endpoints autenticados
- [ ] `Card::list()` sem `account_id` retorna array vazio (nunca retorna tudo)
- [ ] `Processo::list()` sem `account_id` retorna array vazio
- [ ] Token de convite usa `bin2hex(random_bytes(32))` — confirmar em `AdvogadoConvite::criar()`
- [ ] Aceite de convite valida `status = 'pending'` AND `expires_at > NOW()`
- [ ] Aprovação de vínculo valida que o aprovador é da conta **Matriz** (não da Filial)
- [ ] `resource_shares` validate vínculo ativo antes de criar (ver `Account::temVinculoAtivo()`)
- [ ] `account_audit_log` está sendo populado nas operações críticas
- [ ] Sessão é regenerada no login (`session_regenerate_id(true)` — já existia no AuthController)

---

## FASE 6 — Endpoints Ainda Pendentes de Atualização

Os seguintes endpoints ainda precisam receber o filtro de `account_id` nas próximas sprints:

```
public/api/columns.php            → pipeline_columns por conta
public/api/goals.php              → goals por conta
public/api/dashboard.php          → métricas por conta
public/api/juridico_metrics.php   → métricas jurídicas por conta
public/api/whatsapp/instances.php → instâncias WhatsApp por conta
```

> **Já atualizados:** `cards.php`, `processes.php`, `users.php`, `task_boards.php`, `tasks.php`

**Padrão de atualização para cada um:**
1. Adicionar `require_once` do `AccountContext.php`
2. Chamar `$ctx = AccountContext::fromSession()`
3. Adicionar `AND account_id = :account_id` em todas as queries de SELECT
4. Injetar `account_id` em todos os INSERTs

---

## FASE 7 — Estrutura de Banco Resultante

```
accounts
  ├── id, nome, tipo (matriz|filial)
  ├── matriz_id → accounts.id (NULL se matriz)
  ├── codigo_vinculo (UUID público para conectar filial)
  └── status (active|suspended|cancelled)

account_vinculos
  ├── matriz_account_id → accounts.id
  ├── filial_account_id → accounts.id
  └── status (pending|active|suspended|rejected)

resource_shares
  ├── resource_type (card|processo|contato|task_board)
  ├── resource_id
  ├── from_account_id → accounts.id
  ├── to_account_id → accounts.id (NULL = todas as vinculadas)
  └── permission_level (view|edit|full)

advogado_convites
  ├── resource_type + resource_id
  ├── from_account_id → accounts.id
  ├── convidado_email + token_convite (bin2hex 256-bit)
  └── status (pending|accepted|rejected|revoked|expired)

account_notifications
  ├── account_id + user_id (NULL = para toda a conta)
  ├── tipo + titulo + mensagem + payload JSON
  └── lida (bool)

account_audit_log
  └── imutável — apenas INSERT, nunca UPDATE/DELETE
```

---

## FASE 8 — Próximos Passos Opcionais

Após validar as fases 1-5, estas melhorias podem ser adicionadas:

1. **Tela de Configurações de Conta** (`/configuracoes/conta.php`) — exibir tipo, código de vínculo, filiais
2. **Tela de Vínculos** (`/configuracoes/vinculos.php`) — listar/aprovar/rejeitar vínculos
3. **Botão "Compartilhar"** em cada card/processo — abre modal para selecionar conta + permissão
4. **Badge de advogados associados** em cada processo — lista quem está acompanhando
5. **Envio de e-mail do convite** — integrar com SMTP ou serviço de e-mail (link `accept_url`)
6. **Cron de expiração** — chamar `AdvogadoConvite::expirarVencidos()` diariamente
7. **Filtro de notificações** no header — ícone de sino com badge de não lidas

---

## Referências de Padrões Usados

- **Multi-tenancy shared-schema**: [WorkOS SaaS Guide](https://workos.com/blog/developers-guide-saas-multi-tenant-architecture)
- **Invitation token pattern**: [GitHub collaborator invites](https://docs.github.com/rest/collaborators)
- **Organization hierarchy**: [Slack Connect](https://slack.com/intl/pt-br/help/articles/360048357063)
- **Resource sharing ACL**: [Notion sharing](https://www.notion.so/help/sharing-and-permissions)
- **Audit log immutable**: [Stripe audit trail](https://stripe.com/docs/security)
