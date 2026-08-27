# Rollback — Yuris (procedimento de emergência)

**Versão:** 1.0 — 2026-05-26
**Quando usar:** falha crítica após deploy de release nova (login quebrado, dashboard 500, vazamento de dados, performance catastrófica).

> 🚨 **Antes de iniciar rollback, REGISTRE INCIDENTE.** No Painel Master → Incidentes, crie entrada documentando o que motivou o rollback. Isso é obrigatório LGPD se a falha envolveu dados pessoais.

---

## Decisão rápida — devo rollback?

| Sintoma | Rollback? | Alternativa |
|---|---|---|
| Login não funciona pra ninguém | 🔴 SIM | — |
| Dashboard 500 pra todos | 🔴 SIM | — |
| Dados de um tenant aparecendo pra outro | 🔴 SIM + RIPD | — |
| Feature nova quebrada, resto funciona | 🟡 Talvez | Feature flag off |
| Performance ruim em uma tela | 🟢 NÃO | Hotfix forward |
| Layout quebrado em uma página | 🟢 NÃO | Hotfix forward |

---

## Procedimento (15-30 minutos)

### Pré-requisito: identificar commit "última versão boa"

```bash
cd /var/www/yuris
git log --oneline -20
# Identifique o commit antes do problemático. Ex: a1b2c3d
```

> 💡 Se o problema começou imediatamente após o último deploy, é o commit anterior ao HEAD.

### Passo 1 — Backup do estado atual (5 min)

**SEMPRE antes de rollback** — garante que pode restaurar pro estado atual se rollback não resolver:

```bash
# Backup do código atual (commit hash + diff)
git rev-parse HEAD > /tmp/yuris-pre-rollback-commit.txt
git diff > /tmp/yuris-pre-rollback-diff.patch

# Backup do banco AGORA
DATE=$(date +%Y%m%d-%H%M)
sudo mysqldump --single-transaction --routines --triggers \
    -u root -p yuris > /tmp/yuris-pre-rollback-$DATE.sql

# Confirma tamanho razoável
ls -lh /tmp/yuris-pre-rollback-$DATE.sql
```

### Passo 2 — Reverter código (2 min)

#### Opção A: rollback completo pra commit anterior
```bash
cd /var/www/yuris

# DRY RUN: ver o que vai mudar
git diff <COMMIT-BOM>..HEAD --stat

# REAL: voltar pra commit estável
git checkout <COMMIT-BOM>

# Confirma
git log -1 --oneline
```

> ⚠️ Isso deixa o repo em estado "detached HEAD". Pra deixar uma branch limpa:
> ```bash
> git checkout -b rollback-$(date +%Y%m%d) <COMMIT-BOM>
> ```

#### Opção B: revert do commit problemático (preserva histórico)
```bash
cd /var/www/yuris
git revert <COMMIT-RUIM>
# Cria novo commit que desfaz o problemático. Pus mais limpo no histórico.
```

### Passo 3 — Restaurar banco (se a release nova rodou migration nova)

**Se a release nova só mudou código (não rodou migrations):**
Pular passo 3 — banco já está consistente.

**Se rodou migration nova que mexeu em dados:**
```bash
# 3a — Drop tabela/coluna nova (se a migration foi aditiva)
sudo mysql -u root -p yuris -e "ALTER TABLE foo DROP COLUMN nova_coluna;"

# 3b — OU restore completo do backup
# (APENAS se você TEM CERTEZA do estado anterior — backup do passo 1 cobre o ESTADO RUIM)
# Precisa do backup pre-deploy daquela release:
sudo mysql -u root -p -e "DROP DATABASE yuris; CREATE DATABASE yuris CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -u root -p yuris < /var/www/yuris/storage/backups/yuris-PRE-RELEASE-RUIM.sql
```

> 🚨 **Restore destrutivo apaga tudo desde o backup.** Se o backup é de 24h atrás, perde 24h de transações de clientes (dados pessoais novos, processos criados, etc.). Considerar reverter SÓ as tabelas/colunas afetadas (3a).

### Passo 4 — Limpar caches (1 min)

```bash
# Opcache
sudo systemctl reload apache2

# Session cache (cuidado — desloga TODOS os usuários ativos)
# Considere se vale o trade-off
# sudo rm /var/lib/php/sessions/sess_*

# Storage caches (não afetam dados)
sudo rm -rf /var/www/yuris/storage/cache/* 2>/dev/null || true
```

### Passo 5 — Validar rollback (5 min)

Smoke test mínimo:

```bash
# 5.1 — Conectividade
curl -I https://seu-dominio.com/   # esperado: 200

# 5.2 — Login funcional (substitua URLs/cookies pelos seus)
curl -c /tmp/cookie -X POST https://seu-dominio.com/login.php \
    -d "login=admin&senha=<SENHA>&csrf_token=<TOKEN>"

# 5.3 — Dashboard via curl com cookie
curl -b /tmp/cookie https://seu-dominio.com/dashboard.php | grep -q "Dashboard"

# 5.4 — Logs sem erros novos
sudo tail -30 /var/log/apache2/yuris-error.log
sudo tail -30 /var/log/php_errors.log
```

Smoke manual no browser:
- Login admin
- Dashboard carrega
- Aba afetada pelo bug original — agora funciona (esperado)

### Passo 6 — Comunicar (2 min)

- Mensagem no canal de incidentes da equipe
- Update da issue no GitHub com SHA do rollback
- Se afetou clientes externos: comunicado via e-mail/banner

---

## Pós-rollback (próximas horas)

### 1. Atualizar incidente (Painel Master → Incidentes)
- O que aconteceu
- Quando começou e quando rollback foi feito
- Quantos usuários/contas afetados
- Dados pessoais afetados? (LGPD obrigatório)
- Causa raiz preliminar

### 2. Root-cause analysis
- Por que QA não pegou?
- O que faltou no smoke test pós-deploy?
- O staging refletiu o problema?

### 3. Hotfix forward
- Reverter o rollback NÃO. Em vez disso, criar hotfix em branch nova:
```bash
git checkout master   # volta pra branch principal (commit problemático)
git checkout -b hotfix/<descricao>
# ... fix ...
git commit
# Merge + redeploy
```

### 4. RIPD (Etapa 5 LGPD)
Se incidente envolveu dados pessoais, abrir RIPD em até 72h conforme `docs/MODELO_RIPD.md`.

### 5. Notificação ANPD
Se o incidente confirmou vazamento real (ainda que reversível), avaliar notificação à ANPD conforme `docs/MODELO_NOTIFICACAO_ANPD.md`. Prazo: até 72h da ciência.

---

## Cenários específicos

### Cenário 1 — Migration nova quebra schema

**Sintoma:** logs mostram `Unknown column` ou `Table doesn't exist`.

**Solução:**
```bash
# Identifica qual migration causou o problema
ls -lt database/migrations/*.sql | head -3

# Reverte a alteração no banco MANUALMENTE
sudo mysql -u root -p yuris -e "ALTER TABLE foo DROP COLUMN col_problematica;"

# Reverte o código também
git revert <COMMIT-DA-MIGRATION>
```

### Cenário 2 — Senha admin perdida

**Não é rollback** — é recuperação. Em CLI:
```bash
cd /var/www/yuris
sudo php scripts/seed_admin.php
# Como o login 'admin' já existe, ele só rotaciona a senha. Anote a nova.
```

### Cenário 3 — Banco corrompido (XAMPP power outage)

A receita de recuperação está na memória interna do assistente, em
`xampp_power_outage_recovery` (fora deste repositório, na pasta pessoal do
desenvolvedor). Em produção AWS isso não acontece com tanta frequência.

### Cenário 4 — SSL certificate expirou

```bash
sudo certbot renew --force-renewal
sudo systemctl reload apache2
```

Se renewal falhou (DNS errado, rate limit):
- Ver `/var/log/letsencrypt/letsencrypt.log`
- Ajustar e tentar de novo

### Cenário 5 — Disco cheio

```bash
df -h
# Suspeitos: /var/log/apache2/, /var/www/yuris/storage/backups/

# Limpa backups antigos manualmente
sudo find /var/www/yuris/storage/backups -name "*.sql.enc" -mtime +30 -delete

# Compacta logs grandes
sudo gzip /var/log/apache2/yuris-access.log.1
```

---

## Lista de comandos úteis

```bash
# Status geral
sudo systemctl status apache2 mariadb cron
df -h /var
free -h
uptime

# Ver últimos commits
cd /var/www/yuris && git log -10 --oneline --decorate

# Ver mudanças recentes
git log --since="1 hour ago" --stat

# Restart serviços (não destrutivo)
sudo systemctl reload apache2     # opcache
sudo systemctl restart mariadb    # último recurso

# Logs em tempo real
sudo tail -f /var/log/apache2/yuris-error.log
sudo tail -f /var/log/php_errors.log
sudo tail -f /var/log/mysql/error.log
sudo tail -f /var/log/yuris-lgpd.log
sudo tail -f /var/log/yuris-webhook.log
```

---

## Quem chamar (escalação)

| Severidade | Quem | Prazo de notificação |
|---|---|---|
| 🔴 Login quebrado | Dev sênior + DPO | Imediato |
| 🔴 Vazamento dados | DPO + advogado(a) | Imediato |
| 🟠 Performance crítica | Dev sênior | 30 min |
| 🟡 Feature offline | Time produto | 4h |

Contatos atualizados em `docs/POLITICA_SEGURANCA_INFORMACAO.md` (NÃO commitado em texto).
