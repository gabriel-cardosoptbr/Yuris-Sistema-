# Checklist Pre-Flight para Deploy em Produção — Yuris

**Versão:** 1.0 — 2026-05-23
**Aplicação:** obrigatório antes de qualquer go-live (produção pública) ou release que afete dados pessoais em escala.
**Responsável:** Equipe Técnica + DPO.

---

## Como usar

Imprimir/copiar este checklist por release. Cada item deve estar **assinado pelo responsável** antes do deploy. Itens vermelhos (🔴) são bloqueadores — não subir sem resolução.

---

## A — Configuração de ambiente

- [ ] 🔴 `.env` com **todas as variáveis obrigatórias** preenchidas (`EnvLoader::validateProduction` retorna OK):
  - [ ] `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` (senha forte, ≥ 16 chars)
  - [ ] `MFA_ENCRYPTION_KEY` (32 bytes / 256 bits, hex ou base64)
  - [ ] `CRON_TOKEN` (≥ 32 chars aleatórios)
  - [ ] `BILLING_GATEWAY` = `stripe` | `mercadopago` | `asaas` (NÃO `null`/`dev`)
  - [ ] Credenciais do gateway escolhido
  - [ ] `EVOLUTION_TLS_VERIFY=true`
  - [ ] `APP_ENV=production`
  - [ ] `APP_DEBUG=false`
- [ ] 🔴 `.env` está fora do versionamento (`git check-ignore .env` retorna `.env`).
- [ ] 🔴 `.env` tem permissão restrita (600 ou 640) — `chmod 640 .env`.
- [ ] Backup de `.env` em local seguro (vault de senhas), fora do servidor.

## B — Banco de dados

- [ ] 🔴 Todas as **migrations aplicadas** em ordem (até `055_data_processors.sql`):
  ```bash
  ls database/migrations/ | sort
  # Conferir contra information_schema.TABLES
  ```
- [ ] 🔴 **Triggers de imutabilidade** ativos nas 9 tabelas:
  - `master_audit_log`, `account_audit_log`, `processo_history`, `card_history`, `task_history`, `anonymization_log`, `lgpd_request_events`, `term_acceptances`, `security_incident_events`, `data_processor_history`
  - Verificar: `SELECT * FROM information_schema.TRIGGERS WHERE TRIGGER_NAME LIKE 'trg_%_no_%';` (deve retornar 20 linhas)
- [ ] 🔴 Usuário da aplicação tem **permissões mínimas** (SELECT, INSERT, UPDATE, DELETE — sem GRANT, DROP, ALTER USER).
- [ ] 🔴 Senha do `root` MariaDB **rotacionada** (não padrão XAMPP).
- [ ] `binary log` ativo para point-in-time recovery.
- [ ] **Backup pré-deploy** executado e validado (restaurar em staging e smoke test).

## C — Web server (Apache)

- [ ] 🔴 **HTTPS forçado** — redirect 301 de HTTP para HTTPS.
- [ ] 🔴 **TLS 1.2+** apenas — TLS 1.0/1.1 desabilitados.
- [ ] Certificado SSL válido (Let's Encrypt ou similar), com renovação automática configurada.
- [ ] **Headers de segurança** ativos:
  - [ ] `Strict-Transport-Security: max-age=31536000; includeSubDomains`
  - [ ] `X-Content-Type-Options: nosniff`
  - [ ] `X-Frame-Options: SAMEORIGIN`
  - [ ] `Referrer-Policy: strict-origin-when-cross-origin`
  - [ ] `Content-Security-Policy: ...` (adaptado ao app)
- [ ] **`.htaccess`** em `storage/`, `database/`, `app/` bloqueando acesso direto.
- [ ] Logs do Apache rotacionados (`logrotate` configurado).
- [ ] Painel Master `/master.php` ip-allowlisted (recomendado).

## D — Aplicação PHP

- [ ] 🔴 `display_errors = Off` no `php.ini`.
- [ ] 🔴 `expose_php = Off`.
- [ ] `log_errors = On` apontando para arquivo seguro.
- [ ] Versão PHP suportada (≥ 8.0 com suporte de segurança).
- [ ] Extensões necessárias instaladas: `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `zip`, `curl`.
- [ ] `opcache` ativo em produção.
- [ ] `session.cookie_secure = 1`, `session.cookie_httponly = 1`, `session.cookie_samesite = Lax`.

## E — LGPD compliance

- [ ] 🔴 **DPO designado** e e-mail funcional (`dpo@[DOMÍNIO]` ou equivalente).
- [ ] 🔴 Páginas legais publicadas e acessíveis no rodapé:
  - [ ] `/privacidade.php`
  - [ ] `/termos.php`
  - [ ] `/cookies.php`
  - [ ] `/lgpd.php`
  - [ ] `/dpo.php`
- [ ] 🔴 **Banner de cookies** ativo no primeiro acesso.
- [ ] 🔴 **Checkbox de aceite** de termos+privacidade nos formulários de cadastro (`/login.php`, `/master_login.php`, novos cadastros).
- [ ] Canal público `/lgpd/solicitar.php` testado end-to-end (criação + envio de e-mail ao DPO).
- [ ] **RAT inicial** (`LGPD_RAT_INICIAL.md`) revisado e atualizado.
- [ ] Inventário de operadores (`INVENTARIO_OPERADORES.md`) atualizado com gateway e SMTP reais.
- [ ] DPAs assinados (ou status "dispensado" justificado) para todos os operadores marcados como ativos no Painel Master.

## F — Cron jobs

- [ ] 🔴 Agendador externo configurado (Linux cron / Windows Task Scheduler / serviço dedicado).
- [ ] `lgpd_retention_tick.php` agendado (diário ou horário).
- [ ] `tasks_recurrence_tick.php` agendado.
- [ ] `webhook_dispatch.php` (se aplicável) agendado.
- [ ] `CRON_TOKEN` único e armazenado de forma segura no agendador.
- [ ] Logs de cron com retenção mínima de 30 dias.

## G — Monitoramento e alertas

- [ ] Monitoramento de uptime (UptimeRobot / Pingdom / similar).
- [ ] Monitoramento de erro PHP (Sentry / Bugsnag — se contratado, registrar como operador).
- [ ] Alerta para falhas críticas: banco fora, espaço em disco < 10%, latência > 5s.
- [ ] Alerta de novos incidentes registrados em `security_incidents`.
- [ ] Dashboard de KPIs financeiros e operacionais (Painel Master → Dashboard).

## H — Backup

- [ ] 🔴 Política de backup operacional conforme `POLITICA_BACKUP_RECUPERACAO.md`.
- [ ] Off-site cifrado configurado e testado.
- [ ] Teste de restore mensal agendado.
- [ ] Chave de cifragem dos backups custodiada separadamente.

## I — Acessos administrativos

- [ ] 🔴 **MFA habilitado** para o(s) super_admin(s) iniciais.
- [ ] super_admin não usado para tarefas operacionais — apenas administrativas.
- [ ] Usuários iniciais cadastrados com senhas únicas + obrigação de troca no primeiro acesso.
- [ ] Lista de quem tem qual acesso documentada e arquivada.

## J — Testes finais

- [ ] Smoke test funcional dos principais fluxos:
  - [ ] Cadastro de novo usuário em tenant existente;
  - [ ] Criação de card → conversão em processo;
  - [ ] Envio/recebimento de WhatsApp (se gateway Evolution configurado);
  - [ ] Cobrança de uma fatura via gateway real (modo sandbox primeiro);
  - [ ] Solicitação LGPD: titular pede acesso, DPO atende, e-mail enviado;
  - [ ] Anonimização de um contato fictício;
  - [ ] Geração de export ZIP (portabilidade);
  - [ ] Registro de incidente simulado + notificação ANPD simulada.
- [ ] Pentest externo realizado (recomendado anual; obrigatório para produção pública pela primeira vez).
- [ ] OWASP ZAP / Burp passive scan sem findings high.

## K — Comunicação ao público

- [ ] Política de privacidade revisada por advogado(a) com expertise LGPD.
- [ ] Termos de uso revisados por advogado(a).
- [ ] Comunicação de lançamento (e-mail/blog) menciona compromisso com proteção de dados e link para `/lgpd`.

## L — Pós-deploy (primeiros 7 dias)

- [ ] Monitorar logs intensivamente — qualquer anomalia investigada em < 1h.
- [ ] Reunião diária com equipe técnica + DPO para revisar incidentes/alertas.
- [ ] No final da semana: revisão consolidada → ajustes ou rollback se necessário.

---

## Sign-off

**Responsável técnico:** __________________________________ Data: ___/___/___

**DPO:** __________________________________ Data: ___/___/___

**Diretoria:** __________________________________ Data: ___/___/___

> Sem as 3 assinaturas, deploy NÃO é autorizado.

---

## Revisão deste checklist

Anual ou após release com lições aprendidas relevantes.
