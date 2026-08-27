# Política de Retenção e Eliminação de Dados — Yuris

**Versão:** 1.0 — 2026-05-23
**Mantenedor:** DPO + Equipe Técnica
**Aplicação:** todos os dados pessoais tratados pela plataforma Yuris.
**Fonte vivente:** tabela `retention_policies` no banco (gerenciada via `Painel Master → Retenção`).

---

## 1. Propósito

Definir prazos máximos de retenção e ações automáticas de eliminação ou anonimização para cumprir o princípio da **necessidade** (LGPD Art. 6 III) e o direito à **eliminação** (Art. 16 e Art. 18 VI).

## 2. Princípios

- **Minimização:** manter dados apenas pelo tempo estritamente necessário à finalidade que justificou a coleta.
- **Reversibilidade restrita:** anonimização é preferível à eliminação quando há base legal para conservar agregados (estatística, gestão).
- **Documentação:** todo descarte é registrado em `anonymization_log` (imutável).
- **Automação:** rotinas diárias aplicam políticas sem depender de intervenção manual.

## 3. Políticas vigentes

### 3.1 Estado atual (snapshot — atualizado a partir de `retention_policies`)

| Entidade | Ação | Prazo (dias) | Base legal / justificativa |
|----------|------|--------------|----------------------------|
| `webhook_logs` | Purge físico | 90 | Logs de entrega de webhook não têm valor probatório legal após 90 dias. Minimização (LGPD Art. 6 III). |
| `login_attempts` | Purge físico | 90 | Marco Civil Art. 15 (180 dias) — usamos prazo menor para tentativas falhas; bem-sucedidas seriam mantidas. |
| `whatsapp_messages_payload` | Anonimizar (NULL no `raw_payload`) | 30 | `raw_payload` contém metadata + `mediaKey` que não precisa ficar após processado. NULL na coluna preserva metadados da mensagem. |
| `emails_outbox_body` | Purge físico do body | 7 | Body HTML/text de e-mail enviado pode conter tokens/links sensíveis. Após confirmação de envio (7d) o corpo é purgado. |
| `gateway_events_received` | Purge físico | 90 | Idempotency key já processada; payload sensível não deve persistir. |

> **Para atualizar:** ajustar via `Painel Master → Retenção` (campo "Prazo (dias)") — alteração registrada em `master_audit_log`.

## 4. Como as políticas são executadas

### 4.1 Cron diário
- `/api/lgpd_retention_tick.php` — autenticado por `CRON_TOKEN`.
- Roda 1× por hora (recomendado configurar agendador externo).
- Lock de 1h entre execuções previne sobreposição.
- Suporta `?dry_run=1` para simular sem aplicar.

### 4.2 Allowlist de entidades
Apenas entidades explicitamente listadas no cron podem sofrer ação automática. Adicionar nova entidade exige:
1. Inserir/atualizar linha em `retention_policies`;
2. Atualizar allowlist no código `lgpd_retention_tick.php`;
3. Atualizar este documento;
4. Aprovação do DPO.

### 4.3 Anonimização vs. purge físico
- **`anonimizar`** — substitui PII por valores neutros (`Anonymizer::user()`, `::contato()`, etc.); preserva FKs e contagens.
- **`purge_fisico`** — `DELETE` real; usado quando não há necessidade de preservar histórico estatístico.

## 5. Retenção de outras entidades (não-automatizada)

| Entidade | Prazo | Política |
|----------|-------|---------|
| `users` | Indefinido enquanto ativo + 5 anos após `users.ativo=0` | Manutenção legal (Art. 16 II - obrigação legal/regulatória). Após 5 anos, anonimizar via `Anonymizer::user()`. |
| `accounts` | Indefinido enquanto ativa + 5 anos após suspensão | Idem. |
| `contatos` | Indefinido enquanto vinculados a card/processo ativo | Anonimizar via `Anonymizer::contato()` quando explicitamente solicitado pelo titular ou quando vinculação for removida + 1 ano. |
| `cards` | Indefinido enquanto pipeline ativo | Anonimizar dados de prospect 1 ano após `cards.deleted_at`. |
| `processos` | Vinculação à causa judicial — manutenção legal estendida | Anonimizar parte contrária via `Anonymizer::processoParte()` apenas mediante solicitação do titular **e** confirmação de inexistência de obrigação legal de retenção. |
| `tasks` | Idem cards | Anonimização raramente aplicável (descrição interna). |
| `chat_mensagens` | Manutenção por 5 anos (Marco Civil Art. 15) | Purge físico mediante solicitação. |
| `whatsapp_messages` (sem `raw_payload`) | Manutenção por 5 anos | Idem. |
| `lgpd_requests` | **Indefinido** | Auditoria do exercício de direitos (LGPD Art. 37). |
| `lgpd_request_events` | **Indefinido** | Idem — imutável. |
| `security_incidents` + events | **Indefinido** | Art. 48 + Art. 37 — imutável. |
| `data_processors` + history | **Indefinido enquanto ativo + 5 anos após desativação** | Histórico contratual. |
| `master_audit_log` | **Indefinido** | Imutável. Art. 37. |
| `account_audit_log` | **Indefinido** | Imutável. Art. 37. |
| `term_acceptances` | **Indefinido** | Prova de consentimento (Art. 8 §6). Imutável. |
| `lgpd_consents` | Enquanto vigente + 5 anos após revogação | Comprovação de validade do tratamento no período. |
| `anonymization_log` | **Indefinido** | Comprovação do cumprimento do Art. 16. Imutável. |

## 6. Atendimento ao direito à eliminação (Art. 18 VI)

Quando um titular solicita eliminação via `/lgpd/solicitar.php` (tipo `eliminacao` ou `anonimizacao`):

1. Pedido entra em `lgpd_requests` com prazo de 15 dias (Art. 19).
2. DPO avalia se há obrigação legal de retenção (Art. 16 II) — neste caso, aplicar **anonimização** em vez de eliminação total.
3. Execução via Painel Master → ação "Anonimizar" no modal da solicitação → `Anonymizer` decide entre user/contato/card/processoParte.
4. Operação registrada em `anonymization_log` com `lgpd_request_id` correlacionado.
5. Backup: dados anonimizados são propagados aos backups na rotação natural (até 12 meses). Esta limitação é comunicada ao titular na resposta.

## 7. Exclusão por término de contrato (offboarding de tenant)

- Tenant suspenso por > 90 dias e sem renovação: notificação ao titular do tenant alertando que dados serão eliminados em 30 dias.
- Após o aviso: opção do tenant baixar export ZIP de portabilidade (gerado via `Anonymizer::exportTitular()`).
- Após 30 dias do aviso sem resposta: anonimização em cascata de todos os dados PII do tenant.

## 8. Conformidade

- Atende LGPD Art. 6 III (necessidade), Art. 15 (término do tratamento), Art. 16 (eliminação), Art. 18 (direitos), Art. 37 (registro/auditoria).
- Alinhado a controles ISO 27001 (A.8.3 — Mídia, descarte seguro).

## 9. Revisão

Anual ou ao adicionar nova entidade com PII no schema. Próxima revisão: **2027-05-23**.
