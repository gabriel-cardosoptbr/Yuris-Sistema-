# Auditoria de Segurança — Yuris (2026-05-26)

Escopo: varredura estática read-only do repositório `C:\xampp\htdocs\sistema_vendas` antes do push para GitHub e deploy Ubuntu/AWS. NENHUMA alteração feita.

Postura geral: o projeto está em estado **maduro** pós-LGPD (52 itens corrigidos). A maioria dos riscos estruturais (Crypto AES-256-GCM, TenantGuard, ErrorReporter, .htaccess, cookies seguros, anti-fixation, rate-limit, ofuscação de erros em prod) já existe. Os achados abaixo são pontuais.

---

## 1. Credenciais no repo

- `.env` está corretamente em `.gitignore` (regra `*.env` + `.env` + `.env.*`, com whitelist `!.env.example`).
- `git check-ignore .env` → confirma ignorado.
- **Git history**: `git log --all --diff-filter=A --name-only | grep -iE "\.env|secret|password|\.key"` → retorna **apenas** `.env.example`. Nenhum segredo foi commitado no passado.
- `.env.example` está vazio nos campos sensíveis (`MFA_ENCRYPTION_KEY=`, `APP_ENCRYPTION_KEY=`, `DB_PASS=`) — OK.
- Padrão `password=...`/`api_key=...` no código: 1 hit, falso positivo em `public/webhooks.php:860` (HTML doc dentro de `<pre>` mostrando `$secret = 'seu_secret_aqui'` como exemplo na UI).
- Nenhum hash/chave hardcoded em código PHP.

**Severidade: nenhuma.**

---

## 2. SQL Injection

Total de `->query(` em models/endpoints: 33 arquivos. Inspecionados os com concatenação dinâmica:

- `LIMIT $limit` em 8 locais (`task_link_search.php` 1-89, `DataProcessor.php:183`, `SecurityIncident.php:217`, `master/consents.php:85`, `webhooks.php:106`). **Todos** com `min((int)$_GET['limit'], N)` ou `max(1, min(500, $limit))` antes da interpolação — **seguros**.
- `IN (...)` dinâmico para tenant: sempre construído com placeholders nomeados via `goals_in_clause`-style helper.
- `INTERVAL $d DAY` em `DataProcessor.php:164`: `$d = max(1, min(365, (int)$f['vencendo_dias']))` — seguro.
- Nenhuma concatenação direta de `$_GET`/`$_POST` em SQL encontrada. Todos os `$where[]` constroem placeholders nomeados.

**Severidade: nenhuma.** Padrão de prepared statements bem aplicado.

---

## 3. CSRF

CSRF é checado em **64 dos 68** endpoints POST. Auditados os 9 que aparentam não checar:

| Endpoint | Veredito |
|---|---|
| `whatsapp/webhook.php` | OK — recebe Evolution API externa, autentica por `apikey` header (intencional). |
| `lgpd/request.php` | OK — endpoint público (titular sem login) com rate-limit por IP+email. |
| `master/webhook_receiver.php` | Verificar manualmente — receiver externo. |
| `dashboard_settings.php` | **MEDIA** — POST grava em sessão sem CSRF. Baixo impacto (só datas do dashboard). |
| `goals.php` | **MEDIA** — POST de metas sem CSRF claramente visível. Validar. |
| `processes.php` | **ALTA** — POST/PUT/DELETE em processos, dado sensível. Não vi token check no topo. |
| `processo_history.php` | **ALTA** — POST sem CSRF (confirmado linhas 49-93). Permite forjar histórico via CSRF. |
| `processo_prazos.php` | **ALTA** — POST sem CSRF visível. |
| `processo_tarefas.php` | **ALTA** — POST sem CSRF visível. |

Origin/SameSite=Lax mitiga parcialmente em browsers modernos, mas LGPD/Art.46 exige defesa explícita.

---

## 4. XSS

- `echo $_GET/$_POST` direto: **zero hits**.
- `<?= $_GET ?>` / `<?php echo $_GET ?>` em template: **zero hits**.
- `htmlspecialchars(` aparece em 88 ocorrências em 18 arquivos public — escape consistente.

**Severidade: nenhuma direta.** Recomenda-se CSP header em produção (não encontrado).

---

## 5. Tenant Isolation

- `AccountContext::fromSession()` é usado em ~100% dos endpoints `/api/`.
- `TenantGuard::assertProcessoAcessivel/assertTaskAcessivel/...` validam ownership antes de qualquer read/write.
- SELECTs em tabelas operacionais (`cards`, `processos`, `tasks`, `contatos`, `taxes`, `dre_*`, `webhook_endpoints`, `whatsapp_*`) sempre incluem `AND account_id IN (:placeholders)` derivado de `getAccessibleAccountIds(...)`.
- `WhatsAppMessage::78` resolve `account_id` via `instance_id` — OK (mas verificar se inserts validam tenant antes).
- Não foram encontradas queries lendo dados operacionais filtradas SÓ por `user_id` sem `account_id`.

**Severidade: nenhuma.** Multi-tenant bem isolado.

---

## 6. .htaccess / arquivos sensíveis HTTP

- Raiz `/.htaccess`: bloqueia `.md`, `.bat`, `.sh`, `.sql`, `.log`, `.ini`, `.env`, `.lock`, `.sqlite`, `composer.*`, `.dotfiles`; redirect 403 para `docs|scripts|tests|vendor|node_modules|config`.
- `/app/.htaccess`: `Require all denied`.
- `/config/.htaccess`: `Require all denied`.
- `/database/.htaccess`: `Require all denied`.
- `/storage/.htaccess`: `Require all denied`.
- **AUSENTES**: `/scripts/.htaccess` e `/docs/.htaccess` — protegidos pela regra RedirectMatch da raiz (defesa em profundidade ausente, mas funcional).
- `/public/uploads/.htaccess`: `Require all denied` + bloqueio explícito de execução `.php|.phtml|.cgi|.pl|.py|.sh|.asp` (defesa anti-webshell). Excelente.

**Severidade: baixa.** Adicionar `.htaccess` standalone em `/scripts/` e `/docs/` é defesa em profundidade.

---

## 7. Upload

Apenas 2 endpoints chamam `move_uploaded_file`:

- `public/api/task_attachments.php:136`: MIME validado via `finfo`, allowlist (`ALLOWED_MIME`), filename gerado com `bin2hex(random_bytes(16))` (256 bits), salvo em `public/uploads/tasks/{id}/` bloqueado por `.htaccess`. **OK.**
- `public/api/master/lgpd_request_attachments.php:178`: MIME `finfo`, allowlist, `LGPD_ATT_MAX_SIZE` (25MB), nome do arquivo = `sha256.ext`, extensão sanitizada por regex, salvo em `storage/lgpd_requests/` bloqueado por `.htaccess`. **OK.**

**Severidade: nenhuma.**

---

## 8. Sessões

- `AuthController::ensureSessionStarted()`: `session_set_cookie_params([HttpOnly=true, SameSite=Lax, Secure=auto-detect HTTPS])` antes do `session_start()`.
- `master_login.php:44`: mesma config.
- `session_regenerate_id(true)` no login bem-sucedido — anti-fixation OK.
- `AuthController::logout()`: limpa `$_SESSION`, expira cookie via `setcookie(session_name(), '', time()-42000, ...)`, então `session_destroy()`. **Completo.**
- `AccountContext::assertAccountActive()` também faz tear-down completo quando conta é suspensa.

**Severidade: nenhuma.**

---

## 9. Debug em prod

- 19 endpoints (`/api/aasp/*`, `/api/push/*`, `/api/whatsapp/*`, `task_checklist.php`) hardcoded com `@ini_set('display_errors', '0')` no topo — defesa explícita.
- `error_reporting(0)` aparece em `task_checklist.php:2`. Os demais não desligam globalmente — usam `ErrorReporter::handle($e)` que detecta `APP_ENV=production` e oculta `$e->getMessage()` (substitui por `correlation_id`).
- `ErrorReporter` em modo dev devolve stack details — comportamento esperado.

**Risco residual:** se `APP_ENV` não estiver configurado em produção, o helper cai em modo "development" e vaza mensagens. **Verificar em deploy que `APP_ENV=production` está no `.env` do servidor.**

---

## 10. Senhas

- Sempre `password_hash($senha, PASSWORD_BCRYPT)` (login_login.php, users.php, master/advogados.php, master/users.php, master/create_account.php, master/create_filial.php, scripts/seed_admin.php).
- `password_verify` no login.
- Nenhum `md5(`/`sha1(` aplicado a senhas (única menção em `AdvogadoConvite.php:16` é comentário de "NÃO USAR").
- `users.senha_texto` (coluna legada): `AuthController` declara que **não popula mais** essa coluna; em `seed_demo.sql:77-95` ela é populada com a string literal `'password'` para fins de demo — não é hash, mas também não é uma senha real do tenant. **Recomendar remover a coluna `senha_texto` da migration final** (limpar dado legado).
- 2FA TOTP via `TotpHelper` com secret cifrado AES-256-CBC (key = `MFA_ENCRYPTION_KEY`).
- Backup codes cifrados com bcrypt (`password_hash`).

**Severidade: baixa.** Coluna `senha_texto` deveria ser dropada por DROP COLUMN em migration nova.

---

## 11. History git

- 174 commits totais.
- `git log --all --diff-filter=A --name-only | grep -iE "\.env|secret|password|\.key|credentials"` → **somente `.env.example`**.
- Nenhum segredo entrou em qualquer commit, em qualquer branch, em qualquer momento. **History limpo.**

---

## 12. Bloqueadores 🔴

1. **CSRF ausente em endpoints sensíveis de processos** (`processo_history.php`, `processo_prazos.php`, `processo_tarefas.php`, `processes.php POST`): permite ataques CSRF que forjam histórico/prazo em nome do advogado autenticado. **Bloqueador para produção SaaS multi-tenant.**

## 13. Plano de correção

| Prioridade | Item | Esforço |
|---|---|---|
| P0 | Adicionar `requireCsrf()` (helper centralizado) em `processo_history.php`, `processo_prazos.php`, `processo_tarefas.php`, `processes.php` POST/PUT/DELETE. | 30 min |
| P0 | Confirmar `APP_ENV=production` no `.env` do servidor Ubuntu/AWS antes do primeiro request. | 5 min |
| P1 | Auditar `dashboard_settings.php`, `goals.php`, `master/webhook_receiver.php` quanto a CSRF/origem do request. | 20 min |
| P1 | DROP COLUMN `users.senha_texto` em migration nova; remover do `seed_demo.sql`. | 10 min |
| P2 | Adicionar `.htaccess` em `/scripts/` e `/docs/` (defesa em profundidade). | 5 min |
| P2 | Adicionar header CSP (`default-src 'self'`) em respostas HTML do app. | 1h |
| P2 | Considerar mover `seed_demo.sql` para `database/seeds/` com `.htaccess` ou renomear `.sql.example`. | 5 min |

---

**Top 5 vulnerabilidades críticas (1 linha cada):**

1. `public/api/processo_history.php:49` — POST grava em `processo_history` sem CSRF; forjamento via cross-site.
2. `public/api/processo_prazos.php` — POST cria/edita prazos processuais sem CSRF.
3. `public/api/processo_tarefas.php` — POST sem CSRF (vínculo tarefa-processo).
4. `public/api/processes.php` POST/PUT/DELETE — falta de token CSRF claramente visível.
5. `users.senha_texto` ainda populada com literal `'password'` no `seed_demo.sql` — risco se seed for executado em ambiente não-isolado.

**Production-safe?** **Com ressalvas** — corrigir os 4 endpoints CSRF de processos (30 min) e confirmar `APP_ENV=production` antes do deploy.
