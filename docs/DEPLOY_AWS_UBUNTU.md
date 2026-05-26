# Deploy AWS EC2 Ubuntu 22.04 LTS — Runbook Yuris

**Versão:** 1.0 — 2026-05-26
**Audiência:** dev intermediário que vai clonar o repo e subir o servidor a primeira vez.
**Estimativa total:** 3-5 horas (excluindo propagação DNS + SSL).

---

## Sumário

1. [Provisionar EC2](#1-provisionar-ec2)
2. [Pacotes apt](#2-pacotes-apt)
3. [Configurar Apache](#3-configurar-apache)
4. [Configurar MariaDB](#4-configurar-mariadb)
5. [Clonar repo](#5-clonar-repo)
6. [Criar `.env` de produção](#6-criar-env-de-produção)
7. [Importar banco](#7-importar-banco)
8. [Criar admin inicial](#8-criar-admin-inicial)
9. [Ajustar permissões](#9-ajustar-permissões)
10. [Configurar cron](#10-configurar-cron)
11. [SSL via Let's Encrypt](#11-ssl-via-lets-encrypt)
12. [Estratégia de URL base](#12-estratégia-de-url-base)
13. [Smoke test pós-deploy](#13-smoke-test-pós-deploy)
14. [Troubleshooting](#14-troubleshooting)

---

## 1. Provisionar EC2

| Parâmetro | Valor mínimo | Recomendado |
|---|---|---|
| AMI | Ubuntu Server 22.04 LTS | idem |
| Instance type | `t3.small` (2 GB RAM) | `t3.medium` (4 GB RAM) |
| Storage | 30 GB gp3 | 50 GB gp3 |
| Region | `us-east-1` (latência ok pro BR) | `sa-east-1` (São Paulo) |
| Key pair | Crie um e guarde o `.pem` | idem |

### Security Group

```
Inbound:
  22  (SSH)    Só do seu IP: <SEU-IP>/32
  80  (HTTP)   0.0.0.0/0 (redirect → 443)
  443 (HTTPS) 0.0.0.0/0
Outbound:
  Todos
```

> ⚠️ **Nunca** abra MariaDB (3306) para `0.0.0.0/0`. O banco fica `localhost`-only.

### Elastic IP

Associe um Elastic IP à instância — sem ele, o IP muda a cada stop/start e quebra DNS/SSL.

### DNS

Aponte seu domínio (Route53, Cloudflare, Registro.br) para o Elastic IP via registro A.

---

## 2. Pacotes apt

```bash
# Conectar via SSH
ssh -i sua-chave.pem ubuntu@<elastic-ip>

# Atualizar índice + sistema
sudo apt update
sudo apt -y upgrade

# Pacotes obrigatórios
sudo apt install -y \
    apache2 \
    mariadb-server \
    php8.2 php8.2-mysql php8.2-mbstring php8.2-curl \
    php8.2-zip php8.2-xml php8.2-opcache \
    libapache2-mod-php8.2 \
    git \
    certbot python3-certbot-apache \
    unzip

# Habilitar mods Apache necessários
sudo a2enmod rewrite ssl headers
sudo systemctl restart apache2

# Validar versões
php --version    # 8.2.x
mysql --version  # 10.6.x (MariaDB)
apache2 -v       # 2.4.x
```

**Extensões PHP que NÃO precisa instalar** (já vem com php8.2-cli ou php8.2-common):
- `openssl`, `hash`, `fileinfo`, `json`, `session`, `pdo`, `tokenizer`

**Extensões opcionais** (instala só se for usar):
- `php8.2-gd` — só se for usar processamento de imagens server-side (no momento Yuris não usa).
- `php8.2-bcmath` — só se for usar cálculos com decimais precisos extra.

---

## 3. Configurar Apache

### Criar VirtualHost

```bash
sudo nano /etc/apache2/sites-available/yuris.conf
```

Conteúdo (substitua `seu-dominio.com`):

```apache
<VirtualHost *:80>
    ServerName seu-dominio.com
    ServerAlias www.seu-dominio.com

    # Redirecionar tudo para HTTPS
    Redirect permanent / https://seu-dominio.com/
</VirtualHost>

<VirtualHost *:443>
    ServerName seu-dominio.com
    ServerAlias www.seu-dominio.com

    DocumentRoot /var/www/yuris/public

    <Directory /var/www/yuris/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Bloqueio explícito das pastas internas (defense-in-depth — cada pasta
    # também tem .htaccess Require all denied)
    <Directory /var/www/yuris/app>      Require all denied </Directory>
    <Directory /var/www/yuris/config>   Require all denied </Directory>
    <Directory /var/www/yuris/database> Require all denied </Directory>
    <Directory /var/www/yuris/bin>      Require all denied </Directory>
    <Directory /var/www/yuris/scripts>  Require all denied </Directory>
    <Directory /var/www/yuris/storage>  Require all denied </Directory>
    <Directory /var/www/yuris/docs>     Require all denied </Directory>

    # Headers de segurança (LGPD + OWASP)
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"

    # Logs
    ErrorLog ${APACHE_LOG_DIR}/yuris-error.log
    CustomLog ${APACHE_LOG_DIR}/yuris-access.log combined

    # SSL — Certbot vai preencher essa parte (ver §11)
</VirtualHost>
```

### Habilitar

```bash
sudo a2dissite 000-default.conf
sudo a2ensite yuris.conf
sudo apache2ctl configtest  # deve dizer "Syntax OK"
sudo systemctl reload apache2
```

### php.ini de produção

```bash
sudo nano /etc/php/8.2/apache2/php.ini
```

Procurar e ajustar:
```ini
display_errors = Off
display_startup_errors = Off
log_errors = On
error_log = /var/log/php_errors.log
expose_php = Off
opcache.enable = 1
opcache.memory_consumption = 128
opcache.max_accelerated_files = 10000
session.cookie_secure = 1
session.cookie_httponly = 1
session.cookie_samesite = Lax
upload_max_filesize = 20M
post_max_size = 25M
max_execution_time = 60
date.timezone = America/Sao_Paulo
```

```bash
sudo systemctl restart apache2
```

---

## 4. Configurar MariaDB

```bash
# Hardening inicial — defina senha root, remova users anônimos, etc.
sudo mysql_secure_installation
# Responda:
#   - Set root password: SIM (anote em vault)
#   - Remove anonymous users: SIM
#   - Disallow root login remotely: SIM
#   - Remove test database: SIM
#   - Reload privilege tables: SIM

# Tunning mínimo
sudo nano /etc/mysql/mariadb.conf.d/50-server.cnf
```

Adicionar/ajustar em `[mysqld]`:
```ini
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
max_connections = 100
innodb_buffer_pool_size = 256M       # ~25% da RAM
innodb_log_file_size = 64M
log_error = /var/log/mysql/error.log
```

```bash
sudo systemctl restart mariadb
```

Detalhes do setup do banco em [docs/DATABASE_SETUP.md](DATABASE_SETUP.md).

---

## 5. Clonar repo

```bash
# Criar dir
sudo mkdir -p /var/www/yuris
sudo chown ubuntu:ubuntu /var/www/yuris

# Clonar (use seu repo)
git clone https://github.com/<seu-org>/sistema_vendas.git /var/www/yuris
cd /var/www/yuris
git checkout master

# Conferir versão
git log -1 --oneline
```

---

## 6. Criar `.env` de produção

```bash
sudo cp .env.example .env
sudo nano .env
```

**Preencher** (mais detalhes em [docs/ENVIRONMENT.md](ENVIRONMENT.md)):

```bash
# ── App ──
APP_ENV=production
APP_DEBUG=false
APP_URL=https://seu-dominio.com

# ── Banco ──
DB_HOST=127.0.0.1
DB_NAME=yuris
DB_USER=yuris_app
DB_PASS=<gerada na §4>

# ── Chaves AES (obrigatórias em prod) ──
# Gerar com:
#   openssl rand -base64 32
# Cada uma DEVE ser única, 32 bytes (base64).
MFA_ENCRYPTION_KEY=base64:<output>
APP_ENCRYPTION_KEY=base64:<output diferente>

# ── Token interno do cron ──
# Gerar com:
#   openssl rand -hex 24
CRON_TOKEN=<output>

# ── Billing (não pode ser null em prod) ──
BILLING_GATEWAY=stripe   # ou mercadopago, asaas
# Adicionar credenciais do gateway escolhido aqui

# ── DPO LGPD ──
DPO_NAME=Dr. <Nome>
DPO_EMAIL=dpo@seu-dominio.com
DPO_PHONE=+55 11 9XXXX-XXXX
DPO_ADDRESS=...

# ── Evolution API (WhatsApp) — opcional ──
EVOLUTION_BASE_URL=https://evolution.seu-dominio.com
EVOLUTION_API_KEY=<key>
EVOLUTION_INSTANCE=yuris-prod
EVOLUTION_TLS_VERIFY=true

# ── AASP (Intimações) — opcional, configurar via UI Painel Master ──
AASP_BASE_URL=https://intimacaoapi.aasp.org.br
AASP_RATE_LIMIT_MS=1000
AASP_MAX_DAYS=30

# ── Mailer transacional ──
MAIL_DRIVER=smtp
MAIL_HOST=email-smtp.us-east-1.amazonaws.com   # se usar AWS SES
MAIL_PORT=587
MAIL_USERNAME=<smtp-user>
MAIL_PASSWORD=<smtp-pass>
MAIL_FROM_ADDRESS=noreply@seu-dominio.com
MAIL_FROM_NAME=Yuris
```

Salvar e proteger:
```bash
sudo chown root:www-data /var/www/yuris/.env
sudo chmod 640 /var/www/yuris/.env
```

---

## 7. Importar banco

Ver [docs/DATABASE_SETUP.md](DATABASE_SETUP.md). Resumo:

```bash
# Criar database + user da app
sudo mysql -u root -p <<'EOF'
CREATE DATABASE yuris CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'yuris_app'@'localhost' IDENTIFIED BY '<DB_PASS>';
GRANT SELECT, INSERT, UPDATE, DELETE ON yuris.* TO 'yuris_app'@'localhost';
FLUSH PRIVILEGES;
EOF

# Importar schema (já contém triggers + procedures de todas as 70 migrations)
cd /var/www/yuris
sudo mysql -u root -p yuris < database/schema.sql

# Aplicar seeds essenciais
sudo mysql -u root -p yuris < database/seeds/seed_demo.sql   # planos + colunas iniciais
sudo php database/seed_webhook_events.php                    # catálogo de eventos webhook

# Validar
sudo mysql -u root -p yuris -e "SHOW TABLES;" | wc -l   # ~92 + header
sudo mysql -u root -p yuris -e "
SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA='yuris' AND TRIGGER_NAME LIKE 'trg_%_no_%';
" | wc -l   # 20+ triggers de imutabilidade LGPD
```

---

## 8. Criar admin inicial

```bash
cd /var/www/yuris
sudo php scripts/seed_admin.php --account-name="<Sua Empresa>" --account-email=admin@seu-dominio.com
# Anote o login e senha aleatória impressos. Não ficam salvos em texto.
```

---

## 9. Ajustar permissões

```bash
# Owner: ubuntu (dev). Group: www-data (Apache). Senha www-data não tem.
sudo chown -R ubuntu:www-data /var/www/yuris

# Pastas writable pelo Apache: storage/, public/uploads/
sudo chmod -R 750 /var/www/yuris/storage
sudo chmod -R 750 /var/www/yuris/public/uploads
sudo find /var/www/yuris/storage -type d -exec chmod g+s {} \;   # SGID pra herdar grupo
sudo find /var/www/yuris/public/uploads -type d -exec chmod g+s {} \;

# Pasta de exports LGPD precisa existir + writable
sudo mkdir -p /var/www/yuris/storage/lgpd_exports
sudo mkdir -p /var/www/yuris/storage/lgpd_requests
sudo mkdir -p /var/www/yuris/storage/backups
sudo chown -R ubuntu:www-data /var/www/yuris/storage

# .env continua 640 (vista de §6)
ls -la /var/www/yuris/.env  # deve ser -rw-r----- root www-data
```

---

## 10. Configurar cron

```bash
sudo nano /etc/cron.d/yuris
```

Conteúdo:
```
# ── Crons do Yuris (rodam via CLI direto, sem CRON_TOKEN HTTP) ──
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

# Worker async de webhooks (1x/min, com lock interno)
* * * * * www-data /usr/bin/php /var/www/yuris/bin/webhook_worker.php >> /var/log/yuris-webhook.log 2>&1

# Recorrências de tarefas (1x/dia à meia-noite)
0 0 * * * www-data /usr/bin/php /var/www/yuris/public/api/tasks_recurrence_tick.php >> /var/log/yuris-tasks.log 2>&1

# Intimações push (DJEN/AASP) — a cada 10 min
*/10 * * * * www-data /usr/bin/php /var/www/yuris/public/api/push/tick.php >> /var/log/yuris-push.log 2>&1

# Retenção LGPD (purge/anonimização) — diário à 00:30
30 0 * * * www-data /usr/bin/php /var/www/yuris/public/api/lgpd_retention_tick.php >> /var/log/yuris-lgpd.log 2>&1
```

```bash
sudo chmod 644 /etc/cron.d/yuris
sudo systemctl restart cron
```

Validar:
```bash
# Forçar uma execução manual
sudo -u www-data php /var/www/yuris/public/api/lgpd_retention_tick.php

# Ver log na próxima execução agendada
tail -f /var/log/yuris-lgpd.log
```

> ✅ **Importante:** os ticks têm bypass CLI — quando rodados via `php tick.php` (não HTTP), pulam a checagem de CRON_TOKEN. Isso é seguro pois quem executa via shell já tem acesso ao servidor.

---

## 11. SSL via Let's Encrypt

```bash
sudo certbot --apache -d seu-dominio.com -d www.seu-dominio.com
# Siga o wizard:
#   - E-mail: dpo@seu-dominio.com (ou outro)
#   - Aceite ToS
#   - Aceite redirect HTTP→HTTPS (recomendado)

# Validar renovação automática
sudo certbot renew --dry-run
```

Certbot edita `/etc/apache2/sites-available/yuris.conf` adicionando o bloco `:443` com cert. Renovação automática rola via `/etc/cron.d/certbot` (já vem instalado).

---

## 12. Estratégia de URL base

O código atual tem `/sistema_vendas/` hardcoded em 458 ocorrências (legacy de XAMPP). Auditoria 2026-05-26 documentou. Você tem 3 opções:

### Opção A — Apache Alias (recomendada — zero código)
Adicione no VirtualHost:
```apache
Alias /sistema_vendas /var/www/yuris/public
```
Resultado: URLs ficam `https://seu-dominio.com/sistema_vendas/login.php` (ugly mas funcional).

### Opção B — Custom URL via DocumentRoot
Configure DocumentRoot=`/var/www/yuris/public` (já está nas instruções acima) e edite o `.htaccess` raiz pra remover `RewriteBase /sistema_vendas/`. URLs ficam `https://seu-dominio.com/login.php` — mas alguns links internos podem quebrar até que refatoração via `App\Helpers\Url::base()` seja completa.

### Opção C — Refatoração completa
Substituir todos os 458 hits de `/sistema_vendas/` por `<?= \App\Helpers\Url::base() ?>/...` (PHP) e `window.APP_BASE` (JS). Estimativa: 4-6h. Item recomendado pra sprint pós-deploy.

> 💡 **Pra primeiro deploy, use Opção A.** Plante DNS rapidamente e refatore depois.

---

## 13. Smoke test pós-deploy

### Lista de 30 itens — execute na ordem

| # | Item | OK? |
|---|---|---|
| 1 | `https://seu-dominio.com/` carrega a landing | ☐ |
| 2 | Botão "Entrar" leva a `/login.php` (ou `/sistema_vendas/public/login.php`) | ☐ |
| 3 | Login com user admin do `seed_admin.php` funciona | ☐ |
| 4 | Dashboard carrega sem erro 500 | ☐ |
| 5 | Sidebar mostra menu completo | ☐ |
| 6 | Aba Prospecção (Cards) carrega | ☐ |
| 7 | Criar card novo funciona | ☐ |
| 8 | Mover card entre colunas (drag-and-drop) funciona | ☐ |
| 9 | Aba Jurídico (Processos) carrega | ☐ |
| 10 | Criar processo manual funciona | ☐ |
| 11 | Aba Tarefas carrega | ☐ |
| 12 | Criar tarefa funciona | ☐ |
| 13 | Aba Finanças (DRE) carrega | ☐ |
| 14 | Aba Intimações carrega (mesmo sem AASP, deve abrir) | ☐ |
| 15 | Aba WhatsApp carrega (mesmo sem Evolution, deve abrir) | ☐ |
| 16 | Aba Usuários carrega (só admin) | ☐ |
| 17 | Aba Escritórios carrega | ☐ |
| 18 | Aba Configurações carrega | ☐ |
| 19 | Logout funciona e redireciona pra `/login.php` | ☐ |
| 20 | `https://seu-dominio.com/master_login.php` carrega (portal isolado) | ☐ |
| 21 | Login master funciona | ☐ |
| 22 | Painel Master mostra dashboard com gráficos | ☐ |
| 23 | Tentar acessar `/database/schema.sql` retorna 403 | ☐ |
| 24 | Tentar acessar `/app/Models/Database.php` retorna 403 | ☐ |
| 25 | Tentar acessar `/scripts/seed_admin.php` retorna 403 | ☐ |
| 26 | Tentar acessar `/docs/CHECKLIST_DEPLOY_PRODUCAO.md` retorna 403 | ☐ |
| 27 | Tentar acessar `/.env` retorna 403 | ☐ |
| 28 | `tail /var/log/apache2/yuris-error.log` sem erros nas últimas 1h | ☐ |
| 29 | `sudo -u www-data php /var/www/yuris/public/api/lgpd_retention_tick.php` retorna JSON OK | ☐ |
| 30 | Página de privacidade `/privacidade.php` carrega com nome real do DPO | ☐ |

Se qualquer um falhar, ver §14 troubleshooting.

---

## 14. Troubleshooting

### "503 — CRON_TOKEN não configurado"
Falta `CRON_TOKEN` no `.env` OU não fez `EnvLoader::load()` ainda. Verifique:
```bash
grep CRON_TOKEN /var/www/yuris/.env
sudo -u www-data php -r "require '/var/www/yuris/app/Helpers/EnvLoader.php'; \App\Helpers\EnvLoader::load(); var_dump(\App\Helpers\EnvLoader::get('CRON_TOKEN'));"
```

### "Erro 500 ao logar"
99% das vezes: extensão PHP faltando OU permissão de `storage/`. Veja:
```bash
sudo tail -100 /var/log/apache2/yuris-error.log
sudo tail -100 /var/log/php_errors.log
```

### Banco recusa conexão
```bash
sudo systemctl status mariadb
sudo mysql -u yuris_app -p -e "SELECT 1"   # testa user/senha
```

### Triggers LGPD não estão lá
```bash
sudo mysql -u root -p yuris -e "SHOW TRIGGERS;" | wc -l
# Deve retornar 20+. Se vier vazio, schema.sql foi importado errado.
# Reimport: drop database yuris; create database yuris; mysql yuris < schema.sql
```

### Página retorna 404 mas arquivo existe
mod_rewrite não habilitado:
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### Sessão não persiste após login
`session.save_path` não existe ou sem permissão:
```bash
ls -la /var/lib/php/sessions/
sudo chown www-data:www-data /var/lib/php/sessions
sudo chmod 1733 /var/lib/php/sessions
```

### Webhooks não disparam
`webhook_worker.php` não está rodando. Verifique:
```bash
sudo tail /var/log/yuris-webhook.log
# Se vazio: confira /etc/cron.d/yuris está lá e cron está ativo
sudo systemctl status cron
```

---

## Anexos

- [DATABASE_SETUP.md](DATABASE_SETUP.md) — setup detalhado do MariaDB
- [ENVIRONMENT.md](ENVIRONMENT.md) — cada variável do `.env` explicada
- [ROLLBACK.md](ROLLBACK.md) — voltar pra commit anterior, restore de banco
- [CHECKLIST_DEPLOY_PRODUCAO.md](CHECKLIST_DEPLOY_PRODUCAO.md) — sign-off LGPD/segurança antes do go-live
- [AUDITORIA_FINAL_2026-05-26.md](AUDITORIA_FINAL_2026-05-26.md) — relatório de auditoria + bloqueadores
