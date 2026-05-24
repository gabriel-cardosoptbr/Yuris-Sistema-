# Política de Segurança da Informação (PSI) — Yuris

**Versão:** 1.0 — 2026-05-23
**Aprovador:** Diretoria
**Mantenedor:** Encarregado de Proteção de Dados (DPO)
**Aplicação:** todos os colaboradores, prestadores de serviço e operadores que tenham acesso à infraestrutura ou dados da Yuris.

---

## 1. Propósito

Definir os **princípios, controles e responsabilidades** para proteger a confidencialidade, integridade e disponibilidade dos dados pessoais e demais informações tratadas pela plataforma Yuris, em conformidade com a **Lei nº 13.709/2018 (LGPD)** — em especial seus Arts. 6 (princípios), 37 (registro), 46 (segurança) e 50 (boas práticas e governança).

## 2. Escopo

Esta PSI se aplica a:
- Todo dado pessoal, sensível ou não, tratado pela Yuris ou em seu nome;
- Toda infraestrutura sob controle da Yuris (servidores, redes, estações, repositórios de código);
- Todos os colaboradores diretos, terceirizados, estagiários e prestadores que tenham acesso a essa infraestrutura ou dados;
- Operadores contratados (regidos também por DPA — ver `POLITICA_TERCEIROS.md` e `DPA_TEMPLATE.md`).

## 3. Princípios

A Yuris adota os seguintes princípios alinhados à LGPD Art. 6 e às boas práticas de segurança (ISO 27001, NIST):

| Princípio | Como aplicamos |
|-----------|----------------|
| **Finalidade** | Coletar apenas para finalidades específicas, legítimas e informadas. |
| **Adequação** | O tratamento é compatível com as finalidades comunicadas. |
| **Necessidade** | Coletar o mínimo necessário (data minimization). |
| **Livre acesso** | Titulares podem consultar e exportar seus dados a qualquer momento. |
| **Qualidade dos dados** | Dados mantidos exatos, claros e atualizados (mecanismos de correção). |
| **Transparência** | Informações claras sobre quem trata, como e por quê. |
| **Segurança** | Medidas técnicas e administrativas para proteger dados (este documento). |
| **Prevenção** | Medidas adotadas antes do incidente, não só depois. |
| **Não-discriminação** | Tratamento não pode ser usado para fins discriminatórios. |
| **Responsabilização** | Demonstração documentada de conformidade (audit log, RAT, RIPD). |

## 4. Responsabilidades

| Papel | Responsabilidades-chave |
|-------|--------------------------|
| **Diretoria** | Aprovar PSI; alocar recursos; aprovar exceções; receber relatórios trimestrais de DPO. |
| **DPO (Encarregado)** | Manter PSI atualizada; treinar equipe; conduzir avaliações; ponto de contato ANPD/titulares; aprovar contratos com operadores. |
| **Equipe técnica** | Aplicar controles técnicos (autenticação, cifragem, logs); responder a incidentes; manter inventário de ativos. |
| **Gestores** | Garantir aderência da equipe à PSI; autorizar/revogar acessos; comunicar mudanças de papel ao TI. |
| **Colaboradores** | Cumprir PSI; assinar NDA (`NDA_FUNCIONARIO.md`); concluir treinamentos anuais; reportar incidentes. |
| **Operadores (terceiros)** | Cumprir DPA assinado; reportar incidentes em 24h; submeter-se a auditorias. |

## 5. Controles técnicos

### 5.1 Autenticação e acesso
- Senhas armazenadas via **bcrypt** (mínimo cost 10).
- **MFA obrigatório** para super_admin (TOTP via `TotpHelper`).
- MFA recomendado e disponível para todos os papéis.
- Sessões com expiração configurável e renovação por atividade.
- Bloqueio progressivo após tentativas falhas (`login_attempts`).
- Ver detalhes em `POLITICA_SENHAS_E_ACESSO.md`.

### 5.2 Criptografia
- TLS 1.2+ obrigatório em todas as comunicações externas (Apache config).
- Dados sensíveis em repouso cifrados com **AES-256-CBC** (chave em `MFA_ENCRYPTION_KEY`):
  - 2FA secrets, chaves de API de operadores (Evolution, LLM).
- Hashes não reversíveis para senhas (bcrypt).
- Tokens com `random_bytes(32)` (CSPRNG).

### 5.3 Isolamento multi-tenant
- Toda query filtrada por `account_id` via `AccountContext`.
- `getAccessibleAccountIds($module)` resolve hierarquia matriz↔filiais.
- Auditoria automatizada (`SCHEMA_AUDIT.md`) verifica vazamento entre tenants.

### 5.4 Validação de entrada
- **PDO prepared statements** em todo SQL (sem concatenação).
- Allowlists de MIME types em uploads (`task_attachments`, `media_upload`).
- Validação de magic bytes via `finfo_file`.
- CSRF token em todos os POST/PATCH/DELETE.
- Headers de segurança: `X-Content-Type-Options`, `X-Frame-Options`, `Content-Security-Policy`.

### 5.5 Logs e auditoria
- **`master_audit_log`** — toda ação do Painel Master.
- **`account_audit_log`** — ações administrativas por tenant.
- **`processo_history` / `card_history` / `task_history`** — mudanças em entidades.
- **`security_incident_events`** — timeline de incidentes (imutável).
- **`data_processor_history`** — alterações em operadores (imutável).
- **`anonymization_log`** — toda anonimização (imutável).
- **`lgpd_request_events`** — atendimento a titulares (imutável).
- Triggers SQL `BEFORE UPDATE/DELETE` com `SIGNAL SQLSTATE '45000'` bloqueiam modificação/exclusão de tabelas de auditoria a nível de banco.
- Cada registro inclui `request_id` (hex 12) + IP + user-agent para correlação forense.

### 5.6 Backup e recuperação
- Ver `POLITICA_BACKUP_RECUPERACAO.md` para detalhes.

### 5.7 Atualização e gestão de vulnerabilidades
- Dependências PHP via Composer com `composer audit` em CI (quando aplicável).
- MariaDB/Apache atualizados com prazo máximo de 30 dias após divulgação de CVE crítico.
- Revisão trimestral de configurações de Apache (`mod_security`, headers).

## 6. Controles administrativos

### 6.1 Treinamento
- Treinamento obrigatório em proteção de dados para todo novo colaborador (em até 30 dias).
- Reciclagem anual para todos.
- Ver `POLITICA_TREINAMENTO_PRIVACIDADE.md`.

### 6.2 Confidencialidade
- Todo colaborador assina NDA antes de ter acesso a dados pessoais (`NDA_FUNCIONARIO.md`).
- Obrigação de sigilo permanece após o término do vínculo (mínimo 5 anos).

### 6.3 Onboarding e offboarding
- Provisão de acessos seguindo princípio do menor privilégio.
- Revogação imediata no offboarding (mesma jornada).
- Ver `PROCEDIMENTO_ONBOARDING_OFFBOARDING.md`.

### 6.4 Avaliação de impacto (RIPD/DPIA)
- Obrigatória antes de lançar tratamento de alto risco (novos features que processem dados sensíveis em escala, decisões automatizadas, monitoramento sistemático).
- Ver `MODELO_RIPD.md`.

### 6.5 Inventário de operadores
- Mantido em `data_processors` (tabela) + `INVENTARIO_OPERADORES.md` (snapshot anual).
- Avaliação prévia conforme `POLITICA_TERCEIROS.md`.
- DPA assinado conforme `DPA_TEMPLATE.md`.

## 7. Gestão de incidentes

- Detecção, contenção, mitigação, comunicação à ANPD/titulares e encerramento conforme `PROCEDIMENTO_INCIDENTES.md`.
- Templates de comunicação em `MODELO_NOTIFICACAO_ANPD.md` e `MODELO_NOTIFICACAO_TITULAR.md`.
- Registro em `security_incidents` + timeline imutável em `security_incident_events`.

## 8. Direitos dos titulares

- Canal público: `/lgpd/solicitar.php` (10 tipos do Art. 18).
- Prazo de resposta: 15 dias corridos (Art. 19).
- Fluxo de atendimento gerenciado em `Painel Master → LGPD`.
- Anonimização e portabilidade implementadas via `Anonymizer`.

## 9. Retenção e eliminação

- Políticas por entidade em `retention_policies` (tabela) + `POLITICA_RETENCAO.md` (documento).
- Cron diário `lgpd_retention_tick.php` aplica purges/anonimizações.
- Logs de eliminação em `anonymization_log` (imutável).

## 10. Classificação de dados

Toda informação tratada pela Yuris é classificada em 4 níveis: **pública | interna | restrita | sensível**.
Cada nível tem regras específicas de armazenamento, transmissão e descarte.
Ver `POLITICA_CLASSIFICACAO_DADOS.md`.

## 11. Trabalho remoto e BYOD

- Acesso à infraestrutura administrativa somente via VPN ou IP allowlisted (quando aplicável).
- Dispositivos pessoais autorizados a acessar e-mail/Slack, mas vetados para banco de dados produtivo.
- Tela de bloqueio obrigatória em todos os dispositivos.

## 12. Conformidade

- Auditorias internas anuais conduzidas pelo DPO.
- Pentest externo recomendado anualmente (ou após mudanças arquiteturais relevantes).
- Não-conformidades são registradas e tratadas com plano de ação documentado.

## 13. Exceções

Qualquer exceção a esta política exige aprovação por escrito da Diretoria, registrada em ata com prazo de revisão.

## 14. Sanções

O descumprimento desta PSI por colaborador pode ensejar:
- Advertência verbal/formal;
- Suspensão;
- Demissão por justa causa (em caso de violação grave);
- Responsabilização civil/penal (vazamento doloso, fraude, etc.).

## 15. Vigência e revisão

Esta PSI entra em vigor na data de aprovação e é **revisada anualmente** pelo DPO ou imediatamente após:
- Mudanças regulatórias relevantes;
- Incidentes de severidade alta/crítica;
- Mudanças arquiteturais significativas.

---

**Documentos relacionados:**

| Documento | Sobre |
|-----------|-------|
| `POLITICA_SENHAS_E_ACESSO.md` | Autenticação e RBAC |
| `POLITICA_BACKUP_RECUPERACAO.md` | Backup, RPO, RTO, teste de restore |
| `POLITICA_CLASSIFICACAO_DADOS.md` | Como classificar e tratar dados por nível |
| `POLITICA_RETENCAO.md` | Prazo + ação para cada entidade |
| `POLITICA_TERCEIROS.md` | Avaliar e contratar operadores |
| `POLITICA_TREINAMENTO_PRIVACIDADE.md` | Conteúdo e cadência de treinamento |
| `PROCEDIMENTO_ONBOARDING_OFFBOARDING.md` | Acessos de colaboradores |
| `PROCEDIMENTO_INCIDENTES.md` | Resposta a incidentes (Art. 48) |
| `DPA_TEMPLATE.md` | Modelo de contrato com operador |
| `MODELO_RIPD.md` | Relatório de Impacto à Proteção de Dados |
| `MODELO_NOTIFICACAO_ANPD.md` | Comunicação ANPD em incidentes |
| `MODELO_NOTIFICACAO_TITULAR.md` | Comunicação a titulares afetados |
| `NDA_FUNCIONARIO.md` | Termo de confidencialidade interno |
| `CHECKLIST_DEPLOY_PRODUCAO.md` | Pre-flight LGPD/security antes do go-live |
| `LGPD_RAT_INICIAL.md` | Registro de Atividades de Tratamento (Art. 37) |
| `INVENTARIO_OPERADORES.md` | Snapshot dos terceiros contratados |

---

**Próxima revisão prevista:** 2027-05-23.
