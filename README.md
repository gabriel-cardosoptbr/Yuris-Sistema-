# Yuris — CRM Jurídico Inteligente

Sistema SaaS multi-tenant para escritórios de advocacia. Pipeline de leads, gestão de processos, intimações automatizadas (DJEN/AASP), WhatsApp multi-tenant, financeiro/DRE, painel master administrativo, conformidade LGPD completa.

**Status:** sistema maduro (174 commits, 70 migrations, 12 etapas LGPD). Stack: PHP 8.2 + MariaDB 10.4+ + Apache 2.4.

---

## Sumário

- [Stack e requisitos](#stack-e-requisitos)
- [Setup local (XAMPP — Windows/Mac/Linux)](#setup-local-xampp)
- [Setup em servidor Linux/Ubuntu (produção)](#setup-em-servidor-linuxubuntu-produção)
- [Configuração do .env](#configuração-do-env)
- [Banco de dados (zero-to-running)](#banco-de-dados-zero-to-running)
- [Login inicial](#login-inicial)
- [Estrutura de pastas](#estrutura-de-pastas)
- [Comandos úteis](#comandos-úteis)
- [Documentação técnica](#documentação-técnica)
- [Licença](#licença)

---

## Stack e requisitos

| Componente | Versão mínima | Recomendado |
|---|---|---|
| PHP | 8.0 | **8.2** |
| MariaDB | 10.4 | 10.6 LTS |
| Apache | 2.4 | 2.4 + mod_rewrite + mod_ssl + mod_headers |
| Extensões PHP | `pdo_mysql`, `mbstring`, `curl`, `openssl`, `fileinfo`, `zip`, `xml`, `opcache`, `hash`, `json`, `session` |

**Não usa Composer.** Autoload manual via `require_once`. Namespace `App\Helpers\*`, `App\Models\*`, `App\Services\*`.

**Não usa npm/node.** JavaScript é vanilla (sem build step).

**Dependências externas opcionais** (cada uma degrada graciosamente se ausente):
- **Evolution API** (WhatsApp) — self-hosted, ver [docs/INTEGRACAO_AASP.md](docs/INTEGRACAO_AASP.md) e [docs/DOCUMENTACAO_WEBHOOKS_YURIS.md](docs/DOCUMENTACAO_WEBHOOKS_YURIS.md)
- **DJEN/PJE** (intimações públicas) — endpoint público sem auth
- **AASP** (intimações premium) — exige chave por associado, cifrada at-rest
- **Stripe/MercadoPago/Asaas** (billing) — gateway escolhido em `BILLING_GATEWAY` no `.env`

---

## Setup local (XAMPP)

Caminho mais rápido pra dev em Windows. Linux/Mac com XAMPP funciona igual.

```bash
# 1. Clone em htdocs
cd C:/xampp/htdocs
git clone https://github.com/<seu-org>/sistema_vendas.git
cd sistema_vendas

# 2. Configurar .env
cp .env.example .env
# Edite .env: deixe DB_PASS vazio se XAMPP padrão, ajuste APP_URL

# 3. Importar schema + migrations
# (XAMPP MariaDB padrão: usuário root, sem senha)
C:/xampp/mysql/bin/mysql.exe -u root -e "CREATE DATABASE sistema_vendas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
C:/xampp/mysql/bin/mysql.exe -u root sistema_vendas < database/schema.sql

# 4. Aplicar migrations 028-070 (se ainda não estiverem no schema.sql)
for f in database/migrations/*.sql; do
  C:/xampp/mysql/bin/mysql.exe -u root sistema_vendas < "$f"
done

# 5. Criar admin inicial
C:/xampp/php/php.exe scripts/seed_admin.php

# 6. Acessar
# http://localhost/sistema_vendas/  →  redireciona pra landing
# http://localhost/sistema_vendas/public/login.php  →  login direto
```

---

## Setup em servidor Linux/Ubuntu (produção)

**Documento principal:** [docs/DEPLOY_AWS_UBUNTU.md](docs/DEPLOY_AWS_UBUNTU.md) — runbook completo passo a passo.

Resumo:

```bash
# 1. Pacotes (Ubuntu 22.04 LTS)
sudo apt update
sudo apt install -y apache2 mariadb-server \
  php8.2 php8.2-mysql php8.2-mbstring php8.2-curl \
  php8.2-zip php8.2-xml php8.2-opcache \
  certbot python3-certbot-apache git

# 2. Clone em /var/www/yuris
sudo git clone https://github.com/<seu-org>/sistema_vendas.git /var/www/yuris
cd /var/www/yuris

# 3. .env de produção (ver docs/ENVIRONMENT.md)
sudo cp .env.example .env
sudo vim .env
# Preencher:
#   APP_ENV=production  APP_DEBUG=false  APP_URL=https://seu-dominio.com
#   DB_PASS=<senha-forte>  CRON_TOKEN=<openssl rand -base64 32>
#   MFA_ENCRYPTION_KEY=base64:$(openssl rand -base64 32)
#   APP_ENCRYPTION_KEY=base64:$(openssl rand -base64 32)
#   BILLING_GATEWAY=<stripe|mercadopago|asaas>  (não pode ser null em prod)

# 4. Banco (ver docs/DATABASE_SETUP.md)
sudo mysql -e "CREATE DATABASE yuris CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'yuris_app'@'localhost' IDENTIFIED BY '<senha>'; \
               GRANT SELECT,INSERT,UPDATE,DELETE ON yuris.* TO 'yuris_app'@'localhost';"
sudo mysql yuris < database/schema.sql
sudo php scripts/seed_admin.php

# 5. Permissões
sudo chown -R www-data:www-data /var/www/yuris/storage /var/www/yuris/public/uploads
sudo chmod 750 /var/www/yuris/storage /var/www/yuris/public/uploads
sudo chmod 640 /var/www/yuris/.env

# 6. Apache VirtualHost com DocumentRoot=/var/www/yuris/public
# (template completo em docs/DEPLOY_AWS_UBUNTU.md)

# 7. SSL via Let's Encrypt
sudo certbot --apache -d seu-dominio.com

# 8. Cron jobs (ver docs/DEPLOY_AWS_UBUNTU.md §cron)
```

**Antes do go-live**, rode o checklist em [docs/CHECKLIST_DEPLOY_PRODUCAO.md](docs/CHECKLIST_DEPLOY_PRODUCAO.md) — 12 seções (config, banco, web, PHP, LGPD, cron, monitoramento, backup, acessos, testes, comunicação, pós-deploy).

---

## Configuração do .env

Cópia + ajuste a partir de [`.env.example`](.env.example). Cada variável documentada em [docs/ENVIRONMENT.md](docs/ENVIRONMENT.md).

**Variáveis críticas em produção** (`APP_ENV=production` faz `EnvLoader::validateProduction()` travar o boot se faltar):

| Variável | Por quê |
|---|---|
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | Conexão PDO |
| `CRON_TOKEN` | Autenticação dos endpoints `/api/*/tick.php` (≥32 chars aleatórios) |
| `MFA_ENCRYPTION_KEY` | AES-256 pra cifrar `mfa_secret` no banco |
| `APP_ENCRYPTION_KEY` | AES-256-GCM pra cifrar segredos de terceiros (AASP, etc.) |
| `BILLING_GATEWAY` | Pode ser `stripe`, `mercadopago` ou `asaas`. **Não pode ser `null` em prod** |
| `APP_ENV=production` | Esconde stack traces, valida secrets, força SSL em cookies |
| `APP_DEBUG=false` | Idem |

Gerar chaves AES seguras:
```bash
openssl rand -base64 32
```

---

## Banco de dados (zero-to-running)

**Documento detalhado:** [docs/DATABASE_SETUP.md](docs/DATABASE_SETUP.md).

```bash
# 1. CREATE DATABASE com charset/collation corretos
mysql -u root -p -e "CREATE DATABASE yuris CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. User com privs mínimos (recomendado em prod)
mysql -u root -p -e "
CREATE USER 'yuris_app'@'localhost' IDENTIFIED BY '<senha-forte>';
GRANT SELECT, INSERT, UPDATE, DELETE ON yuris.* TO 'yuris_app'@'localhost';
FLUSH PRIVILEGES;
"

# 3. Importar schema consolidado
mysql -u yuris_app -p yuris < database/schema.sql

# 4. Aplicar migrations adicionais (se schema.sql estiver defasado)
ls database/migrations/*.sql | sort | xargs -I {} sh -c 'mysql -u yuris_app -p<senha> yuris < {}'

# 5. Bootstrap do admin inicial + tenant raiz + subscription
php scripts/seed_admin.php

# 6. Verificar triggers de imutabilidade LGPD (devem retornar 20+ linhas)
mysql -u yuris_app -p yuris -e "
SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA='yuris' AND TRIGGER_NAME LIKE 'trg_%_no_%';
"
```

---

## Login inicial

Após `seed_admin.php`:

- **URL:** `https://<seu-dominio>/master_login.php` (painel administrativo isolado)
- **E-mail:** valor configurado em `seed_admin.php` (default `admin@yuris.local` — troque no script antes de rodar em prod)
- **Senha:** valor configurado em `seed_admin.php` (default aleatório printado no stdout — anote)

Após primeiro login, **habilite MFA imediatamente** (`/configuracoes/perfil.php` → "Segurança" → "Configurar 2FA").

---

## Estrutura de pastas

```
sistema_vendas/
├── .env.example          # Template de configuração
├── .env                  # Local (ignorado pelo git)
├── .gitignore
├── .htaccess             # Roteamento + bloqueios de pasta
├── README.md             # Este arquivo
│
├── app/                  # Backend PHP (não acessível via HTTP)
│   ├── Controllers/      # AuthController (login/logout)
│   ├── Helpers/          # AccountContext, TenantGuard, Crypto, EnvLoader, etc.
│   ├── Models/           # Account, User, Card, Processo, Task, etc. (~45 models)
│   └── Services/         # Billing, EvolutionApi, WebhookDispatcher, etc.
│
├── bin/
│   └── webhook_worker.php  # Worker async pra fila de webhooks
│
├── config/
│   └── database.php      # Conexão PDO (lê .env)
│
├── database/
│   ├── schema.sql        # Schema consolidado (importar primeiro)
│   ├── migrations/       # 001-070 incrementais
│   ├── seeds/            # seed_demo.sql, seed_processos_mensais.sql
│   └── seed_webhook_events.php
│
├── docs/                 # Documentação técnica (não acessível via HTTP)
│   ├── DEPLOY_AWS_UBUNTU.md
│   ├── DATABASE_SETUP.md
│   ├── ENVIRONMENT.md
│   ├── ROLLBACK.md
│   ├── CHECKLIST_DEPLOY_PRODUCAO.md
│   ├── ARCHITECTURE.md
│   ├── MULTITENANCY.md
│   ├── SCHEMA_AUDIT.md
│   ├── LGPD_*            # Suite LGPD completa (16+ docs)
│   └── ...
│
├── Imagens/              # Logos, branding
├── public/               # DocumentRoot do Apache em produção
│   ├── index.php         # Landing page institucional one-page
│   ├── login.php         # Login dos tenants
│   ├── master_login.php  # Login do painel admin
│   ├── dashboard.php, juridico.php, prospeccao.php, financas.php, ...
│   ├── api/              # ~70 endpoints REST
│   │   ├── master/       # Endpoints do painel admin
│   │   ├── push/         # Intimações (DJEN/AASP)
│   │   ├── whatsapp/     # WhatsApp via Evolution
│   │   ├── chat/         # Chat interno
│   │   ├── lgpd/         # Solicitações LGPD
│   │   └── ...
│   ├── assets/           # CSS, JS, fonts (estático)
│   ├── includes/         # legal_footer.php, sidebar.php
│   └── uploads/          # Uploads (writable, .htaccess bloqueando .php)
│
├── scripts/              # Scripts CLI utilitários (não acessível via HTTP)
│   ├── seed_admin.php
│   ├── check_user.php
│   └── test_multitenancy_e2e.php
│
└── storage/              # Files gerados em runtime (ignorado pelo git)
    ├── backups/
    ├── lgpd_exports/     # Exports de portabilidade (dados pessoais — NUNCA versionar)
    ├── lgpd_requests/    # Attachments de solicitações LGPD
    └── recurrence_cron.lock
```

---

## Comandos úteis

```bash
# Syntax check de um arquivo PHP
php -l public/api/dashboard.php

# Rodar smoke test multi-tenant
php scripts/test_multitenancy_e2e.php

# Verificar conexão com banco
php -r "require 'config/database.php'; var_dump(\App\Helpers\EnvLoader::get('DB_HOST'));"

# Listar migrations aplicadas vs presentes
ls database/migrations/*.sql | wc -l

# Cron tick LGPD (anonimização agendada) — rodar via CLI bypassa CRON_TOKEN
php public/api/lgpd_retention_tick.php

# Cron tick tarefas recorrentes
php public/api/tasks_recurrence_tick.php

# Worker async de webhooks
php bin/webhook_worker.php

# Limpar opcache em prod (após deploy)
curl -X POST https://seu-dominio/api/whatsapp/opcache_clear.php \
  -H "Cookie: $SESSION_COOKIE_DO_ADMIN"
```

---

## Documentação técnica

| Área | Arquivo |
|---|---|
| Deploy AWS Ubuntu | [docs/DEPLOY_AWS_UBUNTU.md](docs/DEPLOY_AWS_UBUNTU.md) |
| Setup banco | [docs/DATABASE_SETUP.md](docs/DATABASE_SETUP.md) |
| .env e variáveis | [docs/ENVIRONMENT.md](docs/ENVIRONMENT.md) |
| Rollback | [docs/ROLLBACK.md](docs/ROLLBACK.md) |
| Checklist pré-deploy | [docs/CHECKLIST_DEPLOY_PRODUCAO.md](docs/CHECKLIST_DEPLOY_PRODUCAO.md) |
| Arquitetura | [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) |
| Multi-tenancy | [docs/MULTITENANCY.md](docs/MULTITENANCY.md) |
| Schema do banco | [docs/SCHEMA_AUDIT.md](docs/SCHEMA_AUDIT.md) |
| LGPD (relatório final) | [docs/RELATORIO_FINAL_LGPD.md](docs/RELATORIO_FINAL_LGPD.md) |
| LGPD (políticas) | [docs/POLITICA_SEGURANCA_INFORMACAO.md](docs/POLITICA_SEGURANCA_INFORMACAO.md) |
| Webhooks | [docs/DOCUMENTACAO_WEBHOOKS_YURIS.md](docs/DOCUMENTACAO_WEBHOOKS_YURIS.md) |
| Integração AASP | [docs/INTEGRACAO_AASP.md](docs/INTEGRACAO_AASP.md) |
| Auditoria de produção (2026-05-26) | [docs/AUDITORIA_FINAL_2026-05-26.md](docs/AUDITORIA_FINAL_2026-05-26.md) |

---

## Licença

Proprietário — © Inovaize / Yuris. Todos os direitos reservados.

Para licenciamento, suporte ou parceria: [agenciainovaize@gmail.com](mailto:agenciainovaize@gmail.com).
