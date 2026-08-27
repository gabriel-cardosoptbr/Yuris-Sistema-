# Documentação — Módulo de Webhooks (Yuris)

**Versão:** 2.0
**Última atualização:** 2026-05-25
**Escopo:** webhooks **outbound** configuráveis pelo usuário, focados em automações operacionais (n8n, Make, Zapier, CRMs externos, robôs próprios).

> ⚠️ Este módulo NÃO cobre eventos de planos, pagamentos ou assinaturas do SaaS — esses são tratados separadamente pelo gateway de billing.

---

## 1. O que são webhooks no Yuris

Webhooks são notificações HTTP POST que o Yuris envia para uma URL externa quando um evento operacional acontece (lead criado, processo atualizado, prazo vencendo, intimação recebida, etc.). Você cadastra a URL no painel e escolhe quais eventos deseja receber.

Caso de uso típico:
- Lead novo no CRM → webhook dispara → n8n cria card no Trello + envia WhatsApp pra equipe.
- Prazo de processo vencendo → webhook → robô interno notifica o advogado responsável.
- Cliente atualizado → webhook → CRM externo sincroniza dados.

---

## 2. Como cadastrar um webhook

1. Acesse **Sidebar → Automações → Webhooks**.
2. Clique em **Novo Webhook**.
3. Preencha:
   - **Nome** — texto livre pra identificar (ex: `n8n Lead Novo`).
   - **URL de destino** — endereço público que recebe o POST (HTTPS recomendado).
   - **Secret HMAC-SHA256** (opcional) — chave compartilhada pra validar assinatura no seu lado.
   - **Status** — Ativo/Inativo (toggle rápido sem deletar).
4. Em **Eventos a escutar**, marque os eventos desejados (ou clique "Todos os eventos").
5. Em **Configurações avançadas** (collapsable), opcionalmente ajuste:
   - **Modo de payload** (LGPD): `Mascarado` (padrão), `Mínimo` ou `Completo`.
   - **Escopo**: `Apenas este escritório`, `Matriz + filiais vinculadas` ou `Apenas filial`.
   - **Timeout**: 1–60 segundos (padrão 10).
   - **Retry automático** + máx. tentativas (1–10, padrão 3).
   - **Headers customizados**: JSON `{"Authorization":"Bearer xxx", "X-Outro":"valor"}`.
6. Clique **Salvar**.

Botão **Testar** (visível no modo edição) envia um evento `webhook.test` pra você verificar que a URL responde.

---

## 3. Eventos disponíveis (151 eventos em 15 categorias)

> A lista completa, com payload de exemplo por evento, está disponível na aba **Catálogo** do painel.

| Categoria | Eventos |
|---|---|
| **prospeccao_clientes** (4) | `cliente.created`, `cliente.updated`, `cliente.deleted`, `cliente.converted_to_processo` |
| **leads** (5) | `lead.created`, `lead.updated`, `lead.converted`, `lead.deleted`, `lead.status_changed` |
| **contatos** (3) | `contato.created`, `contato.updated`, `contato.deleted` |
| **prospeccao_cards** (18) | `card.created`, `card.updated`, `card.deleted`, `card.stage_changed`, `card.responsavel_changed`, `card.tag_added`, `card.comment_added`, `card.file_uploaded`, `card.followup_created/completed`, `card.dados_pessoais.updated`, `card.atendimento.updated`, `card.qualificacao.updated`, `card.documentos.updated`, `card.observacoes.updated`, `card.historico.updated`, `card.financeiro.updated`, `card.contrato.updated` |
| **pipeline** (5) | `pipeline.created`, `pipeline.updated`, `pipeline.stage_created`, `pipeline.stage_updated`, `pipeline.stage_deleted` |
| **processos** (29) | `processo.created/updated/deleted`, `processo.status_changed`, `processo.responsavel_changed`, `processo.etapa_changed`, `processo.prazo_created/updated/completed/vencendo`, `processo.tarefa_created/completed/atrasada`, `processo.andamento_added`, `processo.documento_uploaded`, `processo.audiencia_created/updated/realizada` + aba-* updates |
| **tarefas** (6) | `task.created/updated/completed/archived/due_soon/overdue` |
| **financeiro** (10) | `financeiro.receita_created/despesa_created`, `financeiro.updated/status_changed/paid/overdue/deleted`, `financeiro.parcela_created/parcela_paid`, `financeiro.relatorio_dre` |
| **advogados** (9) | `advogado.created/updated/activated/deactivated/deleted`, `advogado.linked_to_matriz`, `advogado.linked_to_filial`, `advogado.unlinked`, `advogado.oab_updated` |
| **usuarios** (17) | `usuario.created/updated/deleted/activated/deactivated`, `usuario.role_changed`, `usuario.permission_changed`, `usuario.password_changed/senha_changed`, `usuario.mentioned`, `usuario.login`, `usuario.login_success/login_failed/logout`, `usuario.2fa_enabled/disabled/required` |
| **whatsapp** (8) | `whatsapp.message_received/sent`, `whatsapp.conversation_started/closed/assigned/unassigned`, `whatsapp.handoff_requested/completed` |
| **lgpd** (12) | `lgpd.request_created/updated/completed/denied`, `lgpd.consent_given/revoked`, `lgpd.data_exported/deleted/anonymized`, `lgpd.privacy_document_published`, `lgpd.terms_accepted`, `lgpd.cookies_accepted` |
| **seguranca** (7) | `security.incident_created/updated/resolved/reported`, `security.suspicious_login`, `security.access_denied`, `security.permission_violation` |
| **auditoria** (6) | `audit.log_created`, `system.error`, `system.warning`, `integration.webhook_failed/recovered`, `integration.api_error` |
| **sistema** (12) | `arquivo.uploaded`, `comentario.created/updated/deleted`, `notificacao.created`, `relatorio.generated`, `agente.resposta`, `chat.mensagem`, `webhook.test` etc. |

> **Disparos automáticos hoje** (etapas 10/11): `lgpd.request_created`, `lgpd.consent_given`, `lgpd.terms_accepted`, `security.incident_created`, `usuario.login` + os 19 disparos já existentes em cards/processos/tarefas/usuários (etapas anteriores).
> Demais eventos do catálogo estão **disponíveis pra inscrição** mas o disparo automático (`fire()` no call site) será adicionado conforme a feature for tocada.

> **Eventos PROIBIDOS neste módulo**: plan.*, subscription.*, payment.*, gateway.*, checkout.*, invoice.*. Estes não existem no catálogo por design.

---

## 4. Payload padrão (envelope v2)

```json
{
  "event": "card.created",
  "event_id": "evt_695b2f6294a367cb79bbdb8ae",
  "occurred_at": "2026-05-25T14:30:00-03:00",
  "tenant":       { "id": 1,  "name": "Escritório X" },
  "organization": { "id": 1,  "type": "matriz", "name": "Matriz SP", "matriz_id": null },
  "actor":        { "id": 5,  "role": "owner", "email": "u***@dominio.com" },
  "data": {
    "id": 123,
    "type": "card",
    "attributes": {
      "nome": "Jose Silva",
      "email": "j***@example.com",
      "telefone": "*******4321",
      "cpf": "123.***.***-01",
      "etapa": "Qualificacao"
    }
  },
  "metadata": {
    "source": "yuris",
    "environment": "production",
    "version": "2.0",
    "payload_mode": "masked"
  }
}
```

**Campos importantes:**
- `event_id` — único por evento; compartilhado por todas as deliveries do mesmo trigger (use pra **deduplicar** no destino).
- `occurred_at` — ISO 8601 com timezone.
- `tenant` / `organization` — identificam o escritório dono do evento.
- `actor` — usuário logado que executou a ação (null se evento de sistema).
- `data.attributes` — payload mascarado conforme `payload_mode` do endpoint.
- `metadata.version` — `"2.0"` (envelope atual).

---

## 5. Headers HTTP enviados

```
Content-Type: application/json
User-Agent: Yuris-Webhook/2.0
X-Yuris-Event:     card.created
X-Yuris-Delivery:  evt_695b2f6294a367cb79bbdb8ae
X-Yuris-Timestamp: 1748192400
X-Yuris-Tenant:    1
X-Yuris-Signature: sha256=hex(HMAC_SHA256(secret, timestamp + "." + raw_payload))
```

Headers customizados configurados no endpoint são **anexados** após os Yuris-*. Você não pode sobrescrever `X-Yuris-*`.

---

## 6. Como validar a assinatura HMAC

A assinatura usa o **timestamp** + body raw, separados por ponto:

```
signature = HMAC_SHA256(secret, timestamp + "." + raw_payload)
```

Compare com `X-Yuris-Signature` (formato `sha256=<hex>`). **Não confie em payload sem validar.**

> Recomenda-se rejeitar requisições com `X-Yuris-Timestamp` mais antigo que 5 minutos pra mitigar replay.

### PHP

```php
function valida_yuris(string $secret, string $rawBody, array $headers): bool {
    $ts  = $headers['X-Yuris-Timestamp'] ?? '';
    $sig = $headers['X-Yuris-Signature'] ?? '';
    if (!$ts || !$sig) return false;
    if (abs(time() - (int)$ts) > 300) return false; // anti-replay 5min
    $expected = 'sha256=' . hash_hmac('sha256', $ts . '.' . $rawBody, $secret);
    return hash_equals($expected, $sig);
}
```

### Node.js

```js
const crypto = require('crypto');

function validaYuris(secret, rawBody, headers) {
  const ts  = headers['x-yuris-timestamp'];
  const sig = headers['x-yuris-signature'];
  if (!ts || !sig) return false;
  if (Math.abs(Date.now() / 1000 - parseInt(ts)) > 300) return false;
  const expected = 'sha256=' + crypto.createHmac('sha256', secret)
    .update(ts + '.' + rawBody)
    .digest('hex');
  return crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(sig));
}
```

### Python

```python
import hmac, hashlib, time

def valida_yuris(secret: str, raw_body: str, headers: dict) -> bool:
    ts  = headers.get('X-Yuris-Timestamp', '')
    sig = headers.get('X-Yuris-Signature', '')
    if not ts or not sig:
        return False
    if abs(time.time() - int(ts)) > 300:
        return False
    expected = 'sha256=' + hmac.new(
        secret.encode(), (ts + '.' + raw_body).encode(), hashlib.sha256
    ).hexdigest()
    return hmac.compare_digest(expected, sig)
```

---

## 7. Como configurar no n8n

1. Crie um workflow novo.
2. Adicione um node **Webhook** (trigger).
3. Configure:
   - **HTTP Method**: POST
   - **Authentication**: None (validação via HMAC no próximo node)
   - **Response Mode**: Immediately (ou On Received se quiser corpo customizado)
4. Pegue a URL gerada pelo n8n (ex: `https://n8n.seu-dominio.com/webhook/abc123`).
5. No painel Yuris, cole a URL e gere/cole um secret.
6. Adicione um node **Code** (JavaScript) após o Webhook pra validar HMAC — ver código JS acima.
7. Use os campos do payload nos nodes seguintes (`{{ $json.data.attributes.nome }}`, etc.).

> Dica: o `X-Yuris-Event` permite usar um **node Switch** pra rotear por tipo de evento sem precisar criar 1 webhook por evento.

---

## 8. Reenviar entregas falhadas

Toda delivery aparece na **tabela "Log de Entregas Recentes"** com:
- Evento, Webhook, Status HTTP, Duração, Status (Sucesso/Falhou/Retry/Cancelada/Pendente), Tentativa, Data.

Pra entregas em estado **terminal** (`success`, `failed`, `canceled`), aparece o **botão ↻ Reenviar** na coluna de ações. Click cria uma **nova delivery** (com novo `event_id`) e tenta entregar imediatamente — não altera a delivery original.

Em estado `retrying` (aguardando próximo backoff): aparece "aguardando" sem botão — o worker pega automaticamente.

---

## 9. Rotacionar secret

1. Abra **Editar webhook**.
2. Em "Configurações avançadas", clique **Rotacionar Secret**.
3. Confirme — o secret antigo é invalidado **imediatamente**.
4. O novo secret é mostrado **uma única vez** — anote.
5. Atualize a validação no destino antes que ele rejeite as próximas deliveries.

> O secret nunca aparece em logs de auditoria, mas fica armazenado em DB (em texto plano hoje — encriptação at-rest é roadmap futuro).

---

## 10. Modos de payload (LGPD)

Configurado por endpoint via `payload_mode`:

| Modo | O que envia em `data.attributes` |
|---|---|
| **minimal** | só `id`, `type`, `status` (drop demais) |
| **masked** (padrão) | shape completo, mas mascara chaves sensíveis conhecidas |
| **full** | tudo bruto (exige decisão consciente pelo controlador) |

**Chaves mascaradas em `masked`** (case-insensitive):
- `email`/`titular_email`/`user_email` → `j***@dominio.com`
- `telefone`/`phone`/`celular`/`whatsapp`/`numero` → `*******4321`
- `cpf` → `123.***.***-01`
- `cnpj` → `12.***.***/0001-**`
- `senha`/`password`/`secret`/`token`/`hash`/`senha_hash`/`api_key` → `***`

Recomendação: **mantenha `masked`** salvo necessidade explícita. `full` deve ser justificado num RIPD/DPIA.

---

## 11. Worker assíncrono (cron)

Por padrão o Yuris dispara webhooks **síncronos** (no mesmo request). Pra requisições não travarem se a URL externa estiver lenta, ative o **modo async**:

1. No `.env`, adicione: `WEBHOOK_DISPATCH_MODE=async`
2. Agende o worker `bin/webhook_worker.php` pra rodar a cada **1 minuto**.

### Linux (cron)

```
* * * * * /usr/bin/php /var/www/sistema_vendas/bin/webhook_worker.php >> /var/log/yuris-webhook.log 2>&1
```

### Windows (Task Scheduler)

1. Abra **Task Scheduler** → Create Task.
2. **General**: nome "Yuris Webhook Worker", Run whether logged on or not.
3. **Triggers**: New → Daily → Start now → Repeat every 1 minute → Indefinitely.
4. **Actions**: New →
   - Program/script: `C:\xampp\php\php.exe`
   - Add arguments: `C:\xampp\htdocs\sistema_vendas\bin\webhook_worker.php`
   - Start in: `C:\xampp\htdocs\sistema_vendas`
5. **Conditions**: desmarcar "Start only if computer is on AC power" (se laptop).

### Validar que o worker está rodando

```sh
# Linux
tail -f /var/log/yuris-webhook.log

# Windows PowerShell
Get-Content C:\xampp\htdocs\sistema_vendas\bin\worker.log -Wait
```

Ou rode manualmente uma vez com log verbose:

```sh
WEBHOOK_WORKER_LOG=1 php bin/webhook_worker.php
# imprime "[webhook_worker] processados=N ok=N fail=N cancel=N"
```

### Retry com backoff

Falhas não permanentes (HTTP non-2xx, timeout, conn refused) são reagendadas:
- Tentativa 1 falha → próxima em 60s
- Tentativa 2 falha → próxima em 300s (5min)
- Tentativa 3 falha → próxima em 1800s (30min)
- Após `max_retries` atingido → marca `failed` (definitivo, exige Reenviar manual).

---

## 12. Boas práticas

1. **Use HTTPS sempre**. HTTP é aceito mas não recomendado.
2. **Configure um secret** e valide HMAC no destino.
3. **Anti-replay**: rejeite requests com `X-Yuris-Timestamp` mais antigo que 5min.
4. **Idempotência**: deduplique por `event_id` no destino (o mesmo `event_id` pode chegar 2x em caso de retry ou reenvio manual).
5. **Resposta rápida**: responda `2xx` em < 5s. Use enfileiramento interno se precisar processar lentamente. Yuris timeout padrão é 10s.
6. **LGPD**: prefira `payload_mode=masked` ou `minimal` sempre que possível. Documente em RIPD se usar `full`.
7. **Não exponha URLs internas**: o SSRF guard bloqueia `localhost`, RFC1918, link-local e metadata cloud (AWS/GCP/Azure). Pra dev local, `.env WEBHOOK_ALLOW_PRIVATE=1`.
8. **Headers customizados** podem conter tokens — eles ficam em DB; rotacione periodicamente.

---

## 13. Troubleshooting

**Sintoma: minha URL não recebe nada**
- Veja a aba "Log de Entregas Recentes" pra status real.
- Se aparece `SSRF-blocked: ...` → URL não é roteável publicamente. Use URL pública ou `WEBHOOK_ALLOW_PRIVATE=1` em dev.
- Se aparece `HTTP no_response` → conn refused / timeout. Verifique firewall, DNS, endpoint up.
- Verifique que o evento está marcado no webhook + status do webhook é Ativo.

**Sintoma: assinatura HMAC inválida no meu lado**
- Confirme que está usando `timestamp + "." + raw_payload` (não só body).
- Use o raw body (bytes), não o re-serializado JSON do framework.
- Compare em hex lowercase, com `hash_equals` / `crypto.timingSafeEqual`.

**Sintoma: campos faltando no payload**
- `payload_mode=minimal` envia só `id`/`type`/`status`. Mude pra `masked` ou `full`.
- Alguns eventos não preenchem todos os campos do `data.attributes` — depende do call site.

**Sintoma: receber o mesmo evento 2x**
- Em retries: `event_id` é o mesmo → deduplique por ele.
- Em reenvio manual via painel: `event_id` é NOVO. Se quiser idempotência, use chave de negócio (`data.id` + `event` + `occurred_at`).

**Sintoma: webhook trava criação de processo / card / etc.**
- Você está em modo `sync` (default) e URL está lenta. Solução: mude `.env WEBHOOK_DISPATCH_MODE=async` + agende o worker. Requests passam a retornar instantaneamente.

**Sintoma: worker não está processando**
- Verifique se o cron/Task Scheduler está realmente rodando: `WEBHOOK_WORKER_LOG=1 php bin/webhook_worker.php` deve imprimir resumo.
- Confira que o user do cron tem permissão de DB.
- Veja deliveries `pending`: `SELECT * FROM webhook_deliveries WHERE status='pending' OR (status='retrying' AND scheduled_retry_at <= NOW()) LIMIT 10`.

**Sintoma: muitas falhas, secret comprometido suspeito**
- Use **Rotacionar Secret** no painel. Invalida o atual imediatamente.
- Considere desativar o webhook (toggle ativo=0) até investigar.

---

## 14. Auditoria e logs

Toda ação fica registrada em `account_audit_log`:
- `webhook.created/updated/deleted/tested/secret_rotated/resent`

Toda delivery fica em `webhook_deliveries` (state machine completa) e em paralelo em `webhook_logs` (legacy, mantido por compat).

Pra inspecionar:
```sql
-- todas as deliveries do meu tenant (últimas 50)
SELECT d.id, d.event_code, d.status, d.tentativa, d.response_status, d.created_at
FROM webhook_deliveries d
WHERE d.account_id = <SEU_ACCOUNT_ID>
ORDER BY d.created_at DESC LIMIT 50;

-- breakdown de sucesso/falha por endpoint
SELECT e.nome, d.status, COUNT(*) AS qtd
FROM webhook_deliveries d
JOIN webhook_endpoints e ON e.id = d.webhook_endpoint_id
WHERE d.account_id = <SEU_ACCOUNT_ID>
GROUP BY e.nome, d.status;
```

---

## 15. Limites e roadmap

**Limites atuais:**
- Timeout máximo: 60s por delivery (padrão 10s).
- Max retries: 10 por delivery (padrão 3).
- Backoff fixo: 60s, 300s, 1800s (não configurável por endpoint ainda).
- `response_body` armazenado até 4000 chars.
- Secret armazenado em DB **plaintext** (encriptação at-rest é roadmap).

**Roadmap próximas etapas:**
- Encriptar `secret` at-rest com AES-256-GCM.
- Backoff customizável por endpoint.
- Métricas de saúde por endpoint (% sucesso últimas 24h, alerta automático).
- Auto-desativação após N falhas consecutivas (com `integration.webhook_failed` disparado).
- Webhook batching (agrupar N eventos do mesmo tipo num único POST).

---

## 16. Referência rápida

- **Painel**: `/sistema_vendas/public/webhooks.php`
- **API CRUD**: `/sistema_vendas/public/api/webhooks.php`
- **Worker**: `php bin/webhook_worker.php` (cron 1min)
- **Dispatcher**: `app/Webhooks/WebhookDispatcher.php`
- **Builder**: `app/Webhooks/WebhookPayloadBuilder.php`
- **SSRF guard**: `app/Core/WebhookUrlValidator.php`
- **PII masker**: `app/Lgpd/PayloadMasker.php`
- **Retry policy**: `app/Webhooks/WebhookRetryPolicy.php`
- **Tabelas**: `webhook_endpoints`, `webhook_events`, `webhook_deliveries`, `webhook_event_queue`, `webhook_logs` (legacy)
- **Migrações**: `database/migrations/067_*.sql` a `070_*.sql`
- **Seeder de eventos**: `php database/seed_webhook_events.php`
