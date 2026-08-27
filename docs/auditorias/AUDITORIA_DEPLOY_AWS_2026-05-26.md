# Auditoria Deploy AWS Ubuntu — Yuris (2026-05-26)

> Stack alvo: **EC2 Ubuntu 22.04 LTS + Apache 2.4 + PHP 8.2 + MariaDB 10.6/10.11**
> Stack atual: XAMPP (Win11) + PHP 8.2 + MariaDB 10.4.32 + DB `sistema_vendas`
> Documento auxiliar para preparar `DEPLOY_AWS_UBUNTU.md`, `DATABASE_SETUP.md`, `ENVIRONMENT.md`, `ROLLBACK.md`.

---

## 1. DocumentRoot estratégia

**Recomendação: opção (a) — servir `public/` direto como DocumentRoot.**

Motivo:
- O `.htaccess` raiz hoje só existe porque XAMPP expõe `htdocs/` inteiro. Em prod isso é code smell — depender de `RedirectMatch 403` pra esconder `app/`, `config/`, `database/` é defesa em profundidade frágil.
- Servir `public/` direto torna fisicamente impossível atingir `app/`, `config/`, `database/`, `bin/`, `scripts/`, `storage/`, `docs/` via HTTP, mesmo com `.htaccess` quebrado.
- Padrão de mercado (Laravel/Symfony/etc).

**O que muda:**
- `APP_URL` deixa de ter `/sistema_vendas/` no path → vira `https://app.yuris.com.br`.
- O `.htaccess` raiz com `RewriteBase /sistema_vendas/` é descartado em prod; só vale dentro de `public/` (deve existir um `public/.htaccess` ou criar).
- Layout no servidor:
  ```
  /var/www/yuris/              ← código (git checkout)
  /var/www/yuris/public/       ← DocumentRoot
  /var/www/yuris/storage/      ← writable, FORA do DocumentRoot
  /var/www/yuris/.env          ← root:www-data 640, FORA do DocumentRoot
  ```

**Ação:** verificar se existe `public/.htaccess` com `RewriteEngine On` + front-controller pra rotas (ou se cada `.php` é endpoint próprio — pelo que listei, é endpoint próprio, então basta `DirectoryIndex index.php` + AllowOverride).

---

## 2. Pacotes apt

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y \
  apache2 \
  mariadb-server mariadb-client \
  php8.2 libapache2-mod-php8.2 \
  php8.2-cli php8.2-common php8.2-curl php8.2-mbstring \
  php8.2-mysql php8.2-xml php8.2-zip php8.2-gd \
  php8.2-bcmath php8.2-intl php8.2-opcache php8.2-readline \
  php8.2-fileinfo \
  certbot python3-certbot-apache \
  unzip git curl ufw fail2ban logrotate \
  msmtp msmtp-mta ca-certificates
sudo a2enmod rewrite headers ssl expires deflate
sudo a2dismod autoindex -f
sudo systemctl restart apache2
```

> PHP 8.2 não vem no repositório padrão do Ubuntu 22.04 (que oferece 8.1). Adicionar Ondrej PPA antes:
> `sudo add-apt-repository ppa:ondrej/php && sudo apt update`.
> Ubuntu 24.04 já traz 8.3 nativo — pode ser opção mais limpa.

Extensões usadas pelo Yuris (baseado em uso típico do código): `pdo_mysql`, `mbstring`, `openssl`, `curl`, `json`, `fileinfo` (uploads), `zip` (exports LGPD), `gd` (imagens), `intl`, `bcmath`, `opcache`. `json` e `openssl` são built-in no PHP 8.

---

## 3. VirtualHost Apache (`/etc/apache2/sites-available/yuris.conf`)

```apache
<VirtualHost *:80>
    ServerName app.yuris.com.br
    ServerAlias www.app.yuris.com.br
    Redirect permanent / https://app.yuris.com.br/
</VirtualHost>

<VirtualHost *:443>
    ServerName app.yuris.com.br
    DocumentRoot /var/www/yuris/public

    <Directory /var/www/yuris/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Garante que dirs sensíveis NUNCA sirvam, mesmo via symlink/bug
    <DirectoryMatch "^/var/www/yuris/(app|config|database|bin|scripts|storage|docs)">
        Require all denied
    </DirectoryMatch>

    # Headers de segurança (complementa LGPD checklist)
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"

    SSLEngine on
    SSLCertificateFile      /etc/letsencrypt/live/app.yuris.com.br/fullchain.pem
    SSLCertificateKeyFile   /etc/letsencrypt/live/app.yuris.com.br/privkey.pem
    SSLProtocol             -all +TLSv1.2 +TLSv1.3
    SSLHonorCipherOrder     on

    ErrorLog  /var/log/apache2/yuris-error.log
    CustomLog /var/log/apache2/yuris-access.log combined
    LogLevel warn
</VirtualHost>
```

Ativar: `sudo a2ensite yuris && sudo a2dissite 000-default && sudo systemctl reload apache2`.

---

## 4. MariaDB setup (comandos exatos)

```bash
sudo mysql_secure_installation   # rotaciona root, remove anônimo, test DB

sudo mysql <<'SQL'
CREATE DATABASE yuris CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'yuris_app'@'localhost' IDENTIFIED BY '__TROCAR_SENHA_FORTE__';
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE, SHOW VIEW
  ON yuris.* TO 'yuris_app'@'localhost';
-- NÃO conceder: GRANT, DROP, ALTER USER, CREATE USER, SUPER, FILE.
-- TRIGGER/REFERENCES/CREATE são usados só em migrations -> rodar como root.
FLUSH PRIVILEGES;
SQL

# Importar schema base
sudo mysql yuris < /var/www/yuris/database/schema.sql

# Aplicar migrations 001 → 070 em ordem alfabética
cd /var/www/yuris
for f in $(ls database/migrations/*.sql | sort); do
  echo "→ aplicando $f"
  sudo mysql yuris < "$f" || { echo "FALHA em $f"; exit 1; }
done

# Seed admin inicial
sudo -u www-data php /var/www/yuris/scripts/seed_admin.php
```

> Atenção: existem DUAS migrations com prefixo `067_*` (`067_advogado_vinculos.sql` e `067_rename_webhooks_to_endpoints.sql`). `sort` lex vai aplicar `advogado_vinculos` primeiro. **Confirmar dependência** antes do go-live ou renumerar uma delas.

---

## 5. Permissões de pasta

```bash
sudo chown -R root:www-data /var/www/yuris
sudo find /var/www/yuris -type d -exec chmod 750 {} \;
sudo find /var/www/yuris -type f -exec chmod 640 {} \;

# Writable pelo Apache:
sudo chown -R www-data:www-data \
  /var/www/yuris/storage \
  /var/www/yuris/storage/backups \
  /var/www/yuris/storage/lgpd_exports \
  /var/www/yuris/storage/lgpd_requests \
  /var/www/yuris/public/uploads
sudo chmod -R 770 /var/www/yuris/storage /var/www/yuris/public/uploads

# .env
sudo chown root:www-data /var/www/yuris/.env
sudo chmod 640 /var/www/yuris/.env

# Sessões PHP
sudo mkdir -p /var/lib/php/sessions
sudo chown www-data:www-data /var/lib/php/sessions
sudo chmod 770 /var/lib/php/sessions
```

---

## 6. Cron jobs

Confirmados no repo: `bin/webhook_worker.php`, `public/api/lgpd_retention_tick.php`, `public/api/tasks_recurrence_tick.php`, `public/api/push/tick.php`, `public/api/aasp/sync.php`, `public/api/whatsapp/sync.php`, `public/api/master/retention.php`.

`/etc/cron.d/yuris`:
```
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
MAILTO=ops@yuris.com.br

# LGPD retention (diário 03:10)
10 3 * * * www-data /usr/bin/php /var/www/yuris/public/api/lgpd_retention_tick.php >> /var/log/yuris/lgpd_retention.log 2>&1

# Tarefas recorrentes (a cada 15 min)
*/15 * * * * www-data /usr/bin/php /var/www/yuris/public/api/tasks_recurrence_tick.php >> /var/log/yuris/tasks_tick.log 2>&1

# Push notifications tick (a cada 5 min)
*/5 * * * * www-data /usr/bin/php /var/www/yuris/public/api/push/tick.php >> /var/log/yuris/push_tick.log 2>&1

# AASP sync (a cada 30 min)
*/30 * * * * www-data /usr/bin/php /var/www/yuris/public/api/aasp/sync.php >> /var/log/yuris/aasp_sync.log 2>&1

# Webhook worker (a cada minuto, lock impede sobreposição)
* * * * * www-data /usr/bin/php /var/www/yuris/bin/webhook_worker.php >> /var/log/yuris/webhook_worker.log 2>&1
```

Criar `/var/log/yuris/` com `www-data` owner + logrotate diário.

> Decisão: usar CLI (`php /var/www/yuris/public/api/...`) em vez de `curl` localhost. Não precisa do `CRON_TOKEN` header e evita expor endpoints ticks na web. **Verificar se os scripts detectam `php_sapi_name()==='cli'` e pulam validação de token** — se não, precisa setar env var `CRON_TOKEN=...` no crontab.

---

## 7. SSL + Certbot

```bash
sudo certbot --apache -d app.yuris.com.br -d www.app.yuris.com.br \
  --email ops@yuris.com.br --agree-tos --redirect --no-eff-email
sudo systemctl status certbot.timer   # renovação automática
sudo certbot renew --dry-run
```

---

## 8. AWS Security Group

| Porta | Protocolo | Origem               | Uso                    |
|-------|-----------|----------------------|------------------------|
| 22    | TCP       | `<IP-admin>/32`      | SSH (restrito)         |
| 80    | TCP       | `0.0.0.0/0`          | HTTP (redirect → 443)  |
| 443   | TCP       | `0.0.0.0/0`          | HTTPS                  |
| 3306  | —         | —                    | **BLOQUEADO** (DB local)|

Egress: `0.0.0.0/0` (chamadas a Evolution, AASP, DataJud, gateways).
Recomendado: **AWS Systems Manager Session Manager** em vez de SSH 22 aberto.

---

## 9. .env por criticidade

| Var | Crit | Notas |
|-----|------|-------|
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | 🔴 | Sem isso, nada conecta. |
| `DB_CHARSET` | 🟡 | Default `utf8mb4`. |
| `APP_ENV=production` | 🔴 | Destrava validações fail-closed. |
| `APP_DEBUG=false` | 🔴 | `true` expõe stack traces. |
| `APP_URL` | 🔴 | Usado em links de e-mail, redirects OAuth. |
| `CRON_TOKEN` | 🔴 | ≥32 chars aleatórios. |
| `MFA_ENCRYPTION_KEY` | 🔴 | Obrigatória se super_admin tem MFA. `openssl rand -base64 32`. |
| `APP_ENCRYPTION_KEY` | 🔴 | Fail-closed se AASP tiver linhas. |
| `BILLING_GATEWAY` | 🔴 | NÃO pode ser `null` em produção. |
| Credenciais do gateway escolhido | 🔴 | Stripe/MP/Asaas — chaves prod. |
| `EVOLUTION_TLS_VERIFY=true` | 🔴 | LGPD P1. |
| `EVOLUTION_BASE_URL`, `EVOLUTION_API_KEY`, `EVOLUTION_INSTANCE` | 🟠 | WhatsApp inoperante sem. |
| `DPO_NAME`, `DPO_EMAIL`, `DPO_PHONE`, `DPO_ADDRESS` | 🔴 | LGPD — `/dpo.php` exibe. |
| `DJEN_BASE_URL` | 🟠 | Intimações desligam se vazio. |
| `DATAJUD_API_KEY`, `DATAJUD_BASE_URL` | 🟡 | Enriquecimento; sem chave funciona. |
| `AASP_BASE_URL`, `AASP_RATE_LIMIT_MS` | 🟠 | Módulo AASP. |

---

## 10. ROLLBACK plano (skeleton p/ `ROLLBACK.md`)

**Pré-deploy:** snapshot EBS + `mysqldump --single-transaction yuris > /var/backups/yuris/pre-$(date +%F).sql.gz`. Tag git `pre-release-YYYYMMDD`.

**Rollback código:**
```bash
cd /var/www/yuris && sudo -u www-data git fetch --tags
sudo -u www-data git checkout pre-release-YYYYMMDD
sudo systemctl reload apache2
```

**Rollback banco:**
```bash
sudo systemctl stop apache2
sudo mysql -e "DROP DATABASE yuris; CREATE DATABASE yuris CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
zcat /var/backups/yuris/pre-AAAA-MM-DD.sql.gz | sudo mysql yuris
sudo systemctl start apache2
```

**Critérios pra rolar back:** 5xx >1%/5min, login quebrado, cron falhando >2 ciclos, perda de integridade auditável.
**Janela:** decidir em ≤15min após detecção; executar em ≤30min.

---

## 11. Checklist pós-deploy (30 itens)

1. `curl -I https://app.yuris.com.br` → 200/302.
2. `curl -I http://app.yuris.com.br` → 301 https.
3. `/index.php` (landing) carrega.
4. `/login.php` GET + POST com user válido → 302 dashboard.
5. Logout limpa sessão.
6. `/dashboard.php` renderiza sem PHP warning.
7. CSS/JS de `assets/` 200.
8. Upload em `public/uploads/` grava + lê.
9. `storage/login_debug.log` recebe linha após login.
10. `/api/tasks.php?action=list` autenticada retorna JSON.
11. Webhook worker roda 1 ciclo (verificar log).
12. `lgpd_retention_tick.php` manual → exit 0.
13. `tasks_recurrence_tick.php` manual → exit 0.
14. `/dpo.php` exibe dados do `.env`.
15. `/privacidade.php`, `/cookies.php`, `/lgpd.php` 200.
16. Banner de cookies aparece em incógnito.
17. `/master_login.php` 200 + bloqueia IP fora allowlist (se config).
18. `mysql -u yuris_app -p` conecta; `GRANT GRANT` falha (permissão mínima).
19. `SHOW TRIGGERS FROM yuris` → 20 triggers `trg_*_no_*`.
20. `mysql -e "SELECT COUNT(*) FROM users WHERE role='super_admin'"` ≥1.
21. `php -r "var_dump(extension_loaded('pdo_mysql'),extension_loaded('mbstring'),extension_loaded('openssl'),extension_loaded('curl'),extension_loaded('fileinfo'));"` todos true.
22. `php -i | grep opcache.enable` = On.
23. `php -i | grep session.cookie_secure` = 1.
24. `php -i | grep display_errors` = Off.
25. Headers HSTS/X-Frame/X-Content via `curl -I`.
26. `https://app.yuris.com.br/.env` → 404/403 (não 200).
27. `https://app.yuris.com.br/app/` → 403.
28. `https://app.yuris.com.br/storage/` → 403.
29. `certbot certificates` mostra validade > 60d.
30. Disco `df -h /` < 70%, RAM `free -m` ≥30% livre.

---

## 12. Bloqueadores Windows → Linux

- **Usuário de serviço:** XAMPP roda como usuário Windows; Apache Ubuntu = `www-data`. Todos os arquivos que o app escreve (`storage/`, `uploads/`, `sessions`) precisam ter `www-data` como owner ou grupo. Senão: `Permission denied` em uploads, sessions, logs.
- **mod_rewrite:** no XAMPP vem habilitado por padrão; no Ubuntu **precisa** `sudo a2enmod rewrite`. Sem isso, `.htaccess` rewrite vira no-op e endpoints quebram.
- **AllowOverride:** Ubuntu padrão é `None` em `/var/www/`. `.htaccess` é ignorado se não trocar pra `All` no `<Directory>` do VHost.
- **PHP-FPM vs mod_php:** **Recomendo mod_php (`libapache2-mod-php8.2`) inicial** — simples, sem socket extra, sem `proxy_fcgi`. Carga atual (multitenancy pequeno-médio) não justifica complexidade do FPM. Migrar pra FPM só se NGINX entrar OU `prefork` virar gargalo.
- **`session.save_path`:** `/var/lib/php/sessions` precisa existir e ser writable pelo `www-data`. Pacote `php8.2` cria por padrão, mas confirmar `php -i | grep session.save_path` e `ls -ld` no path. Sessões "somem" silenciosamente se path errado.
- **Case-sensitivity:** Windows trata `Helper.php` e `helper.php` como o mesmo arquivo; Linux NÃO. `\App\Helpers\*` em `app/Helpers/` precisa ter case exato — `require_once __DIR__ . '/../Helpers/foo.php'` vs `Foo.php` quebra em Linux. **Auditoria de código separada deve checar isso.**
- **Line endings:** scripts shell de `scripts/` ou `bin/` em CRLF (Windows) quebram no shebang. `dos2unix bin/*.php scripts/*.sh` antes.
- **Caminhos hardcoded:** `C:\xampp\htdocs\sistema_vendas` em qualquer log/config/include é bomba. Buscar e remover.
- **`import_db.bat`:** script Windows-only, descartar em prod, substituir pelo loop bash da §4.
- **MariaDB 10.4 → 10.6+:** sintaxe de TRIGGER/JSON pode mudar. Testar restore do dump local em staging Ubuntu antes do go-live.
- **Charset cliente:** `mysql` CLI no Ubuntu usa charset do locale. Setar `default-character-set=utf8mb4` em `/etc/mysql/mariadb.conf.d/50-client.cnf` pra evitar mojibake em imports.
- **Timezone:** `date.timezone=America/Sao_Paulo` em `/etc/php/8.2/apache2/php.ini` E `/etc/php/8.2/cli/php.ini` (cron usa o CLI). XAMPP herdava do Windows.
- **`msmtp` (sendmail):** Linux não tem `mail()` funcional out-of-the-box. `msmtp-mta` configurado com SMTP relay (SES/SendGrid/Mailgun) substitui `sendmail_path` no `php.ini`.

---

## 13. Docs faltando que vou criar na fase de correção

- `docs/DEPLOY_AWS_UBUNTU.md` — runbook step-by-step (1ª instalação + atualizações).
- `docs/DATABASE_SETUP.md` — comandos da §4 expandidos + recipe de restore.
- `docs/ENVIRONMENT.md` — cada var do `.env.example` com semântica, default, exemplo, criticidade.
- `docs/ROLLBACK.md` — expansão da §10 com runbook completo.
- `README.md` — modernização: badges, requisitos, quick-start dev (Docker-compose opcional?), link pros docs acima.
