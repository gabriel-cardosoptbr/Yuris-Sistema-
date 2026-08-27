# Auditoria GitHub — Yuris (2026-05-26)

Repositório: `C:\xampp\htdocs\sistema_vendas`
Estado: 174 commits totais, 0 tags, primeiro push pra `origin/master` (que só tem o commit inicial — então saem **173 commits** novos de uma vez).

---

## 1. Working tree status

```
 M .htaccess
 M public/dashboard.php
?? public/assets/landing.css
?? public/assets/landing.js
?? public/index.php
```

- `.env` confirmado ignorado (`git check-ignore .env` retorna `.env`).
- **376 arquivos tracked**.
- Modificações da landing page (5 arquivos) ficam fora desse push inicial conforme combinado.

---

## 2. Tracked files que NÃO deveriam estar

Varreduras feitas:
- Logs (`*.log`): **NENHUM tracked**. (`storage/login_debug.log` está fora do tree — gitignore pega via `/storage/`.)
- Backups (`*.bak`, `*.zip`, `*.tar.gz`): **NENHUM tracked**.
- `vendor/`, `node_modules/`: **NENHUM** (e não há `composer.json`/`package.json` no projeto — stack PHP puro).
- `*.sqlite`, `*.db`, `*.pem`, `*.key`, `*.p12`, `id_rsa`: **NENHUM**.
- `storage/lgpd_exports/*.json`: **NENHUM** (gitignore explícito).
- `.backup_fase0_20260521_164244/`: pasta existe no FS, está **vazia**, **não está tracked**. (Recomendado: adicionar `/.backup_*/` ao .gitignore preventivamente.)
- Arquivos > 1 MB tracked: **apenas 1** → `Imagens/YURIS.png` (1.93 MB). Tolerável; **convém** comprimir/converter pra WebP fora do escopo desse push.

### Achados que merecem atenção (não bloqueantes):

| Caminho | Gravidade | Observação |
|---|---|---|
| `.claude/settings.local.json` | **Alta** | É a config local-por-dev. Contém URL ngrok pessoal (`calced-eminent-saul.ngrok-free.dev`). Não é segredo, mas vaza ambiente de teste e cresce indefinidamente. Convenção do Claude Code é manter `.local.json` fora do repo. |
| `.claude/settings.json` | Média | Permission allowlist gigante com paths absolutos Windows (`c:\xampp\...`). Pode ficar, mas é dev-machine-specific e suja diff. |
| `database/seeds/seed_demo.sql` (74 KB) | Baixa | É seed fictício (escritório "Monteiro & Associados"). Confirmado: sem CPFs/e-mails reais. OK pra versionar. |
| `database/migrations/022_add_senha_texto_users.sql` | **Alta** | Migration adiciona coluna `senha_texto` (senha em texto plano). O arquivo em si não tem segredo, mas a feature é um anti-pattern documentado no próprio comentário. Issue separada pra remover essa coluna depois do push. |
| `docs/db_schema_local.tsv` | Baixa | Schema dump local. Sem dados, só estrutura. OK. |
| `Imagens/` (1.93 + 0.75 + 0.46 MB) | Baixa | 3 PNGs de logo somam ~3 MB. Aceitável. |

**Nenhum arquivo `.env*` real foi commitado em momento algum** (verificado com `log --all --diff-filter=A`).

---

## 3. .gitignore — adequação e adições sugeridas

O atual cobre: `vendor/`, `node_modules/`, `*.env`/`.env*` (com whitelist `!.env.example`), `/public/uploads/*` (com whitelist do `.htaccess`), `/storage/`, `/storage/lgpd_exports/` (redundante mas defensivo), `*.log`, `/logs/`, IDE files, `/cache/`.

### Adições recomendadas (sem aplicar agora):

```gitignore
# Backup folders criados por scripts de hardening
/.backup_*/

# Claude Code dev-machine-specific (mantém só o team-shared settings.json)
.claude/settings.local.json

# Dumps SQL grandes (qualquer mysqldump local)
*.dump.sql
*.mysqldump

# Lockfiles de cron (presentes em storage/, já cobertos, mas explícito)
*.lock
```

---

## 4. README — gaps

O README atual tem 25 linhas, é o boilerplate inicial e está **completamente obsoleto**: chama o projeto de "Inovaize - Sistema de Vendas (skeleton)", lista "próximos passos" tipo "implementar features da spec" — quando o projeto já tem 174 commits, módulos LGPD/AASP/multi-tenancy/billing prontos.

### Estrutura mínima pra README final (clone-and-run no Linux):

1. **Título + tagline** — "Yuris CRM Jurídico — SaaS multi-tenant".
2. **Stack**: PHP 8+, MariaDB 10.4+, Apache (mod_rewrite), sem composer/npm.
3. **Requisitos**: extensões PHP (`pdo_mysql`, `openssl`, `mbstring`, `curl`, `gd`), `openssl` CLI pra gerar chaves.
4. **Setup** (Linux):
   - `git clone` → `/var/www/yuris`
   - `cp .env.example .env` e preencher (link pra seção 5)
   - Gerar `MFA_ENCRYPTION_KEY` e `APP_ENCRYPTION_KEY` com `openssl rand -base64 32`
   - Criar DB: `mysql -u root -e "CREATE DATABASE sistema_vendas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"`
   - Importar `database/schema.sql` + aplicar migrations `001..070` em ordem
   - (Opcional) Aplicar `database/seeds/seed_demo.sql` em ambiente dev
   - Apontar vhost Apache pra `public/`
5. **Login inicial**: instruir uso de `scripts/seed_admin.php`.
6. **Estrutura de diretórios** (1 frase por pasta: `app/`, `config/`, `database/`, `docs/`, `public/`, `scripts/`, `bin/`).
7. **Comandos**: `php bin/webhook_worker.php`, crons (`api/lgpd_retention_tick.php`, `api/push/tick.php`, `api/tasks_recurrence_tick.php` — com `CRON_TOKEN`).
8. **Links** pras docs em `docs/`: `ARCHITECTURE.md`, `MULTITENANCY.md`, `API.md`, `FEATURES.md`, `CHECKLIST_DEPLOY_PRODUCAO.md`.
9. **Aviso LGPD**: pasta `storage/lgpd_exports/` NUNCA versionar.

---

## 5. .env.example — gaps de vars

Comparei `.env.example` (84 linhas) com `.env` (56 linhas) e com 30+ chamadas `EnvLoader::get(...)` no código.

### Vars usadas no código mas **AUSENTES** do `.env.example`:

| Var | Onde é lida | Default no código |
|---|---|---|
| `MAIL_DRIVER` | `app/Services/Mailer.php:93` | `'log'` |
| `AASP_MAX_DAYS` | `public/api/aasp/search.php:96` | `'30'` |

### Vars no `.env.example` que **NÃO estão no `.env` real** (e vice-versa):

- `.env.example` tem `EVOLUTION_INSTANCE` e bloco `DJEN_BASE_URL` + `DATAJUD_API_KEY` + `DATAJUD_BASE_URL` que **não estão no `.env` real**. Isso é OK — vars com fallback no código rodam sem o `.env` ter elas explícitas. Mas o `.env` real deveria ter pelo menos as comentadas pra dev saber que existem.

### Recomendação:

Adicionar ao `.env.example`:
```
# ── Email (LGPD: notificações de incidente/titular) ──
# Drivers: log (dev — grava em error_log) | smtp (futuro) | sendmail (futuro)
MAIL_DRIVER=log

# Limite máximo de dias retroativos em busca AASP (proteção anti-flood)
AASP_MAX_DAYS=30
```

**Nenhum segredo real vaza pelo `.env.example`** — todas as chaves AES estão como placeholder ("change_me" / vazias).

---

## 6. Tamanho do push inicial

- `git count-objects -vH`: **10.30 MiB**, 1731 objetos, 0 packed (todos loose).
- 173 commits novos. GitHub aguenta tranquilamente — limite por push é 2 GB, por arquivo 100 MB.
- Push deve levar < 30s em conexão decente.
- **Sem risco de tamanho.** O peso vem dos 3 PNGs de logo (3.1 MB juntos), não de inflação histórica.

---

## 7. Segredos no histórico git (read-only)

Varredura: `git log --all --pretty=format: --name-only --diff-filter=A | grep -iE "(\.env$|secret|credentials|password\.txt|\.pem$|\.key$|id_rsa|\.p12$|\.pfx$|aws_|gcp_)"`.

Resultado:
```
database/migrations/022_add_senha_texto_users.sql
docs/POLITICA_SENHAS_E_ACESSO.md
```

Ambos são falsos positivos do regex (matcham "senha"/"password" no nome, mas não contêm segredos):
- `022_add_senha_texto_users.sql` é migration DDL (cria coluna).
- `POLITICA_SENHAS_E_ACESSO.md` é política institucional, sem credenciais.

**Conclusão**: histórico está limpo. Nunca foi commitado `.env`, chave privada, SSH key, AWS/GCP credentials ou dump SQL com dados reais. Push pode rolar sem `git filter-branch` / `git filter-repo`.

---

## 8. Bloqueadores antes do push

### Bloqueador 1 (RED) — `.claude/settings.local.json` versionado
Esse arquivo é, por convenção do Claude Code, per-developer. Contém URL ngrok pessoal e cresce sem fim com permissões locais. Vai gerar conflito feio toda vez que outro dev clone.
**Ação**: `git rm --cached .claude/settings.local.json` + adicionar ao `.gitignore`. (Não estou executando — só reportando.)

### Bloqueador 2 (RED) — README obsoleto
O README atual fala em "skeleton" e "próximos passos: implementar features da spec". Se alguém clonar do GitHub agora, vai achar que o projeto está vazio. README precisa ser reescrito antes do push **público** (se for público) ou pelo menos antes de compartilhar URL com terceiros.

### Bloqueador 3 (YELLOW) — `.env.example` faltando 2 vars
`MAIL_DRIVER` e `AASP_MAX_DAYS` não estão documentados. Não impedem o app de rodar (têm default no código), mas devs não vão descobrir que existem.

### Não-bloqueador mas urgente — coluna `senha_texto`
Migration 022 cria coluna pra armazenar senha em texto plano. Não é segredo no repo (o arquivo é apenas DDL), mas é um anti-pattern de segurança em produção. Issue separada **depois** do push.

---

## 9. Ordem segura de correção

Antes do `git push origin master`:

1. **Atualizar `.gitignore`** com adições da seção 3 (especialmente `.claude/settings.local.json` e `/.backup_*/`).
2. **Untrack `.claude/settings.local.json`** sem deletar do FS: `git rm --cached .claude/settings.local.json`.
3. **Reescrever `README.md`** com a estrutura da seção 4.
4. **Adicionar `MAIL_DRIVER` e `AASP_MAX_DAYS` ao `.env.example`** (seção 5).
5. Commit único dessas 4 alterações ("chore: pre-push hygiene — gitignore, README, .env.example").
6. Confirmar `git status --short` está limpo (exceto os 5 da landing).
7. `git push origin master`.
8. Criar tag `v1.0.0` ou similar pós-push (zero tags hoje — bom marco).

---

## Resumo executivo

| Categoria | Count |
|---|---|
| Tracked files | 376 |
| Commits totais | 174 |
| Tamanho push | 10.30 MiB |
| Bloqueadores RED | 2 |
| Bloqueadores YELLOW | 1 |
| Segredos no histórico | 0 |
| Arquivos > 1MB | 1 (logo PNG, aceitável) |
| `.env` em qualquer commit | 0 |

### Pronto pra push?

**Com ressalvas.** Os 3 bloqueadores acima (settings.local.json tracked, README obsoleto, 2 vars faltando no .env.example) são correções de 15 minutos. Histórico está limpo, sem segredos, sem binários gigantes, sem `vendor/`. Após aplicar a ordem da seção 9, push é seguro.
