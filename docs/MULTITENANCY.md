# Multi-Tenancy (SaaS) — Yuris

Visão técnica do sistema multi-tenant atual: tipos de conta, regras de acesso, fluxos e como rodar o teste e2e.

## Tipos de conta

| Tipo | Quem é | Acesso |
|---|---|---|
| **Matriz** | Escritório principal | Vê **tudo da própria matriz** + **tudo das filiais com vínculo ativo** |
| **Filial** | Escritório secundário vinculado a uma matriz | Vê **apenas o próprio tenant** + recursos explicitamente compartilhados |
| **Advogado associado** | Conta externa (tipo matriz isolada) sem vínculo | Vê **apenas recursos compartilhados** especificamente com ele |

## Identificador único do advogado

Cada usuário tem um `codigo_advogado` (formato `ADV-XXXXXX`, 6 hex maiúsculos) **único no sistema todo**.
Usado para compartilhar recurso/módulo com 1 advogado específico independente da matriz/filial dele.

## Dois tipos de compartilhamento

| Tipo | O que libera | Como liberar |
|---|---|---|
| **Recurso específico** | 1 processo / card / contato / task_board | Dentro do detalhe do processo: "+ Adicionar vínculo" |
| **Módulo inteiro** | Aba inteira (Processos, Jurídico, Dashboard, etc) | Em /escritorios.php aba **Módulos** → "+ Liberar módulo" |

Quem recebe um **módulo** passa a ver os dados da conta liberadora **naquela aba específica** — não em todas as abas.

## Assimetria de acesso (matriz ↔ filial)

```
       Matriz                Filial
         │                     │
         │  vínculo ativo     │
         │ ◄────────────────► │
         │                     │
   ┌─────▼──────┐       ┌─────▼──────┐
   │  vê TUDO   │ ─────►│  vê tudo   │
   │  da filial │       │  do próprio│
   │  + recursos│       │  + shares  │
   │  pontuais  │ ◄─x── │  recebidos │  (filial NÃO vê matriz)
   └────────────┘       └────────────┘
```

A direção **Matriz → Filial** é automática quando o vínculo está `active` em `account_vinculos`. A direção **Filial → Matriz** exige `resource_shares` explícito por recurso.

## Implementação técnica

### Núcleo: AccountContext

[app/Helpers/AccountContext.php](../app/Helpers/AccountContext.php) — extrai o tenant da sessão e calcula os `account_id`s acessíveis.

```php
$ctx = AccountContext::fromSession();
$ids = $ctx->getAccessibleAccountIds();
// Se sessão é matriz com filiais id=2,3 vinculadas → [1,2,3]
// Se sessão é filial id=2 → [2]
```

Helpers utilitários:

- `buildAccountInClause($alias)` — gera `alias.account_id IN (:k0, :k1, ...)` + params
- `buildResourceFilter($alias, $resourceType)` — adiciona também o `EXISTS resource_shares`
- `assertCanRead/assertCanWrite/assertIsOwnerOfResource` — para validações pontuais

### Models (filtro IN ao listar)

[Card::list()](../app/Models/Card.php), [Processo::list()](../app/Models/Processo.php), [TaskBoard::findForUser()](../app/Models/TaskBoard.php), [PipelineColumn::listAll()](../app/Models/PipelineColumn.php), [WhatsAppInstance::listAll()](../app/Models/WhatsAppInstance.php) aceitam `account_ids` (array) — passar `$ctx->getAccessibleAccountIds()` direto do endpoint.

Aceitam também `account_id` (int) por compat retroativa.

### Endpoints com filtro de tenant

Já filtrados via `AccountContext::fromSession()` + `getAccessibleAccountIds()`:

- `public/api/cards.php`
- `public/api/processes.php`
- `public/api/task_boards.php`
- `public/api/users.php` (escopado ao próprio tenant — não herda filiais)
- `public/api/columns.php`
- `public/api/goals.php`
- `public/api/dashboard.php`
- `public/api/juridico_metrics.php`
- `public/api/whatsapp/instances.php`
- `public/api/accounts.php`, `account_vinculos.php`, `resource_shares.php`, `advogado_convites.php`, `account_notifications.php`

## Tabelas (resumo)

```
accounts                    — matriz/filial, codigo_vinculo (UUID), plano, status
account_vinculos            — matriz↔filial, status pending|active|suspended|rejected
resource_shares             — type+id, from→to, permission_level view|edit|full
advogado_convites           — DEPRECATED (substituído por resource_shares + codigo_vinculo)
account_notifications       — notificações internas (sino)
account_audit_log           — imutável; ações críticas
```

## UI: tela única `/escritorios.php`

[public/escritorios.php](../public/escritorios.php) — 640 linhas, tema Yuris:

- Painel **Minha Conta**: nome, tipo, plano, status, código de vínculo (copiar)
- Aba **Vínculos**: aprovar/rejeitar/suspender (matriz) ou ver status (filial)
- Aba **Advogados Associados**: lista shares ativos com permissão
- Aba **Compartilhamentos**: todos os shares + revogar
- Aba **Solicitar Vínculo** (só filial): informar codigo_vinculo da matriz
- Modal **Compartilhar com Advogado**: busca conta por código → seleciona processo → cria share

## Como rodar o teste e2e

```bash
C:\xampp\php\php.exe C:\xampp\htdocs\sistema_vendas\scripts\test_multitenancy_e2e.php
```

Cenários validados:

| # | Cenário | Esperado |
|---|---|---|
| 1 | Criar filial + admin | Filial existe com `tipo='filial'` |
| 2 | Solicitar e aprovar vínculo | `account_vinculos.status='active'` |
| 3 | Criar processo na filial | Processo com `account_id=filial.id` |
| 4 | Matriz lista processos | Vê o da filial automaticamente |
| 5 | Filial lista processos | NÃO vê os da matriz |
| 6 | Matriz compartilha 1 processo seu com a filial | `resource_shares` criado |
| 7 | Filial relista | Vê seu processo + o compartilhado, **não** os outros |
| 8 | Criar "advogado externo" (conta isolada) + share de 1 processo | Share criado |
| 9 | Advogado lista processos | Vê **apenas** o processo compartilhado |

Última execução: **12/12 ✅** (inclui share por `to_user_id` e module share).

## Segurança: regras invioláveis

1. `account_id` NUNCA vem do body/query — sempre da sessão (`AccountContext::fromSession()`)
2. Listagens sem `account_id`/`account_ids` retornam vazio (nunca todos os dados)
3. Token de convite usa `bin2hex(random_bytes(32))` (256 bits)
4. Aceite de vínculo/convite valida `status='pending'` e `expires_at > NOW()`
5. Aprovação de vínculo só pode ser feita pela conta **Matriz**
6. `account_audit_log` é imutável (apenas INSERT)
7. Sessão é regenerada no login (`session_regenerate_id(true)`)

## Próximos passos opcionais

- Cron diário: `AdvogadoConvite::expirarVencidos()` — auto-expirar tokens vencidos
- E-mail de convite (SMTP) — hoje o `accept_url` precisa ser copiado manualmente
- Sino de notificações no header (ícone com badge de não lidas)
- Auto-vínculo opcional: matriz pode pré-aprovar filiais cadastradas com mesmo CNPJ raiz
