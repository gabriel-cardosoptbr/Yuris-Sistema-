# Procedimento de Onboarding e Offboarding — Yuris

**Versão:** 1.0 — 2026-05-23
**Mantenedor:** Equipe de RH/Operações + Equipe Técnica + DPO
**Aplicação:** todos os colaboradores diretos, terceirizados, estagiários e prestadores que recebam acesso à infraestrutura/sistemas da Yuris.

---

## 1. Propósito

Garantir provisão segura de acessos no início do vínculo e revogação completa e tempestiva na saída, em conformidade com o princípio do **menor privilégio** e os controles de **segurança** (LGPD Art. 46) e **retenção** (Art. 16).

## 2. Onboarding

### 2.1 Antes do primeiro dia (D-7 a D-1)

Responsável: **RH** + **gestor direto**.

- [ ] **Determinar o papel** (matrix-aligned): qual a função, quais sistemas serão acessados, qual o nível mínimo de acesso necessário.
- [ ] **Aprovar acessos** com checklist específico do papel (ver §4).
- [ ] **Solicitar à equipe técnica** a criação dos acessos (chamado interno ou formulário).
- [ ] **Preparar contratos:** contrato de trabalho ou prestação + **NDA** (`NDA_FUNCIONARIO.md`) + autorização de uso de equipamentos.

### 2.2 No primeiro dia (D0)

Responsável: **gestor direto** + **equipe técnica**.

- [ ] **Assinatura presencial** ou eletrônica:
  - Contrato + NDA;
  - Política de Segurança da Informação (`POLITICA_SEGURANCA_INFORMACAO.md`);
  - Política de Senhas (`POLITICA_SENHAS_E_ACESSO.md`).
- [ ] **Entrega de credenciais iniciais** (senha temporária, MFA via app autenticador):
  - E-mail corporativo;
  - Acesso à Yuris com papel adequado;
  - Acesso ao repositório git (se equipe técnica) — usando chave SSH pessoal, nunca senha compartilhada;
  - Acesso ao chat interno (Slack/Discord/etc).
- [ ] **Apresentação institucional** com DPO ou treinamento básico de privacidade (mesmo que o treinamento completo seja agendado para depois).
- [ ] **Registro em `users`** (Yuris) com `ativo=1` e `role` específico.

### 2.3 Primeira semana (D1 a D7)

- [ ] **Mentor designado** acompanha primeiras configurações.
- [ ] **Configuração do MFA obrigatório** se papel exige (super_admin, owner — recomendado).
- [ ] **Verificação** de que o colaborador conseguiu acessar e bloqueou tela após uso (treinamento prático).

### 2.4 Primeiros 30 dias

- [ ] **Treinamento completo em proteção de dados** conforme `POLITICA_TREINAMENTO_PRIVACIDADE.md`.
- [ ] **Avaliação após treinamento** com quiz mínimo (registro guardado pelo RH/DPO).
- [ ] **Revisão dos acessos provisórios** — confirmar que tudo está adequado ou ajustar.

## 3. Offboarding

### 3.1 Saída programada (aviso prévio)

Responsável: **RH** notifica **equipe técnica + DPO** com **mínimo 5 dias úteis** de antecedência.

#### Dia da saída (D0)

- [ ] **Desativar `users.ativo=0`** na Yuris (CRUD via Painel Master).
- [ ] **Encerrar sessões ativas**: usuário perde acesso imediatamente (`AccountContext::assertAccountActive()`).
- [ ] **Bloquear e-mail corporativo** (não excluir — manter por 6 meses para responder mensagens pendentes via gestor).
- [ ] **Revogar acessos** a:
  - Repositório git (remover chave SSH);
  - Chat interno (suspender conta);
  - Banco de dados (se DBA — revogar `GRANT`);
  - Cofre de senhas compartilhadas (rotacionar credenciais que ele conhecia);
  - VPN.
- [ ] **Coletar equipamentos** (notebook, badge, token físico).
- [ ] **Wipe seguro** do equipamento antes de redistribuir.

#### Em até 48h após saída

- [ ] **Rotação de credenciais compartilhadas** que o colaborador conhecia:
  - Senha do `root` do banco produtivo (se ele conhecia);
  - Chaves de API de operadores (se aplicável);
  - Tokens de webhook;
  - `CRON_TOKEN`;
  - `MFA_ENCRYPTION_KEY` — apenas em hipótese extrema (force re-encryption).
- [ ] **Documentar offboarding** em log interno do RH.
- [ ] **Confirmar com DPO** que NDA continua vigente.

### 3.2 Saída abrupta (desligamento sem aviso)

Mesma sequência da §3.1, mas **executada no momento da decisão**:
- Bloqueio imediato no Yuris e demais sistemas (em paralelo à comunicação ao colaborador).
- Rotação de credenciais compartilhadas em **até 4h**.
- Auditoria nos últimos 30 dias de atividade do colaborador via `master_audit_log` (procurar acessos atípicos, exports massivos, etc.).
- Se houver suspeita de exfiltração: abrir incidente em `security_incidents` (severidade alta).

### 3.3 Saída de fornecedor/terceirizado

Aplicar mesmas regras, ajustando:
- Cancelamento do contrato + comprovante de devolução/exclusão de dados (Cl. 12 do DPA);
- Atualização no inventário de operadores (`Painel Master → Operadores → desativar`).

## 4. Checklist de acesso por papel

### 4.1 super_admin (gestão da plataforma)
- Acesso completo ao Painel Master;
- `users.role = 'admin'` + entrada em `super_admins` com `nivel='owner'`;
- MFA obrigatório;
- Justificativa documentada e aprovada pela diretoria.

### 4.2 admin (suporte / DPO)
- `users.role = 'admin'` (no tenant interno da Yuris, se aplicável);
- Sem entrada em `super_admins`;
- MFA recomendado.

### 4.3 Equipe técnica (devs / sysadmin)
- Acesso a repositório git (membro da org);
- Acesso a servidor de staging (SSH com chave);
- Acesso a banco de staging — **NUNCA** produtivo direto;
- Acesso ao Painel Master **somente** se também for super_admin (aprovação explícita).

### 4.4 Time comercial / financeiro
- Sem acesso direto ao banco;
- Acesso à Yuris no tenant interno + ao Painel Master com papel limitado (Faturas/Pagamentos/Despesas).

### 4.5 Estagiário
- Acesso restrito ao mínimo necessário para a função;
- **Sem acesso** a dados sensíveis ou produtivos sem supervisão;
- MFA obrigatório se acessar Painel Master.

## 5. Mudança de papel (lateral move)

Quando colaborador muda de função interna:
- Aplicar **revogação** dos acessos anteriores (offboarding-like) e **provisão** dos novos (onboarding-like).
- Não acumular acessos (anti-pattern "criadinha vai virando admin").
- Documentar em log.

## 6. Revisão periódica de acessos

Realizada **trimestralmente** pelo DPO + cada gestor:
- Listar todos os `users.ativo=1` por tenant interno e externos.
- Cada gestor confirma se cada usuário ainda precisa do acesso atual.
- Casos pendentes/incertos: revogar até confirmação.
- Log da revisão arquivado por 5 anos.

## 7. Conformidade

- Atende LGPD Art. 46 (segurança) e Art. 47 (boas práticas).
- Alinhado a ISO 27001 (A.9.2 — gestão de acessos de usuários).

## 8. Revisão

Anual. Próxima revisão prevista: **2027-05-23**.
