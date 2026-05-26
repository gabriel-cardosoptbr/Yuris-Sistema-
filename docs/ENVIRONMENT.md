# Environment Variables — Yuris

**Versão:** 1.0 — 2026-05-26
**Arquivo de referência:** [`.env.example`](../.env.example)

Cada variável documentada com:
- **Criticidade** (🔴 obrigatória / 🟠 recomendada / 🟡 opcional)
- **Default** se ausente
- **Como gerar** valor seguro
- **Quem usa** (helper/módulo)

---

## Carregamento

`config/database.php` chama `\App\Helpers\EnvLoader::load()` no boot. O loader:
1. Lê `.env` da raiz do projeto
2. Cacheia em static var
3. Disponibiliza via `EnvLoader::get('CHAVE', $default)`

Em produção (`APP_ENV=production`), `EnvLoader::validateProduction()` é chamado e **trava o boot com 503** se faltar variável obrigatória ou se BILLING_GATEWAY=null.

---

## ── App ──

### `APP_ENV` 🔴
Valor: `development` | `production` | `staging`
Default: `development`
Quem usa: `EnvLoader::validateProduction()`, `ErrorReporter`, `Gateway::driver()`

Em `production` força:
- Esconde stack traces (ErrorReporter)
- Valida chaves obrigatórias
- Proíbe NullGateway no billing
- Cookies de sessão exigem HTTPS

### `APP_DEBUG` 🔴
Valor: `true` | `false`
Default: `true` (dev), DEVE ser `false` em prod
Quem usa: `ErrorReporter`

Em `true`, expõe stack trace e arquivos do servidor em mensagens de erro. NUNCA em prod.

### `APP_URL` 🔴
Valor: URL absoluta (com protocolo)
Default: `http://localhost/sistema_vendas`
Exemplo prod: `https://yuris.app.br`
Quem usa: `App\Helpers\Url::base()` (helper de URL), redirects, e-mails transacionais

> Em prod, `APP_URL` SEM o `/sistema_vendas/` final (a menos que use Opção A de [DEPLOY_AWS_UBUNTU.md §12](DEPLOY_AWS_UBUNTU.md)).

### `APP_TIMEZONE` 🟡
Default: `America/Sao_Paulo`
Quem usa: PHP `date.timezone` (mas só se setado via `ini_set`).

---

## ── Banco de dados ──

### `DB_HOST` 🔴
Default: `127.0.0.1` (dev)
Exemplo prod: `127.0.0.1` (banco local) ou RDS endpoint
Quem usa: `App\Models\Database` via `config/database.php`

### `DB_NAME` 🔴
Default: `sistema_vendas` (dev)
Exemplo prod: `yuris`

### `DB_USER` 🔴
Default: `root` (XAMPP padrão — APENAS DEV)
Exemplo prod: `yuris_app` (user com privs mínimos)

### `DB_PASS` 🔴
Default: vazio (XAMPP padrão — APENAS DEV)
**Produção:** mínimo 16 caracteres alfanuméricos + símbolos.
Como gerar: `openssl rand -base64 24`

### `DB_CHARSET` 🟠
Default: `utf8mb4`
Não mexer.

---

## ── Cifra at-rest (LGPD) ──

### `MFA_ENCRYPTION_KEY` 🔴 (obrigatória se algum super_admin tem MFA habilitado)
Default: vazio
Formato aceito:
- `base64:<32 bytes em base64>` (recomendado)
- `hex:<64 chars hex>`
- 32 bytes raw

Como gerar:
```bash
echo "base64:$(openssl rand -base64 32)"
```

Quem usa: `App\Helpers\TotpHelper` para cifrar `users.mfa_secret`

> ⚠️ **NUNCA rotacione esta chave** depois que algum super_admin habilitou MFA — você perde acesso aos secrets. Para troca segura: decifre todos os secrets com chave antiga, depois cifre de novo com chave nova, dentro de uma transação.

### `APP_ENCRYPTION_KEY` 🔴 (obrigatória se aasp_integrations tem qualquer linha)
Default: vazio
Formato: igual ao MFA_ENCRYPTION_KEY
Como gerar:
```bash
echo "base64:$(openssl rand -base64 32)"
```

Quem usa: `App\Helpers\Crypto` (AES-256-GCM) para cifrar:
- `aasp_integrations.chave_encrypted`
- Futuros tokens de terceiros

Mesma regra de rotação: rotacione SÓ se conseguir descriptografar tudo com a antiga e re-cifrar com a nova.

---

## ── Cron ──

### `CRON_TOKEN` 🔴
Default: vazio
**Produção:** mínimo 32 chars aleatórios.
Como gerar:
```bash
openssl rand -hex 24
```

Quem usa: endpoints `lgpd_retention_tick.php`, `tasks_recurrence_tick.php`, `push/tick.php` (rota HTTP — opcional em Linux com cron CLI).

> 💡 Em Linux com cron CLI (`php /var/www/yuris/public/api/...tick.php`), o bypass CLI ignora `CRON_TOKEN`. Em Windows ou Docker com cron via HTTP/curl, o token é obrigatório.

---

## ── Billing ──

### `BILLING_GATEWAY` 🔴 (em prod)
Valores: `null` | `stripe` | `mercadopago` | `asaas`
Default: `null`

**Em `APP_ENV=production`, NÃO pode ser `null`** — `Gateway::driver()` lança RuntimeException e o app não sobe.

Quem usa: `App\Services\Billing\Gateway`, modal de cobrança no Painel Master.

Credenciais por gateway (preencher de acordo com `BILLING_GATEWAY` escolhido — todas opcionais se gateway = `null`):

- `STRIPE_SECRET_KEY`, `STRIPE_PUBLISHABLE_KEY`, `STRIPE_WEBHOOK_SECRET`
- `MERCADOPAGO_ACCESS_TOKEN`, `MERCADOPAGO_PUBLIC_KEY`
- `ASAAS_API_KEY`, `ASAAS_BASE_URL`

---

## ── DPO LGPD (Etapa 5) ──

### `DPO_NAME` 🟠
Quem usa: `/dpo.php` (página pública), e-mails LGPD ao titular

### `DPO_EMAIL` 🟠
Validação: e-mail válido.
Usado em: `/lgpd/solicitar.php`, e-mails de aviso

### `DPO_PHONE` 🟡
### `DPO_ADDRESS` 🟡

**Em prod, todos devem estar preenchidos**. Em dev, pode deixar vazio se DPO ainda não designado.

---

## ── Intimações (DJEN) ──

### `DJEN_BASE_URL` 🟠
Default: `https://comunicaapi.pje.jus.br/api/v1`
Não mexer (endpoint público do CNJ).

### `DATAJUD_API_KEY` 🟡
Default: vazio
Chave pública do CNJ pra DataJud (enriquecimento de metadados).
Sem ela, busca DJEN continua, só não enriquece com DataJud.

### `DATAJUD_BASE_URL` 🟡
Default: `https://api-publica.datajud.cnj.jus.br`
Não mexer.

---

## ── Intimações (AASP) ──

### `AASP_BASE_URL` 🟡
Default: `https://intimacaoapi.aasp.org.br`
Não mexer.

### `AASP_RATE_LIMIT_MS` 🟡
Default: `1000` (ms)
Pausa entre chamadas pra AASP. AASP não declara rate limit, mas mantemos 1s defensivamente.

### `AASP_MAX_DAYS` 🟡
Default: `30` (dias)
Janela retroativa máxima na busca. Aumentar exige mais memória — DJEN pode crescer muito.

> 💡 Configuração de chave AASP **não vai no `.env`** — é cifrada no banco (tabela `aasp_integrations`). Gerenciada via UI: aba Intimações → AASP.

---

## ── WhatsApp (Evolution API) ──

### `EVOLUTION_BASE_URL` 🟡
Default: vazio
Exemplo: `https://evolution.yuris.app.br`
Self-hosted. Configure no `.env` E na UI WhatsApp → Configurações (UI sobrescreve).

### `EVOLUTION_API_KEY` 🟡
Default: vazio
Chave da sua instância Evolution.

### `EVOLUTION_INSTANCE` 🟡
Default: vazio
Nome da instância configurada na Evolution.

### `EVOLUTION_TLS_VERIFY` 🟠
Default: `true`
**NUNCA `false` em produção** (LGPD P1 2C.4 — protege contra MITM).
Em dev local com cert self-signed, pode setar `false`.

---

## ── E-mail transacional ──

### `MAIL_DRIVER` 🟠
Valores: `log` | `smtp` | `sendmail`
Default: `log` (grava em `storage/mail.log`)

Em prod use `smtp` apontando pra serviço transacional (AWS SES, Sendgrid, Postmark, etc.).

### `MAIL_HOST` 🟠 (obrigatório se `MAIL_DRIVER=smtp`)
Exemplo: `email-smtp.us-east-1.amazonaws.com`

### `MAIL_PORT` 🟠
Default: `587` (STARTTLS) — recomendado.
Outros: `465` (SSL/TLS direto), `25` (plaintext — não usar).

### `MAIL_USERNAME` 🟠
SMTP user.

### `MAIL_PASSWORD` 🟠
SMTP password.
Para AWS SES, é a senha SMTP (não a access key).

### `MAIL_FROM_ADDRESS` 🟠
Default: `noreply@yuris.app.br`
Deve estar verificado no domínio do gateway (SES exige verificação).

### `MAIL_FROM_NAME` 🟡
Default: `Yuris`

---

## Validação em produção

Antes de subir, valide manualmente:

```bash
cd /var/www/yuris
sudo -u www-data php -r "
require 'app/Helpers/EnvLoader.php';
try {
    \App\Helpers\EnvLoader::load();
    \App\Helpers\EnvLoader::validateProduction();
    echo \"OK — .env valido para producao\n\";
} catch (\Throwable \$e) {
    echo 'ERRO: ' . \$e->getMessage() . \"\n\";
    exit(1);
}
"
```

Esperado: `OK — .env valido para producao`

Se falhar com mensagem específica, corrija a variável apontada e tente de novo.

---

## Rotina de rotação anual

A cada 12 meses, ou após qualquer incidente:

| Variável | Como rotacionar |
|---|---|
| `CRON_TOKEN` | Gerar novo + atualizar `.env` + reload Apache |
| `DB_PASS` | `ALTER USER` no MariaDB + atualizar `.env` |
| `STRIPE_*` etc. | Rotacionar no portal do gateway + atualizar `.env` |
| `MFA_ENCRYPTION_KEY` | ⚠️ Procedimento especial (vide §"Cifra at-rest" acima) |
| `APP_ENCRYPTION_KEY` | ⚠️ Mesmo procedimento |

---

## Variáveis NUNCA devem entrar no git

`.gitignore` já protege:
```
.env
.env.*
!.env.example
```

Se acidentalmente alguma chave for commitada:
1. **Rotacione imediatamente** todas as chaves comprometidas.
2. `git filter-repo` ou similar para remover do histórico (NÃO basta `git rm`).
3. Force-push (depois de avisar a equipe).
4. Registre incidente em `security_incidents` (Master → Incidentes).
