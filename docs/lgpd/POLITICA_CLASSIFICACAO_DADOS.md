# Política de Classificação de Dados — Yuris

**Versão:** 1.0 — 2026-05-23
**Mantenedor:** DPO
**Aplicação:** todo dado tratado pela Yuris, em qualquer formato (banco, arquivos, anexos, logs).

---

## 1. Propósito

Estabelecer uma **taxonomia clara** para classificar as informações tratadas pela Yuris, de forma a aplicar controles proporcionais ao seu nível de sensibilidade. A classificação orienta decisões sobre armazenamento, transmissão, compartilhamento e descarte.

## 2. Os 4 níveis

| Nível | Definição | Exemplos na Yuris |
|-------|-----------|-------------------|
| **Pública** | Disponível livremente; sem impacto se exposta. | Páginas legais (`/privacidade`, `/termos`), logo, conteúdo de marketing. |
| **Interna** | Uso restrito à equipe; não confidencial mas não pública. | Documentos operacionais internos, métricas de uso agregadas, configurações não-secretas. |
| **Restrita** | Confidencial; exposição causa dano operacional ou contratual. | Dados de identificação de tenants e usuários (nome, e-mail, telefone), processos jurídicos, mensagens de chat, anexos. |
| **Sensível** | Crítica; exposição causa dano relevante a titulares ou à organização. | Senhas (hash), 2FA secrets, chaves de API, dados financeiros bancários, **dados sensíveis LGPD Art. 5 II** (saúde, biometria, etc.), credenciais de operadores. |

## 3. Regras por nível

### 3.1 Dados Públicos
- Armazenamento: qualquer local.
- Transmissão: qualquer canal.
- Compartilhamento: livre.
- Descarte: irrelevante.

### 3.2 Dados Internos
- Armazenamento: apenas em ambientes da Yuris (não pessoais).
- Transmissão: cifrada em trânsito quando sai da rede interna (TLS).
- Compartilhamento: somente entre colaboradores Yuris ou operadores com DPA.
- Descarte: limpeza padrão (não exige sobrescrita segura).

### 3.3 Dados Restritos
- **Armazenamento:** apenas em sistemas autorizados (banco produtivo, storage da Yuris). Proibido em laptops pessoais, drives USB, e-mail pessoal.
- **Transmissão:** **sempre cifrada** (TLS 1.2+).
- **Compartilhamento:** restrito ao need-to-know; logado em audit trail.
- **Acesso:** controle baseado em papéis (RBAC) com MFA recomendado.
- **Descarte:** anonimização ou eliminação registrada em `anonymization_log`.

### 3.4 Dados Sensíveis
- **Armazenamento:** **obrigatoriamente cifrado em repouso** (AES-256). Aplicado a:
  - Senhas → bcrypt (hash, não cifragem; ainda mais forte);
  - 2FA secrets, api_keys de operadores, agent_configs → AES-256-CBC via `TotpHelper::encryptSecret`;
  - **Dados sensíveis LGPD** → quando armazenados, cifrar com chave dedicada (atualmente n/a — Yuris não trata dados de saúde, biometria etc).
- **Transmissão:** TLS 1.2+ obrigatório; mTLS recomendado entre serviços internos.
- **Compartilhamento:** **proibido fora da plataforma** sem aprovação explícita do DPO + assinatura de DPA reforçado.
- **Acesso:** RBAC restritivo + MFA **obrigatório** + log de cada acesso.
- **Descarte:** sobrescrita segura ou destruição física; certificada.

## 4. Mapeamento entidades → classificação

Snapshot mantido com base no schema atual:

| Entidade (tabela) | Classificação | Observações |
|-------------------|---------------|-------------|
| `users` | Restrito | Inclui nome, e-mail, telefone, OAB. `users.senha` é Sensível (bcrypt). |
| `users.totp_secret_enc` | Sensível | AES-256 |
| `accounts` | Restrito | Razão social, CNPJ, endereço |
| `contatos` | Restrito | PII de leads/clientes finais |
| `processos` | Restrito | Inclui dados de partes (CPF, nome) |
| `cards` | Restrito | Lead/oportunidade — PII básica |
| `tasks` | Interno | A menos que descrição contenha PII de titular — sensibilizar equipe |
| `chat_mensagens`, `whatsapp_messages` | Restrito | Conteúdo de comunicações |
| `whatsapp_settings.evolution_api_key` | Sensível | (registrado mascarado na UI; cifrado em repouso) |
| `agent_configs.api_key_enc` | Sensível | AES-256 |
| `super_admins.totp_secret_enc` | Sensível | AES-256 |
| `lgpd_consents` | Restrito | Vínculo titular → consentimento |
| `lgpd_requests` | Restrito | Pedido + dados do titular |
| `security_incidents.descricao_interna` | Sensível | Pode conter detalhes técnicos exploráveis |
| `security_incidents.descricao_publica` | Restrito | Versão sanitizada |
| `data_processors` | Interno | Inventário de terceiros — público em RAT/auditoria |
| `master_audit_log` e demais audits | Restrito | Imutável a nível de banco |
| `webhook_logs` | Interno | Conteúdo pode revelar PII — tratar como Restrito em payloads |
| `login_attempts` | Interno | IPs + e-mails tentados |
| `master_expenses` | Restrito | Dados financeiros internos |
| `invoices`, `payments` | Restrito | Inclui dados financeiros de tenants |

## 5. Marcação visual

Quando aplicável, dados aparecem na UI com selo de classificação (futuro — não implementado nesta fase). Por ora, equipe segue mapeamento acima.

## 6. Coleta de novos dados

Antes de coletar nova categoria de dado pessoal:
1. Documentar finalidade e base legal (LGPD Art. 7/11);
2. Classificar conforme tabela acima (atualizar este documento);
3. Definir controles proporcionais ao nível;
4. Se Sensível: realizar **RIPD/DPIA** antes do lançamento (ver `MODELO_RIPD.md`).

## 7. Treinamento

Toda equipe deve conhecer esta classificação (parte do treinamento anual em privacidade — `POLITICA_TREINAMENTO_PRIVACIDADE.md`).

## 8. Revisão

Anual ou ao adicionar novas categorias de dados (nova entidade no schema). Próxima revisão prevista: **2027-05-23**.
