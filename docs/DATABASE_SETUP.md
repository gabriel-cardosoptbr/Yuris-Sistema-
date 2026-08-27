# Database Setup — Yuris

**Versão:** 1.0 — 2026-05-26
**Pré-requisito:** MariaDB 10.4+ instalado (idealmente 10.6 LTS).

---

## Sumário

1. [Criar database + user](#1-criar-database--user)
2. [Importar schema](#2-importar-schema)
3. [Aplicar seeds](#3-aplicar-seeds)
4. [Criar admin inicial](#4-criar-admin-inicial)
5. [Validar instalação](#5-validar-instalação)
6. [Migrations incrementais (deploy de release nova)](#6-migrations-incrementais-deploy-de-release-nova)
7. [Backup e restore](#7-backup-e-restore)
8. [Charset, collation, timezone](#8-charset-collation-timezone)
9. [Troubleshooting](#9-troubleshooting)

---

## 1. Criar database + user

```bash
sudo mysql -u root -p
```

Dentro do prompt:

```sql
-- Database principal
CREATE DATABASE yuris
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

-- User da aplicação (privs mínimos — SEM GRANT, DROP, ALTER USER)
CREATE USER 'yuris_app'@'localhost' IDENTIFIED BY '<SENHA-FORTE-32-CHARS>';

GRANT SELECT, INSERT, UPDATE, DELETE
    ON yuris.*
    TO 'yuris_app'@'localhost';

-- Permissão extra que o schema.sql precisa (procedures + triggers)
-- IMPORTANTE: ESSAS PERMISSÕES SÓ DURANTE IMPORT — depois revogar.
GRANT CREATE, ALTER, INDEX, DROP, CREATE ROUTINE, ALTER ROUTINE, TRIGGER
    ON yuris.*
    TO 'yuris_app'@'localhost';

FLUSH PRIVILEGES;

EXIT;
```

**Após importar o schema, revogar as permissões "extras"** (passo 5):
```sql
REVOKE CREATE, ALTER, INDEX, DROP, CREATE ROUTINE, ALTER ROUTINE, TRIGGER
    ON yuris.*
    FROM 'yuris_app'@'localhost';
FLUSH PRIVILEGES;
```

> 💡 Em DEV local com XAMPP, o user padrão é `root` sem senha. Não recomendamos isso em produção.

---

## 2. Importar schema

O arquivo `database/schema.sql` é gerado via `mysqldump` do banco com todas as 70 migrations já aplicadas. Tem ~146KB, 92 tabelas, procedures + triggers.

```bash
cd /var/www/yuris

# Importar como root (permissões necessárias estão no schema)
sudo mysql -u root -p yuris < database/schema.sql

# Validar tabelas criadas
sudo mysql -u root -p yuris -e "SHOW TABLES;" | wc -l
# Esperado: ~93 (1 header + 92 tabelas)

# Validar charset
sudo mysql -u root -p -e "
SELECT TABLE_NAME, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA='yuris' AND TABLE_COLLATION <> 'utf8mb4_unicode_ci'
LIMIT 10;
"
# Esperado: zero linhas (todas devem ser utf8mb4_unicode_ci)
```

### Para outro nome de banco (não 'yuris')

O `schema.sql` tem no topo:
```sql
CREATE DATABASE IF NOT EXISTS sistema_vendas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sistema_vendas;
```

Edite essas 2 linhas pra `yuris` (ou seu nome) **OU** rode com `mysql yuris < schema.sql` (a flag posicional força o uso desse banco).

---

## 3. Aplicar seeds

### Seeds essenciais (obrigatórios)

```bash
# Planos de billing (Basico, Profissional, Enterprise, Teste Grátis, Pago Padrão)
# — sem isso, seed_admin.php falha porque exige plan_id em subscriptions
sudo mysql -u root -p yuris < database/seeds/seed_demo.sql

# Catálogo de eventos webhook (~40 eventos disponíveis)
sudo php /var/www/yuris/database/seed_webhook_events.php

# Categorias de operadores LGPD (data_processors) — se aplicável
# Já vem com o schema; verifique:
sudo mysql -u root -p yuris -e "SELECT COUNT(*) FROM data_processors;"
```

### Seeds opcionais

```bash
# Processos mensais de demonstração (NÃO usar em prod com dados reais)
# sudo mysql -u root -p yuris < database/seeds/seed_processos_mensais.sql
```

---

## 4. Criar admin inicial

```bash
cd /var/www/yuris

# Cria account raiz + subscription + user super_admin. Idempotente.
sudo php scripts/seed_admin.php \
    --login=admin \
    --account-name="Nome da Empresa" \
    --account-email=admin@seu-dominio.com
```

Output esperado:
```
═══════════════════════════════════════════════════════════════
 YURIS — BOOTSTRAP COMPLETO
═══════════════════════════════════════════════════════════════
 Account:       #1  (Nome da Empresa)
 Subscription:  plano id=1, status=active, 30 dias
 User:          #1  (Administrador)
                role=super_admin, perfil=admin
                codigo_vinculo=A3F9D2B1
───────────────────────────────────────────────────────────────
 LOGIN
───────────────────────────────────────────────────────────────
 URL:           https://<seu-dominio>/master_login.php
 Login:         admin
 Senha:         a8K3mNpQrTx9zBcD
                ── anote AGORA, não fica salva em texto ──
═══════════════════════════════════════════════════════════════
```

**Anote a senha**. Não fica registrada em lugar nenhum legível.

Para customizar a senha:
```bash
sudo php scripts/seed_admin.php --password=MinhaSenh@123
```

Para mais opções: `sudo php scripts/seed_admin.php --help`

---

## 5. Validar instalação

```bash
# 5.1 — Tabelas (esperado: 92)
sudo mysql -u root -p yuris -e "SHOW TABLES;" | wc -l

# 5.2 — Triggers de imutabilidade LGPD (esperado: 20+)
sudo mysql -u root -p yuris -e "
SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA='yuris' AND TRIGGER_NAME LIKE 'trg_%_no_%';
"

# 5.3 — Procedures (esperado: 1+ — _add_index_safe)
sudo mysql -u root -p yuris -e "SHOW PROCEDURE STATUS WHERE Db='yuris';"

# 5.4 — Charset/collation (esperado: zero linhas)
sudo mysql -u root -p -e "
SELECT TABLE_NAME, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA='yuris' AND TABLE_COLLATION <> 'utf8mb4_unicode_ci';
"

# 5.5 — Admin user existe
sudo mysql -u root -p yuris -e "
SELECT id, login, role, account_id FROM users WHERE login='admin';
"

# 5.6 — Account raiz existe
sudo mysql -u root -p yuris -e "
SELECT id, nome, tipo, plano, status FROM accounts WHERE tipo='matriz' LIMIT 1;
"

# 5.7 — Conexão do user da app funciona
sudo mysql -u yuris_app -p -e "SELECT 1 FROM yuris.accounts LIMIT 1;"
```

---

## 6. Migrations incrementais (deploy de release nova)

Quando subir nova versão do código que adicionou migration nova (ex: `072_nova_feature.sql`):

```bash
cd /var/www/yuris

# Listar migrations não aplicadas (você guarda manualmente quais já rodou)
ls database/migrations/072*.sql   # ou as novas que vieram no release

# Aplicar uma por uma
sudo mysql -u root -p yuris < database/migrations/072_nova_feature.sql

# OU em batch (cuidado com erros — se uma falhar, próximas continuam)
for f in database/migrations/072*.sql database/migrations/073*.sql; do
    echo "→ $f"
    sudo mysql -u root -p yuris < "$f" || break
done
```

> 💡 **Recomendado:** crie tabela `_migrations_applied (filename, applied_at)` pra rastrear. O Yuris não tem isso ainda — adicionar é tarefa pós-deploy.

---

## 7. Backup e restore

### Backup diário automático

```bash
sudo nano /etc/cron.daily/yuris-backup
```

Conteúdo:
```bash
#!/bin/bash
set -e

DATE=$(date +%Y%m%d-%H%M)
DEST=/var/www/yuris/storage/backups
mkdir -p "$DEST"

# Dump completo cifrado (-> não em texto plano)
mysqldump --single-transaction --routines --triggers \
    -u root -p<SENHA-ROOT-MYSQL> yuris | \
    openssl enc -aes-256-cbc -salt -pbkdf2 -pass file:/etc/yuris-backup-key \
    > "$DEST/yuris-$DATE.sql.enc"

# Manter últimos 30 dias
find "$DEST" -name "yuris-*.sql.enc" -mtime +30 -delete

# Subir pra S3 (opcional)
# aws s3 cp "$DEST/yuris-$DATE.sql.enc" s3://yuris-backups/
```

```bash
sudo chmod +x /etc/cron.daily/yuris-backup
sudo openssl rand -base64 32 > /etc/yuris-backup-key
sudo chmod 600 /etc/yuris-backup-key
```

### Restore

```bash
# Decifrar
sudo openssl enc -d -aes-256-cbc -pbkdf2 \
    -pass file:/etc/yuris-backup-key \
    -in yuris-20260526-0300.sql.enc -out yuris-restore.sql

# Importar (em banco vazio ou substituindo)
sudo mysql -u root -p yuris < yuris-restore.sql
rm yuris-restore.sql   # apaga o plaintext
```

Detalhes em [POLITICA_BACKUP_RECUPERACAO.md](lgpd/POLITICA_BACKUP_RECUPERACAO.md).

---

## 8. Charset, collation, timezone

### Charset MariaDB

Em `/etc/mysql/mariadb.conf.d/50-server.cnf`:
```ini
[mysqld]
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci

[mysql]
default-character-set = utf8mb4
```

### Timezone

Servidor Linux deve estar em `America/Sao_Paulo`:
```bash
sudo timedatectl set-timezone America/Sao_Paulo
date    # deve mostrar -03 ou -02 (verão)
```

MariaDB carrega timezones do sistema se você importou:
```bash
sudo mysql_tzinfo_to_sql /usr/share/zoneinfo | sudo mysql -u root -p mysql
sudo mysql -u root -p -e "SET GLOBAL time_zone = 'America/Sao_Paulo';"
```

PHP via `php.ini`:
```ini
date.timezone = America/Sao_Paulo
```

---

## 9. Troubleshooting

### "Access denied for user 'yuris_app'"
```bash
# Confira senha
sudo mysql -u root -p -e "SHOW GRANTS FOR 'yuris_app'@'localhost';"

# Reset
sudo mysql -u root -p -e "
ALTER USER 'yuris_app'@'localhost' IDENTIFIED BY '<NOVA-SENHA>';
FLUSH PRIVILEGES;
"

# Atualize .env
sudo nano /var/www/yuris/.env
# DB_PASS=<NOVA-SENHA>
```

### "Unknown column 'X' in tabela Y"
Migration nova não foi aplicada. Verifique:
```bash
# Ver últimas migrations no diretório
ls -lt database/migrations/*.sql | head -5

# Tentar aplicar
sudo mysql -u root -p yuris < database/migrations/NNN_nova.sql
```

### "Duplicate entry 'X' for key 'codigo_vinculo'"
`scripts/seed_admin.php` gerou um codigo_vinculo que já existe. Rode de novo — usa `random_int` por trás, colisão estatística é improvável.

### Charset errado (caracteres como "B├ísico")
Significa que dados foram inseridos em latin1 e estão sendo lidos como utf8mb4. Solução:
```sql
-- Conferir
SELECT @@character_set_database, @@collation_database;

-- Forçar (em DEV — não recomendado em prod com dados)
ALTER DATABASE yuris CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Por tabela (cuidado — testar em staging)
ALTER TABLE plans CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Em prod com dados, fazer dump + edit + reimport é mais seguro.

### Triggers LGPD não foram criados
Você importou o schema com user sem permissão `TRIGGER`. Reimport com root:
```bash
sudo mysql -u root -p yuris < database/schema.sql
```

Validar:
```bash
sudo mysql -u root -p yuris -e "SHOW TRIGGERS;" | head -25
# Deve listar triggers como trg_master_audit_log_no_update, etc.
```
