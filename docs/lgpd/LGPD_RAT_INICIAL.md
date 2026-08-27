# Registro de Atividades de Tratamento (RAT) — Yuris

> **Versão:** 1.2 — 2026-05-23 (atualizada após Etapas 8, 9, 11 e 12 do roadmap)
> **Aplicabilidade:** LGPD Art. 37 (registro de operações de tratamento)
> **Responsável:** Inovaize (controladora própria) + clientes PJ (controladores dos seus dados)
> **Pendente:** revisão jurídica final dos textos e bases legais

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

> **Fonte vivente:** tabela `data_processors` (Etapa 9 do roadmap). Snapshot inicial em `INVENTARIO_OPERADORES.md`. Gerenciado em **Painel Master → Operadores**.

Inventário inicial (seed da migration 055 + complementos previstos):

| Operador | Função | Localização | Status DPA | Transferência internacional |
|---|---|---|---|---|
| Evolution API | Mensageria WhatsApp | Self-host (preencher) | Pendente | Sim (via Meta) |
| Meta / WhatsApp | Suboperador (transporte) | EUA | Padrão WhatsApp Business | Sim |
| MariaDB | Storage primário | Auto-hospedado | Dispensado (open-source) | Não |
| Apache HTTP Server | Servidor web | Auto-hospedado | Dispensado (open-source) | Não |
| Gateway de Pagamento | Cobrança | Variável (Stripe=Irlanda; MP=BR; Asaas=BR) | Pendente — a contratar antes do go-live | Possível |
| Provedor SMTP | E-mail transacional | Variável (SendGrid/SES/Mailgun) | Pendente — a contratar antes do go-live | Provável (US) |
| LLM / IA (OpenAI/Anthropic) | Agente IA WhatsApp (opt-in por tenant) | EUA | Pendente | **Sim — cláusulas contratuais padrão (Art. 33 II)** |
| Google Fonts | CDN fontes | EUA | Termos Google | Sim |
| jsDelivr / Cloudflare | CDN bibliotecas JS | EUA/global | Termos públicos | Sim |
| Provedor de hospedagem | Infraestrutura | A definir em produção | Contratual | Depende do provider |

**Workflow contratual completo** (Etapa 9): cada operador novo passa por avaliação (`POLITICA_TERCEIROS.md` §3), assinatura de DPA usando `DPA_TEMPLATE.md`, registro em `data_processors` com base legal da transferência internacional (Art. 33), e monitoramento contínuo (badge no Painel Master alerta DPAs vencendo em 30 dias).

Toda mudança no inventário fica em `data_processor_history` (imutável — trigger SQL bloqueia UPDATE/DELETE).

---

## 5. Medidas técnicas de segurança aplicadas

- Hash de senha em bcrypt (Fase 0)
- 2FA TOTP obrigatório para super_admin; opt-in para demais (Etapa 1)
- HTTPS obrigatório; cookies HttpOnly/Secure/SameSite
- CSRF token em endpoints state-changing
- Rate-limit de login (5 falhas/15min); rate-limit em lookup (30/min)
- Isolamento multi-tenant (`AccountContext` + `TenantGuard`)
- Allowlist de MIME em uploads + validação por `finfo_file`
- Cifragem at-rest de tokens MFA e api_keys de operadores via `TotpHelper::encryptSecret` (AES-256-CBC com `MFA_ENCRYPTION_KEY`)
- Webhooks per-tenant (eventos não vazam entre escritórios)
- 9 tabelas de audit imutáveis no nível do banco — 20 triggers `BEFORE UPDATE/DELETE` com `SIGNAL SQLSTATE '45000'` (Etapa 4D, ampliado nas 8 e 9)
- `ErrorReporter` centralizado — não vaza `getMessage()` em prod, gera `correlation_id` (Etapa 2D)
- `EnvLoader::validateProduction()` impede bootstrap sem segredos críticos (Etapa 2D)
- Correlação forense: `RequestId` (12 hex) propagado em todos os logs (Etapa 4A/4B)
- Documentos com retenção automatizada (Etapa 7) + workflow de incidentes (Etapa 8) + inventário de operadores (Etapa 9)

**Programa de governança em privacidade** (Etapa 11 — Art. 50):
- PSI mestre, política de senhas, backup, classificação de dados, retenção, treinamento, terceiros
- Procedimentos de incidentes, onboarding/offboarding
- Templates: DPA, RIPD (DPIA), NDA funcionário, comunicações ANPD/titulares
- Checklist de deploy em produção

---

## 6. Histórico de versões

| Versão | Data | Alteração | Responsável |
|---|---|---|---|
| 1.0 | 2026-05-23 | Versão inicial (Etapa 5 LGPD do roadmap) | Equipe técnica + (pendente) revisão jurídica |
| 1.1 | 2026-05-23 | Adição do bloco de Retenção (Etapa 7) | Equipe técnica |
| 1.2 | 2026-05-23 | Atualizado após Etapas 8 (incidentes), 9 (operadores), 11 (políticas) e 12 (consolidação) | Equipe técnica |

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

## 8. Resposta a incidentes (Art. 48) — implementado na Etapa 8

- **Schema:** `security_incidents` (workflow) + `security_incident_events` (timeline imutável).
- **Workflow:** detectado → em_analise → contido → mitigado → notificado_anpd → notificado_titulares → encerrado. Status `falso_positivo` encerra sem notificar.
- **Notificação ANPD:** marcada no incidente com protocolo. Template em `MODELO_NOTIFICACAO_ANPD.md`.
- **Notificação titulares:** marcada com canal (email/telefone/aviso público/in_app). Template em `MODELO_NOTIFICACAO_TITULAR.md`.
- **Procedimento operacional:** `PROCEDIMENTO_INCIDENTES.md` (6 passos + matriz severidade × prazo).
- **Painel Master → Incidentes:** badge dinâmico alerta abertos / críticos / pendência de notificação.

## 9. Pendências para revisão jurídica especialista

- [ ] Revisar todas as bases legais por atividade (Art. 7º e Art. 11)
- [ ] Validar prazos de retenção em cada categoria — em especial os 7/30/90 dias seed
- [ ] Finalizar contratos de Operador (DPA) usando `DPA_TEMPLATE.md` para gateway de pagamento e SMTP antes do go-live
- [ ] Designar formalmente o Encarregado (DPO) e preencher `.env` (`LGPD_DPO_EMAIL`)
- [ ] Aprovar textos públicos: Política de Privacidade, Termos, Cookies (atualmente modelos com disclaimer)
- [ ] Aprovar este RAT
- [x] Plano de resposta a incidentes (Art. 48) — **concluído (Etapa 8)**
- [x] Inventário detalhado de operadores e suboperadores — **concluído (Etapa 9 — vivente em `data_processors`)**
- [x] Programa de governança em privacidade Art. 50 — **concluído (Etapa 11)**
- [ ] Treinar equipe no fluxo: chega solicitação → Painel Master → DPO analisa → Anonymizer/Export (conforme `POLITICA_TREINAMENTO_PRIVACIDADE.md`)

---

## 10. Documentos relacionados

**Visão consolidada do programa:** `RELATORIO_FINAL_LGPD.md` (Etapa 12)

### Migrations principais
- `database/migrations/049_lgpd_legal_consent.sql` — legal_documents, term_acceptances, lgpd_consents (Etapa 5A)
- `database/migrations/050_lgpd_requests.sql` — lgpd_requests + events (Etapa 6A)
- `database/migrations/051_lgpd_retention_anonymization.sql` — retention_policies, anonymization_log (Etapa 7A)
- `database/migrations/052_audit_coverage.sql` — colunas user_agent + request_id em 5 tabelas (Etapa 4A)
- `database/migrations/053_audit_immutability_triggers.sql` — 16 triggers (Etapa 4D)
- `database/migrations/054_security_incidents.sql` — security_incidents + events + 2 triggers (Etapa 8A)
- `database/migrations/055_data_processors.sql` — data_processors + history + 2 triggers + seeds (Etapa 9A)

### Endpoints públicos
- `public/privacidade.php`, `termos.php`, `cookies.php`, `lgpd.php`, `dpo.php` — páginas legais (Etapa 5D)
- `public/lgpd/solicitar.php`, `acompanhar.php` — formulário Art. 18 + acompanhamento (Etapa 6C)
- `public/configuracoes/privacidade.php` — gestão de consentimentos pelo próprio usuário (Etapa 5E)

### Models e helpers
- `app/Lgpd/LegalDocument.php`, `TermAcceptance.php`, `Consent.php` — gestão de termos (Etapa 5A)
- `app/Lgpd/LgpdRequest.php` — solicitações Art. 18 (Etapa 6A)
- `app/Master/SecurityIncident.php` — incidentes (Etapa 8B)
- `app/Lgpd/DataProcessor.php` — operadores (Etapa 9B)
- `app/Lgpd/Anonymizer.php` — substituição de PII Art. 12 (Etapa 7A)
- `app/Master/MasterAudit.php` (expandido com IP/UA/request_id — Etapa 4B)
- `app/Core/RequestId.php` — correlação forense (Etapa 4A)
- `app/Core/ErrorReporter.php` — mensagens seguras em prod (Etapa 2D.1)
- `app/Core/EnvLoader.php` (`validateProduction` — Etapa 2D.2)

### APIs
- `public/api/lgpd_retention_tick.php` — cron de purge (Etapa 7B)
- `public/api/master/lgpd_requests.php`, `lgpd_anonymize.php`, `retention.php` — Painel Master LGPD (Etapas 6D, 7C)
- `public/api/master/incidents.php` — workflow de incidentes (Etapa 8C)
- `public/api/master/processors.php` — workflow de operadores (Etapa 9C)

### Documentos do programa de governança (Etapa 11)
- `POLITICA_SEGURANCA_INFORMACAO.md` — PSI mestre
- `POLITICA_SENHAS_E_ACESSO.md`, `POLITICA_BACKUP_RECUPERACAO.md`
- `POLITICA_CLASSIFICACAO_DADOS.md`, `POLITICA_RETENCAO.md`
- `POLITICA_TERCEIROS.md`, `POLITICA_TREINAMENTO_PRIVACIDADE.md`
- `PROCEDIMENTO_ONBOARDING_OFFBOARDING.md`, `PROCEDIMENTO_INCIDENTES.md`
- `DPA_TEMPLATE.md`, `NDA_FUNCIONARIO.md`, `MODELO_RIPD.md`
- `MODELO_NOTIFICACAO_ANPD.md`, `MODELO_NOTIFICACAO_TITULAR.md`
- `INVENTARIO_OPERADORES.md`, `CHECKLIST_DEPLOY_PRODUCAO.md`

### Relatório consolidado
- `RELATORIO_FINAL_LGPD.md` — sumário executivo, cobertura por artigo, métricas, gaps remanescentes (Etapa 12)
