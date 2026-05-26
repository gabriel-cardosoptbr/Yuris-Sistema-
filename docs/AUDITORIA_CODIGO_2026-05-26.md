# Auditoria de Código — Yuris (2026-05-26)

Escopo: 223 arquivos PHP (excluído `.backup_fase0_*`, sem `vendor/` nem `node_modules/`).
Objetivo: identificar bloqueadores para deploy em Ubuntu Linux na AWS, vindo de XAMPP/Windows.
Método: análise estática (Grep, Read, Python script de validação de paths), lint `php -l` em todos os 223 arquivos.

---

## 1. Linux compatibility (case-sensitive paths, encoding, etc.)

### 1.1 require/include com __DIR__ — análise mecânica
- Cruzados ~150 caminhos de `require_once __DIR__ . '/...'` e `require_once dirname(__DIR__) . '/...'` contra a lista real de arquivos no disco. Resultado:
  - **0 backslashes** em literais de require/include — todos os separators são `/`.
  - **0 caminhos absolutos Windows** (`C:/...`) em código.
  - **0 case mismatches** entre o nome usado no `require` e o filename real (todos os 222 arquivos PHP de `app/` e `public/` batem com o case usado nos requires). Mesmo nomes como `WhatsAppInstance.php`, `LgpdRequest.php`, `AaspIntegration.php`, `DREAccount.php` estão consistentes.
- **1 require quebrado encontrado**: 🟠 `public/api/whatsapp/opcache_clear.php:2` usa `__DIR__ . '/../../../../app/Models/Database.php'` — 4 níveis acima, mas precisa 3. Bug silencioso (file_exists trata caso `realpath` retornar false, mas o `require_once` da linha 2 vai dar fatal). Esse endpoint provavelmente nunca foi executado. **Não bloqueia deploy** mas a feature está quebrada hoje no Windows também.

### 1.2 include sem `__DIR__` (frágil — depende de cwd)
- 🟡 `public/chat.php:1812`: `include 'includes/sidebar.php'` — sem `__DIR__`. Funciona se Apache servir o arquivo a partir de `public/` (que é o caso atual e provável no deploy), mas é a única ocorrência inconsistente: todos os outros 15 arquivos que carregam `sidebar.php` usam `__DIR__`. Recomendado padronizar.

### 1.3 Encoding / Line endings
- Não foi feita análise CRLF/LF arquivo a arquivo, mas `git config core.autocrlf` típico do Windows costuma manter LF em PHP. **Risco baixo** porque PHP aceita ambos, mas scripts em `bin/` invocados via `#!/usr/bin/env php` precisariam de LF. `bin/webhook_worker.php` não tem shebang — é chamado via `php /path/to/file.php`, então CRLF não atrapalha.

### 1.4 Paths armazenados no banco com `DIRECTORY_SEPARATOR`
- 🟠 `public/api/master/lgpd_request_attachments.php:183-184`: grava em `lgpd_request_attachments.caminho_arquivo` o resultado de `'storage' . DIRECTORY_SEPARATOR . 'lgpd_requests' . DIRECTORY_SEPARATOR . $reqId . ...`. No Windows isso vira `storage\lgpd_requests\3\hash.pdf`. No Linux após migrar, esses paths antigos vão precisar de tratamento na leitura. O download (linha 67) já faz `str_replace('storage' . DIRECTORY_SEPARATOR, '', ...)` + `realpath` de fallback — **funciona mas torto**. Recomendação: migration `UPDATE lgpd_request_attachments SET caminho_arquivo = REPLACE(caminho_arquivo, '\\', '/')` após cutover.

---

## 2. Funções/classes chamadas mas ausentes

Comparei FQCN `App\Models\*`, `App\Helpers\*`, `App\Services\*`, `App\Controllers\*` referenciados (73 únicos) contra classes definidas (78). Diff:

- **Todos os 73 nomes referenciados existem.** Os 5 "extras" definidos sem uso direto via FQCN são: `LgpdRequestRetentionJustification`, `LgpdRequestModule`, `LgpdRequestFinding`, `LgpdRequestAttachment` (referenciados via FQCN dentro de outros models, contagem ok), e `DataProcessor` / `Team` / `WhatsAppMessage` (instanciados via outros caminhos).
- **`new ClassName()` raw** (sem namespace): `new AaspProvider`, `new AaspSyncRunner`, `new DjenProvider`, `new EvolutionApiService`, `new NullGateway`, `new PushMonitorRunner`, `new WhatsAppInstance`, `new WhatsAppMessage` — todos com `use` correspondente. **Sem broken refs.**
- 🟢 `new StripeAdapter`, `new MercadoPagoAdapter` aparecem apenas em **comentários** (gateways futuros, ainda não implementados) — sem código quebrado.
- 🟢 Funções globais (`ok()`, `fail()`, `csrfOk()`, `checkParticipant()`) declaradas em múltiplos endpoints `public/api/*.php`. **Não causa colisão**: cada arquivo é entrypoint independente, sem cross-include entre endpoints.

---

## 3. Hardcoded paths / configs

### 3.1 🔴 CRÍTICO — `/sistema_vendas/` hardcoded em 458 ocorrências (65 arquivos)
Padrões encontrados:
- `header('Location: /sistema_vendas/public/login.php')` — em 20+ páginas (`login.php`, `dashboard.php`, `master.php`, etc.).
- `<link rel="stylesheet" href="/sistema_vendas/public/assets/...">` — todos os HTML inline.
- `<script src="/sistema_vendas/public/assets/...">` — idem.
- `const API_BASE = '/sistema_vendas/public/api/';` em JS embutido.
- `RewriteBase /sistema_vendas/` em `.htaccess`.

**Por quê é bloqueador**: em produção AWS o domínio será raiz (ex: `https://app.yuris.com.br/login.php`), não `https://app.yuris.com.br/sistema_vendas/public/login.php`. Sem refactor, **toda navegação quebra** após deploy: redirects 302 levam a 404, CSS/JS não carregam, AJAX falha.

**Recomendação**: substituir por:
1. Constante única em `config/app.php` (`BASE_URL`).
2. Helper `url($path)` que prefixa BASE_URL.
3. `.htaccess` com `RewriteBase` configurável via env.

### 3.2 🟡 `http://localhost:8080` (Evolution API)
- `EvolutionApiService.php:17` e `whatsapp/media.php:77` — fallback se `whatsapp_settings.evolution_base_url` vazio. Documentado, esperado configurar via DB ou env.

### 3.3 🟢 `127.0.0.1` em `config/database.php:13`
- Fallback documentado para dev. Sobrescrito via `DB_HOST` no `.env`. OK.

### 3.4 🟢 `localhost` em comentários e `WebhookUrlValidator.php`
- Validador SSRF que **bloqueia** localhost/127.0.0.0/8 — proteção correta.

### 3.5 🟢 `C:\xampp\...` em comentários (2 arquivos)
- `bin/webhook_worker.php:16-17` e `scripts/test_multitenancy_e2e.php:16` — apenas docblocks. Nada em código executável.

---

## 4. Includes quebrados / arquivos faltando

- 🟠 `public/api/whatsapp/opcache_clear.php:2` — require apontando 4 níveis acima, mas precisa 3 (já reportado em §1.1). Fatal se chamado.
- 🟢 `config/app.php` é referenciado em 3 cron ticks (`lgpd_retention_tick.php`, `push/tick.php`, `tasks_recurrence_tick.php`) mas **não existe**. Tratado com `file_exists()` antes de incluir — é fallback opcional. OK.
- 🟢 Nenhum outro require/include apontando para arquivo inexistente.

---

## 5. Sintaxe (`php -l`) — falhas

`php -l` rodado em **todos os 223 arquivos PHP** (não só os 5 obrigatórios):

```
0 syntax errors detected.
```

Lint passa 100%.

---

## 6. Extensões PHP requeridas

Mapeamento por uso:

| Extensão     | Pacote Ubuntu          | Onde usado                                                          |
|--------------|------------------------|---------------------------------------------------------------------|
| `pdo_mysql`  | `php-mysql`            | `Database.php` + todos os Models (PDO + MariaDB)                     |
| `mbstring`   | `php-mbstring`         | 20 arquivos (`mb_strtolower`, `mb_strlen`, `mb_substr`, etc.)        |
| `curl`       | `php-curl`             | `EvolutionApiService`, `DjenProvider`, `AaspProvider`, `retention.php`, `whatsapp/media.php` |
| `openssl`    | (built-in, geralmente) | `Crypto.php`, `TotpHelper.php`, `PIIMasker.php`, etc. (19 arquivos)  |
| `hash`       | (built-in)             | `hash_file`, `hash_hmac`, `hash_equals` — webhooks, anexos LGPD     |
| `fileinfo`   | `php-fileinfo` (built-in no PHP 8) | `finfo()` em uploads (LGPD, tasks, whatsapp, master)      |
| `zip`        | `php-zip`              | `ZipArchive` em `Anonymizer.php` (export portabilidade LGPD)         |
| `json`       | (built-in PHP 8)       | onipresente                                                          |
| `session`    | (built-in)             | login/auth                                                           |
| `opcache`    | (built-in / `php-opcache`) | `opcache_reset`, `opcache_invalidate` (uso opcional)             |
| `pdo`        | (built-in)             | core                                                                 |
| `simplexml` / `dom` | `php-xml`         | não encontrei uso direto — pode ser desnecessário, mas inclui por segurança |

**Não usado** (verificado e ausente): GD/imagick, bcmath, gmp, ldap, imap, soap, intl, gettext, sodium, redis, memcached, apcu.

**Comando Ubuntu sugerido** (PHP 8.2 LTS):
```bash
sudo apt install php8.2 php8.2-fpm php8.2-mysql php8.2-mbstring \
                 php8.2-curl php8.2-zip php8.2-xml php8.2-opcache \
                 php8.2-intl libapache2-mod-php8.2
```
(`fileinfo`, `openssl`, `hash`, `json`, `session`, `pdo` vêm built-in.)

---

## 7. Cron jobs e schedule sugerido

| Arquivo                                          | Propósito                                      | Frequência sugerida | Auth        |
|--------------------------------------------------|------------------------------------------------|---------------------|-------------|
| `bin/webhook_worker.php`                         | Consumidor da fila `webhook_deliveries`        | a cada 1 min        | CLI only    |
| `public/api/tasks_recurrence_tick.php`           | Cria instâncias de tarefas recorrentes + lembretes WhatsApp/sistema | diário 00:00 | `?token=<CRON_TOKEN>` |
| `public/api/lgpd_retention_tick.php`             | Purga/anonimiza dados antigos (LGPD)           | diário 00:30        | `?token=<CRON_TOKEN>` |
| `public/api/push/tick.php`                       | Sincroniza DJEN/AASP (intimações)              | a cada 10 min       | `?token=<CRON_TOKEN>` |
| `app/Services/RecurrenceCronService.php` (piggyback) | Disparado em GET /api/tasks.php — roda 1× por hora via lock file | passivo (não cron real) | sessão Apache |
| `app/Services/Mailer::processQueue()`            | Processa `emails_outbox` — **sem cron criado ainda** | a cada 5 min (criar) | — |

**Crontab Ubuntu sugerido** (rodando como `www-data` ou usuário dedicado):
```cron
# webhook deliveries — 1 batch por minuto
* * * * * /usr/bin/php /var/www/yuris/bin/webhook_worker.php >/dev/null 2>&1

# Intimações (DJEN+AASP) — a cada 10 minutos
*/10 * * * * curl -fsS "https://app.yuris.com.br/api/push/tick.php?token=$CRON_TOKEN" >/dev/null

# Tarefas recorrentes + lembretes — diário 00:00
0 0 * * * curl -fsS "https://app.yuris.com.br/api/tasks_recurrence_tick.php?token=$CRON_TOKEN" >/dev/null

# LGPD retention — diário 00:30
30 0 * * * curl -fsS "https://app.yuris.com.br/api/lgpd_retention_tick.php?token=$CRON_TOKEN" >/dev/null

# Mailer queue (criar endpoint primeiro) — a cada 5 minutos
*/5 * * * * curl -fsS "https://app.yuris.com.br/api/mailer_tick.php?token=$CRON_TOKEN" >/dev/null
```

Notas:
- Endpoint-based ticks usam GET autenticado por `CRON_TOKEN` (set em `.env`).
- `webhook_worker.php` é CLI direto (não HTTP). Pode também rodar como `systemd` timer.
- `RecurrenceCronService` (piggyback) **continua funcionando** no Linux, mas é redundante quando há cron real.

---

## 8. Bloqueadores 🔴 para deploy Linux

1. **🔴 `/sistema_vendas/` hardcoded em 458 lugares** — sem refactor, todo redirect e asset quebra ao mover para raiz do domínio. Plano: introduzir `BASE_URL` em `config/app.php` e helper PHP/JS.
2. **🔴 `RewriteBase /sistema_vendas/` no `.htaccess` raiz** — precisa ser ajustado para raiz do vhost (`/`) ou parametrizado.
3. **🔴 Permissões de filesystem** — `storage/`, `storage/lgpd_requests/`, `storage/lgpd_exports/`, `storage/backups/` precisam ser graváveis pelo user do PHP-FPM/Apache (www-data). No XAMPP roda como o user do dev. **Configurar `chown -R www-data:www-data storage/ public/uploads/` + `chmod 750`** no provisionamento.
4. **🟠 `.env` não existe na raiz** — somente `.env.example`. `EnvLoader::validateProduction()` derruba o request com 503 se `APP_ENV=production` e `DB_PASS`/`CRON_TOKEN`/`BILLING_GATEWAY` não estiverem definidos. Preparar `.env` real no deploy.
5. **🟠 `opcache_clear.php` tem require quebrado** (`../../../../app` em vez de `../../../app`) — fatal se acessado em produção (rota privilegiada).

(Os "🔴 Permissões" + "🔴 RewriteBase" + "🔴 /sistema_vendas/" são todos do mesmo conjunto de problemas de "URL/file path base"; tratados juntos no plano abaixo.)

---

## 9. Plano de correção

### Fase 1 — pré-deploy (no código, antes do cutover)
1. Criar `config/app.php` com `'base_url' => env('BASE_URL', '/sistema_vendas')` (compat com dev).
2. Refactor de `header('Location: /sistema_vendas/public/login.php')` → `header('Location: ' . url('/login.php'))`. ~25 locais.
3. Refactor de `<link>`, `<script>` para usar `<?= asset('css/...') ?>` ou base path PHP-injected. ~150 locais (mas trivialmente sed-able).
4. JS: substituir `const API_BASE = '/sistema_vendas/public/api/'` por valor injetado via `<?= json_encode(...) ?>` no template raiz.
5. `.htaccess` raiz: `RewriteBase` parametrizável (ou simplesmente apagar e configurar via Apache vhost).
6. Corrigir `public/api/whatsapp/opcache_clear.php:2` (path-fix).
7. Padronizar `public/chat.php:1812` para usar `__DIR__`.

### Fase 2 — provisionamento da máquina Ubuntu
1. `apt install` das extensões PHP listadas (§6).
2. Apache 2.4 + `mod_rewrite` + `mod_php8.2` (ou php-fpm).
3. DocumentRoot do vhost apontando para `/var/www/yuris/public/`.
4. `chown -R www-data:www-data` em `storage/`, `public/uploads/`.
5. Criar `.env` real (gerar `CRON_TOKEN`, `MFA_ENCRYPTION_KEY` com `openssl rand`).
6. Migrar DB (atual: 50+ migrations em `database/migrations/`).
7. **Migration de paths**: `UPDATE lgpd_request_attachments SET caminho_arquivo = REPLACE(caminho_arquivo, '\\\\', '/')` se houver dados existentes.

### Fase 3 — crontab + workers (§7)
1. Configurar 5 entries de cron (webhook_worker + 3 ticks + mailer quando existir).
2. (Opcional) systemd timers em vez de cron para webhook_worker (melhor observabilidade).

### Fase 4 — pós-deploy
1. `APP_ENV=production` no `.env` para ativar `EnvLoader::validateProduction()`.
2. Implementar `Mailer` driver SMTP (hoje só `log`).
3. Implementar `StripeAdapter` ou `MercadoPagoAdapter` (`BILLING_GATEWAY` real).
4. Smoke test: login → dashboard → criar tarefa → upload anexo LGPD → executar cron tick manualmente.

---

## Sumário de achados por gravidade

| Gravidade | Quantidade |
|-----------|------------|
| 🔴 Bloqueador | 3 conjuntos (todos relacionados a base URL/permissões) |
| 🟠 Sério (não-bloqueador) | 3 (opcache_clear path, .env ausente, paths Windows no DB) |
| 🟡 Atenção | 2 (chat.php sem __DIR__, Evolution localhost fallback) |
| 🟢 OK / esperado | resto |

**Resposta "Linux-ready?": Com ressalvas.**

O **código PHP em si está extremamente bem preparado** para Linux: zero backslashes em requires, zero case mismatches, zero paths absolutos Windows em código executável, zero syntax errors em 223 arquivos, todas as classes referenciadas existem, uso correto de `DIRECTORY_SEPARATOR` e `__DIR__`.

O bloqueador é **arquitetural** (URL base hardcoded `/sistema_vendas/`), não Linux-específico — mesmo migrando entre dois XAMPP em pastas diferentes quebraria. Resolvendo isso + configurando permissões de filesystem + criando `.env` produção, o sistema sobe limpo em Ubuntu/Apache/PHP 8.2.
