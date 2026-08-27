# Política de Treinamento em Privacidade e Proteção de Dados — Yuris

**Versão:** 1.0 — 2026-05-23
**Mantenedor:** DPO + RH
**Aplicação:** todos os colaboradores diretos, terceirizados, estagiários e prestadores que tenham acesso a dados pessoais.

---

## 1. Propósito

Garantir que toda a equipe da Yuris tenha **conhecimento, competência e responsabilidade** necessários para tratar dados pessoais em conformidade com a LGPD e com as políticas internas, atendendo ao **programa de governança em privacidade** previsto no **Art. 50** da LGPD.

## 2. Princípios

- **Continuidade:** não é evento isolado — é processo contínuo.
- **Relevância:** conteúdo adaptado ao papel do colaborador.
- **Comprovação:** todo treinamento é registrado com data, conteúdo, participante e validação.
- **Atualização:** material atualizado sempre que houver mudança regulatória ou processo interno relevante.

## 3. Modalidades

### 3.1 Treinamento básico (obrigatório para todos)

**Quando:** primeiros 30 dias do colaborador.
**Duração:** 2-3 horas.
**Formato:** vídeo + slides + leitura dirigida + quiz de validação (passing score ≥ 70%).

**Conteúdo mínimo:**
1. **O que é dado pessoal e dado sensível** (LGPD Art. 5).
2. **Princípios da LGPD** (finalidade, necessidade, segurança, transparência).
3. **Bases legais para tratamento** (Art. 7 + Art. 11).
4. **Direitos do titular** (Art. 18) e canal interno para encaminhar pedidos.
5. **Política de Segurança da Informação** da Yuris (resumo).
6. **Política de Senhas e MFA** — apresentação prática.
7. **O que fazer ao identificar um incidente** — não esconder, comunicar imediatamente ao DPO.
8. **Phishing e engenharia social** — exemplos reais; cuidado com links e anexos.
9. **Confidencialidade** — alcance do NDA, antes e depois do vínculo.
10. **Trabalho remoto seguro** — VPN, bloqueio de tela, dispositivos pessoais.

### 3.2 Treinamento específico por papel

#### 3.2.1 Equipe técnica (devs, sysadmin)
**Adicional:** 4-6 horas.
- Codificação segura (OWASP Top 10);
- Prepared statements, escape, validação de input;
- Isolamento multi-tenant (`AccountContext`);
- Gestão de segredos (`.env`, gerenciador de senhas, nunca commit);
- Cripto na prática (bcrypt, AES-256-CBC, TLS);
- Logs de auditoria — o que registrar e o que não registrar (não logar senha/api_key).

#### 3.2.2 Suporte ao cliente
**Adicional:** 2 horas.
- Como atender pedidos LGPD (Art. 18) corretamente;
- Identificar dados sensíveis em conversas e tratá-los com discrição;
- Roteiro para chamadas/chats — não pedir senha/CPF sem necessidade.

#### 3.2.3 Comercial / Marketing
**Adicional:** 1-2 horas.
- Base legal para envio de comunicações (consentimento vs. legítimo interesse);
- Direito de oposição ao marketing direto (Art. 18 §1);
- Lista de exclusão (opt-out) — manutenção obrigatória.

#### 3.2.4 Financeiro
**Adicional:** 1 hora.
- Tratamento de dados financeiros como Restritos;
- Cuidados com PDFs de fatura (não envio para destinatários errados);
- Recolhimento de comprovantes — onde podem ficar e por quanto tempo.

#### 3.2.5 Gestores / Diretoria
**Adicional:** 1 hora + briefing executivo trimestral.
- Decisões com impacto em proteção de dados (lançamento de feature, contratação de operador) exigem consulta ao DPO;
- Cultura: gestor que sabotar/burlar políticas é responsabilizado.

### 3.3 Reciclagem anual (obrigatório para todos)

**Quando:** uma vez por ano, no aniversário do treinamento básico (± 30 dias).
**Duração:** 1-2 horas.
**Formato:** quiz + atualização sobre mudanças do ano (novas decisões ANPD, jurisprudência relevante, novos processos internos).
**Aproveitamento:** ≥ 70% no quiz; caso contrário, treinamento dirigido.

### 3.4 Treinamento ad-hoc

Realizado sempre que:
- ANPD publica norma ou guidance relevante;
- Houver incidente de severidade alta/crítica — lições aprendidas;
- For introduzido fluxo novo que processa categoria adicional de dados (após RIPD positivo).

## 4. Registro

- **Planilha mantida pelo RH/DPO** com: nome do colaborador, modalidade, data, aproveitamento, validade.
- Cada participante recebe **certificado interno** com hash do conteúdo treinado.
- Relatório consolidado **trimestral** apresentado à Diretoria.

## 5. Sanções por descumprimento

- Colaborador que não concluir treinamento dentro do prazo: acesso a sistemas críticos suspenso até regularização.
- Reincidência: ação disciplinar conforme `POLITICA_SEGURANCA_INFORMACAO.md` §14.

## 6. Material de apoio (referência interna)

Os documentos abaixo formam parte do material de leitura obrigatória para o treinamento básico:

- `POLITICA_SEGURANCA_INFORMACAO.md`
- `POLITICA_SENHAS_E_ACESSO.md`
- `POLITICA_CLASSIFICACAO_DADOS.md`
- `PROCEDIMENTO_INCIDENTES.md`
- `LGPD_RAT_INICIAL.md` (para entender o que se trata na empresa)

## 7. Conformidade

Atende LGPD Art. 50 — adoção de programa de governança em privacidade, incluindo treinamento contínuo.

## 8. Revisão

Anual. Próxima revisão: **2027-05-23**.
