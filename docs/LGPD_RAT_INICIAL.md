# Registro de Atividades de Tratamento (RAT) — Yuris

> **Versão:** 2026-05-23 — modelo inicial pendente revisão jurídica
> **Aplicabilidade:** LGPD Art. 37 (registro de operações de tratamento)
> **Responsável:** Inovaize (controladora própria) + clientes PJ (controladores dos seus dados)

---

## 1. Identificação do controlador

| Campo | Valor |
|---|---|
| Razão social | Inovaize (preencher CNPJ, endereço completo) |
| Encarregado (DPO) | Definir (ver `.env` → `DPO_NAME`/`DPO_EMAIL`) |
| Canal do titular | `/dpo.php` + e-mail do DPO |
| Data de início do tratamento | 2025-XX-XX (data da primeira conta criada) |

> **Importante:** Cada cliente PJ que contrata o Yuris é **controlador** dos dados que cadastra. A Inovaize atua como **operador** dessa parte e como **controlador** dos dados próprios da relação SaaS.

---

## 2. Atividades de tratamento — Inovaize como CONTROLADOR

### 2.1 Cadastro e gestão do cliente PJ

| Item | Detalhe |
|---|---|
| **Finalidade** | Identificar o cliente PJ, manter relação contratual, comunicar sobre o serviço |
| **Base legal (Art. 7º)** | V — execução de contrato |
| **Categoria de titulares** | Sócios, administradores, usuários do escritório |
| **Categoria de dados** | Nome, e-mail, telefone, CNPJ, endereço, OAB |
| **Origem** | Auto-cadastro ou criação pelo super_admin |
| **Compartilhamento** | Nenhum externo (uso interno) |
| **Retenção** | Vigência do contrato + 30 dias para recuperação + 5 anos para fiscal |
| **Medidas de segurança** | Senhas em bcrypt; HTTPS; CSRF; multi-tenant isolation; 2FA disponível |
| **Tabelas no banco** | `accounts`, `users`, `super_admins` |

### 2.2 Cobrança e gestão financeira do SaaS

| Item | Detalhe |
|---|---|
| **Finalidade** | Cobrar mensalidades, emitir invoices, controlar inadimplência |
| **Base legal** | V — execução de contrato; IX — legítimo interesse (proteção do crédito) |
| **Categoria de titulares** | Cliente PJ (sócios/responsáveis financeiros) |
| **Categoria de dados** | CNPJ, e-mail, dados de pagamento tokenizados (NUNCA PAN/CVV) |
| **Compartilhamento** | Gateway de pagamento (Stripe / Mercado Pago / etc, quando ativado) |
| **Transferência internacional** | Possível (Stripe = EUA) — informado na Política |
| **Retenção** | 5 anos após encerramento (fiscal) |
| **Tabelas** | `subscriptions`, `invoices`, `payment_methods`, `gateway_events_received` |

### 2.3 Logs de auditoria e segurança

| Item | Detalhe |
|---|---|
| **Finalidade** | Investigar incidentes, auditar acessos do super_admin, atender obrigações de Marco Civil |
| **Base legal** | IX — legítimo interesse (segurança); II — cumprimento de obrigação legal (Marco Civil Art. 15) |
| **Categoria de dados** | user_id, IP, user_agent, ação, snapshot de dados antes/depois |
| **Retenção** | 90 dias (login_attempts); 6 meses (master_audit_log com IP) — ajustável pela política de retenção |
| **Tabelas** | `master_audit_log`, `account_audit_log`, `login_attempts`, `webhook_logs` |

### 2.4 Suporte ao cliente PJ

| Item | Detalhe |
|---|---|
| **Finalidade** | Atender solicitações, resolver incidentes, treinar uso |
| **Base legal** | V — execução de contrato |
| **Categoria de dados** | E-mail, nome do solicitante, descrição da solicitação |
| **Retenção** | 5 anos para suporte fiscal/contratual |
| **Tabelas** | (não implementado ainda — futuras tabelas de tickets) |

---

## 3. Atividades de tratamento — Inovaize como OPERADOR (dados do cliente PJ)

> O escritório contratante (Cliente PJ) é o **controlador** dos dados abaixo. A Inovaize/Yuris é **operadora** — processa em nome do escritório, sob suas instruções e nos termos contratuais.

### 3.1 Gestão de clientes finais do escritório

| Item | Detalhe |
|---|---|
| **Finalidade** | Permitir ao escritório cadastrar e gerir clientes (CRM) |
| **Base legal** | V (contrato escritório↔cliente final) ou VI (defesa de direitos) — definida pelo escritório |
| **Categoria de titulares** | Clientes finais do escritório |
| **Categoria de dados** | Nome, telefone, e-mail, CPF/CNPJ, endereço, observações |
| **Retenção** | Definida pelo escritório (default configurável); mínimo dos prazos prescricionais |
| **Tabelas** | `contatos`, `cards` |

### 3.2 Processos jurídicos

| Item | Detalhe |
|---|---|
| **Finalidade** | Gerir andamento processual |
| **Base legal** | VI — exercício regular de direitos em processo |
| **Categoria de titulares** | Cliente, parte contrária, terceiros mencionados em peças |
| **Categoria de dados** | Nome, CPF/CNPJ, número CNJ, vara, observações, anexos (podem incluir laudos, sentenças com dados sensíveis) |
| **Retenção** | Conforme prazos prescricionais (mínimo 5 anos após trânsito em julgado) |
| **Tabelas** | `processos`, `processo_history`, `processo_prazos`, `processo_tarefas` |

### 3.3 Comunicação via WhatsApp

| Item | Detalhe |
|---|---|
| **Finalidade** | Comunicação entre escritório e cliente / parte / advogado adverso |
| **Base legal** | V — execução de contrato; VI — exercício de direitos |
| **Categoria de dados** | Telefone, nome (push), conteúdo das mensagens, mídia (imagens, áudios, documentos), payload Evolution |
| **Subprocessador** | Meta / WhatsApp (transporte) — possível transferência internacional |
| **Retenção** | 365 dias (configurável); raw_payload pode ser purgado em 30 dias |
| **Tabelas** | `whatsapp_messages`, `whatsapp_chats`, `whatsapp_contacts`, `whatsapp_instances` |

### 3.4 Tarefas, agendamentos, financeiro do escritório

| Item | Detalhe |
|---|---|
| **Finalidade** | Organização interna do escritório |
| **Base legal** | V — execução de contrato; IX — legítimo interesse |
| **Tabelas** | `tasks`, `task_boards`, `dre_*`, `taxes`, `goals`, `chat_*` (chat interno) |

---

## 4. Operadores e suboperadores

| Operador | Função | Localização | Status DPA | Transferência internacional |
|---|---|---|---|---|
| Evolution API | Mensageria WhatsApp | Self-host (preencher) | A providenciar | Sim (via Meta) |
| Meta / WhatsApp | Suboperador (transporte) | EUA | Padrão WhatsApp Business | Sim |
| Gateway pagamento | Cobrança | Variável (Stripe=Irlanda; MP=BR) | A providenciar | Possível |
| Google Fonts | CDN fontes | EUA | Termos Google | Sim |
| jsDelivr / Cloudflare | CDN bibliotecas JS | EUA/global | Termos públicos | Sim |
| Provedor de hospedagem | Infraestrutura | Preencher | Sim, contratual | Depende do provider |
| SMTP (futuro) | E-mail transacional | Depende do provider | A providenciar | Depende |

---

## 5. Medidas técnicas de segurança aplicadas

- Hash de senha em bcrypt (Fase 0)
- 2FA TOTP para super_admin (Etapa 1)
- HTTPS obrigatório; cookies HttpOnly/Secure/SameSite
- CSRF token em endpoints state-changing
- Rate-limit de login (5 falhas/15min); rate-limit em lookup (30/min)
- Isolamento multi-tenant (AccountContext + TenantGuard)
- Allowlist de MIME em uploads + validação por finfo
- Cifragem at-rest de tokens MFA e api_keys de IA (AES-256-CBC)
- Webhooks per-tenant (eventos não vazam entre escritórios)
- Logs imutáveis em master_audit_log
- ErrorReporter centralizado (não vaza schema em prod)

---

## 6. Histórico de versões

| Versão | Data | Alteração | Responsável |
|---|---|---|---|
| 2026-05-23 | 2026-05-23 | Versão inicial (Etapa 5 LGPD do roadmap) | Equipe técnica + (pendente) revisão jurídica |

---

## 7. Política de Retenção operacional (Etapa 7)

A tabela `retention_policies` controla o ciclo de vida dos dados conforme Art. 16 LGPD. Cron diário (`/api/lgpd_retention_tick.php`) aplica as regras.

| Entidade | Ação | Prazo | Base legal |
|---|---|---|---|
| `webhook_logs` | purge físico | 90 dias | Sem valor probatório após 90d (Art. 6 III — minimização) |
| `login_attempts` | purge físico | 90 dias | Tentativas falhas; retenção curta protege titular |
| `gateway_events_received` | purge físico | 90 dias | Idempotência já processada |
| `emails_outbox_body` | anonimiza body | 7 dias após sent | Pode conter tokens/links sensíveis |
| `whatsapp_messages_payload` | anonimiza `raw_payload` | 30 dias | Metadata + mediaKey não precisa persistir |

DPO pode rodar manualmente via Painel Master → aba Retenção (botões "Dry Run" e "Executar agora").

Anonimização de titulares (não cron, sob demanda do DPO):
- `Anonymizer::user($id)` — nome, login, telefone, OAB → placeholder; preserva FKs e logs.
- `Anonymizer::contato($id)` — idem para `contatos`.
- `Anonymizer::card($id)` — `cards`.
- `Anonymizer::processoParte($id)` — `parte_contraria` + CPF.
- `Anonymizer::exportTitular($email)` — gera ZIP estruturado para portabilidade.

Cada operação loga em `anonymization_log` com motivo + executor + `lgpd_request_id` (se origem foi solicitação Art. 18). Auditável, irreversível, com trilha clara.

---

## 8. Pendências para revisão jurídica especialista

- [ ] Revisar todas as bases legais por atividade (Art. 7º e Art. 11)
- [ ] Validar prazos de retenção em cada categoria — em especial os 7/30/90 dias seed
- [ ] Finalizar contratos de Operador com cada terceiro (DPA)
- [ ] Designar formalmente o Encarregado (DPO) e preencher `.env`
- [ ] Aprovar textos públicos: Política de Privacidade, Termos, Cookies
- [ ] Aprovar este RAT
- [ ] Plano de resposta a incidentes (Art. 48) — pendente Etapa 8 do roadmap
- [ ] Inventário detalhado de operadores e suboperadores — pendente Etapa 9
- [ ] Treinar equipe no fluxo: chega solicitação → Painel Master → DPO analisa → Anonymizer/Export

---

## 9. Documentos relacionados

- `database/migrations/049_lgpd_legal_consent.sql` — schema legal_documents, term_acceptances, lgpd_consents
- `database/migrations/050_lgpd_requests.sql` — schema lgpd_requests + events
- `database/migrations/051_lgpd_retention_anonymization.sql` — retention_policies, anonymization_log + colunas anonymized_at
- `public/privacidade.php`, `termos.php`, `cookies.php`, `lgpd.php`, `dpo.php` — páginas públicas
- `public/lgpd/solicitar.php`, `acompanhar.php` — formulário Art. 18 + acompanhamento
- `app/Models/LegalDocument.php`, `TermAcceptance.php`, `Consent.php`, `LgpdRequest.php` — gestão programática
- `app/Helpers/Anonymizer.php` — substituição de PII (Art. 12)
- `public/api/lgpd_retention_tick.php` — cron de purge automático
- `docs/AUDITORIA_LGPD_2026-05-23` — relatório de auditoria base
