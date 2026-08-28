# Yuris — CRM Jurídico Inteligente

Sistema SaaS multi-tenant para escritórios de advocacia. Pipeline de leads, gestão de processos, intimações automatizadas (DJEN/AASP), WhatsApp multi-tenant, financeiro/DRE, painel master administrativo, conformidade LGPD completa.

**Status:** em produção, com escritórios reais usando. 124 migrations (001 a 110), 12 etapas LGPD.
Stack: PHP 8.2 + MySQL 8.0 (producao) / MariaDB 10.4+ (dev) + Apache 2.4.

> Onde comecar a ler o codigo: [`CLAUDE.md`](CLAUDE.md) para as regras do projeto,
> [`app/README.md`](app/README.md) para o mapa dos dominios. Cada pasta tem o seu README.

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

**Não usa Composer**, mas **tem autoloader**: `app/bootstrap.php`, 20 linhas de `spl_autoload_register`
que mapeiam namespace para pasta. Todo ponto de entrada carrega o bootstrap e nada mais:

```php
require_once __DIR__ . '/../app/bootstrap.php';
use App\Processos\Processo;
```

O namespace espelha a pasta, **sem exceção** (`App\Core\*` → `app/Core/`, `App\Tarefas\*` → `app/Tarefas/`).
Ver [`app/README.md`](app/README.md).

**Não usa npm/node.** JavaScript é vanilla (sem build step).

**Dependências externas opcionais** (cada uma degrada graciosamente se ausente):
- **Evolution API** (WhatsApp) — self-hosted, ver [docs/integracao/INTEGRACAO_AASP.md](docs/integracao/INTEGRACAO_AASP.md) e [docs/integracao/DOCUMENTACAO_WEBHOOKS_YURIS.md](docs/integracao/DOCUMENTACAO_WEBHOOKS_YURIS.md)
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

**Documento principal:** [docs/deploy/DEPLOY_AWS_UBUNTU.md](docs/deploy/DEPLOY_AWS_UBUNTU.md) — runbook completo passo a passo.

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
# (template completo em docs/deploy/DEPLOY_AWS_UBUNTU.md)

# 7. SSL via Let's Encrypt
sudo certbot --apache -d seu-dominio.com

# 8. Cron jobs (ver docs/deploy/DEPLOY_AWS_UBUNTU.md §cron)
```

**Antes do go-live**, rode o checklist em [docs/deploy/CHECKLIST_DEPLOY_PRODUCAO.md](docs/deploy/CHECKLIST_DEPLOY_PRODUCAO.md) — 12 seções (config, banco, web, PHP, LGPD, cron, monitoramento, backup, acessos, testes, comunicação, pós-deploy).

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

Organizada **por assunto**, não por tipo técnico. O nome da pasta é o nome do
módulo no menu do sistema. **Cada pasta tem seu `README.md`**, com o que ela
abriga, o que cada arquivo faz e as regras que valem ali.

```
sistema_vendas/
├── CLAUDE.md             # regras do projeto, LEIA PRIMEIRO
├── README.md             # este arquivo
├── .env / .env.example / .gitignore / .htaccess
├── Dockerfile / docker-compose.yml
│
├── app/                  # regra de negócio, nada aqui responde por URL
│   ├── bootstrap.php     # o autoloader. É o único require de um ponto de entrada
│   ├── Core/             # Database, AccountContext, TenantGuard, Crypto, .env, Mailer
│   ├── Usuarios/         # login, 2FA, convites, vínculos, times
│   ├── Master/           # conta (tenant), Painel Master, incidentes, auditoria
│   ├── Prospeccao/       # funil de vendas: cards, colunas, contatos
│   ├── Clientes/         # base de clientes, origens, kanban operacional
│   ├── Processos/        # processos, histórico, AASP/DJEN
│   │   └── Monitor/      # motor de busca de publicações (providers + runners)
│   ├── Tarefas/          # quadros, tarefas, checklists, horas, recorrência
│   ├── Financas/         # plano de contas do DRE
│   ├── WhatsAppAgente/   # canal Evolution, webhook de entrada
│   │   └── AiIntake/     # o agente de pré-atendimento jurídico
│   ├── Webhooks/         # webhooks de SAÍDA do Yuris
│   ├── Billing/          # limites e módulos por plano
│   │   └── Gateway/      # integração com gateway de pagamento
│   └── Lgpd/             # solicitações do titular, anonimização, documentos legais
│
├── public/               # DocumentRoot. O caminho do arquivo É a URL
│   ├── *.php             # 31 páginas: sistema, login, Master, institucional
│   ├── api/              # 133 endpoints (master/ whatsapp/ push/ aasp/ chat/ legal/ auth/ lgpd/)
│   ├── assets/           # CSS e JS, um arquivo por tela, sem build
│   ├── includes/         # sidebar, cabeçalho de SEO, rodapés
│   ├── v2/               # landing institucional nova, servida na /
│   ├── sistema_vendas/Imagens/   # os logos. NÃO mover, é URL usada pelo sidebar
│   ├── uploads/          # arquivos enviados pelos clientes
│   └── <slug>/           # 11 páginas de SEO, uma pasta com index.php cada
│
├── database/
│   ├── migrations/       # 124 arquivos, 001 a 110. Nunca editar uma já aplicada
│   ├── seeds/            # demo, processos, catálogos do agente de IA
│   ├── auditorias/       # divergências entre migrations e banco real
│   └── schema.sql
│
├── docs/                 # deploy/ integracao/ lgpd/ auditorias/ produto/ design/ seo/
├── scripts/              # utilitários de linha de comando
│   ├── tests/            # as 5 suites. Rodar antes e depois de mexer em app/
│   └── manutencao/       # operações pontuais já executadas, guardadas como receita
├── bin/                  # processos de fundo (worker de webhook, roda por cron)
├── config/               # database.php. Segredo NUNCA aqui, vai no .env
└── storage/              # gerado em runtime, fora do git
    ├── backups/
    ├── lgpd_exports/     # dados pessoais, NUNCA versionar
    └── lgpd_requests/
```

### A regra de organização

`app/` **não** volta a ter `Models/`, `Helpers/` ou `Services/`: arquivo novo vai
na pasta do assunto dele. Se serve a três ou mais domínios sem pertencer a
nenhum, vai em `Core/`. E ao mexer numa pasta, o `README.md` dela é atualizado
na mesma mudança. Detalhe em [CLAUDE.md](CLAUDE.md).

`public/` mantém a disposição atual de propósito: mover um arquivo de lá muda
uma URL pública.

---

## Comandos úteis

```bash
# Syntax check de um arquivo PHP
php -l public/api/dashboard.php

# Rodar smoke test multi-tenant
php scripts/test_multitenancy_e2e.php

# Verificar conexão com banco
php -r "require 'config/database.php'; var_dump(\App\Core\EnvLoader::get('DB_HOST'));"

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
| Deploy AWS Ubuntu | [docs/deploy/DEPLOY_AWS_UBUNTU.md](docs/deploy/DEPLOY_AWS_UBUNTU.md) |
| Setup banco | [docs/DATABASE_SETUP.md](docs/DATABASE_SETUP.md) |
| .env e variáveis | [docs/ENVIRONMENT.md](docs/ENVIRONMENT.md) |
| Rollback | [docs/deploy/ROLLBACK.md](docs/deploy/ROLLBACK.md) |
| Checklist pré-deploy | [docs/deploy/CHECKLIST_DEPLOY_PRODUCAO.md](docs/deploy/CHECKLIST_DEPLOY_PRODUCAO.md) |
| Arquitetura | [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) |
| Multi-tenancy | [docs/MULTITENANCY.md](docs/MULTITENANCY.md) |
| Schema do banco | [docs/auditorias/SCHEMA_AUDIT.md](docs/auditorias/SCHEMA_AUDIT.md) |
| LGPD (relatório final) | [docs/lgpd/RELATORIO_FINAL_LGPD.md](docs/lgpd/RELATORIO_FINAL_LGPD.md) |
| LGPD (políticas) | [docs/lgpd/POLITICA_SEGURANCA_INFORMACAO.md](docs/lgpd/POLITICA_SEGURANCA_INFORMACAO.md) |
| Webhooks | [docs/integracao/DOCUMENTACAO_WEBHOOKS_YURIS.md](docs/integracao/DOCUMENTACAO_WEBHOOKS_YURIS.md) |
| Integração AASP | [docs/integracao/INTEGRACAO_AASP.md](docs/integracao/INTEGRACAO_AASP.md) |
| Auditoria de produção (2026-05-26) | [docs/auditorias/AUDITORIA_FINAL_2026-05-26.md](docs/auditorias/AUDITORIA_FINAL_2026-05-26.md) |

---

## Licença

Proprietário — © Inovaize / Yuris. Todos os direitos reservados.

Para licenciamento, suporte ou parceria: [agenciainovaize@gmail.com](mailto:agenciainovaize@gmail.com).
