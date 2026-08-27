# Modelo de Comunicação à ANPD — Incidente de Segurança

**Base legal:** LGPD Art. 48 §1 — *"O controlador deverá comunicar à autoridade nacional e ao titular a ocorrência de incidente de segurança que possa acarretar risco ou dano relevante aos titulares."*

> Este é um **modelo de partida**.  O Encarregado (DPO) deve adaptar com os fatos do incidente e, em incidentes de alta/crítica severidade, **submeter à revisão jurídica antes de enviar à ANPD**.
> Envio oficial: portal da ANPD em `https://www.gov.br/anpd/pt-br/canais_atendimento` (formulário "Comunicado de Incidente de Segurança").

---

## Identificação

- **Razão social do controlador:** [RAZÃO SOCIAL DA EMPRESA QUE OPERA O YURIS]
- **CNPJ:** [CNPJ]
- **Encarregado (DPO):** [NOME COMPLETO]
- **E-mail do Encarregado:** [E-MAIL DPO]
- **Telefone do Encarregado:** [TELEFONE]
- **Identificador interno do incidente:** INC-[NNNNNN]
- **Data desta comunicação:** [DD/MM/AAAA]

## Descrição do incidente

- **Data e hora da ocorrência (estimada):** [DD/MM/AAAA HH:MM]
- **Data e hora da detecção:** [DD/MM/AAAA HH:MM]
- **Natureza do incidente:** [vazamento de dados | acesso não autorizado | ransomware | outro — descrever]
- **Resumo dos fatos:**
  [Texto livre, 1-3 parágrafos. Linguagem clara, sem jargão técnico desnecessário.]

## Dados pessoais afetados

- **Categorias:** [marcar todas que se aplicam]
  - [ ] Dados de identificação (nome, e-mail, telefone, endereço)
  - [ ] Documentos (CPF, RG, OAB, CNH)
  - [ ] Dados financeiros (dados bancários, transações)
  - [ ] Dados jurídicos (números de processos, peças, vinculações cliente-causa)
  - [ ] Credenciais de autenticação (senhas — hash — , MFA, tokens)
  - [ ] Comunicações privadas (chat, WhatsApp)
  - [ ] Dados sensíveis (Art. 5, II — saúde, orientação sexual, dado biométrico etc.)
- **Volume estimado de titulares afetados:** [NÚMERO ou "indeterminado, em apuração"]
- **Volume estimado de registros afetados:** [NÚMERO ou "indeterminado, em apuração"]
- **Titulares estão sendo individualmente identificados?** [sim | não, apenas categorias]
- **Há dados de crianças/adolescentes envolvidos?** [sim | não | não se aplica]

## Avaliação de risco aos titulares

- **Probabilidade de exploração efetiva:** [alta | média | baixa]
- **Impacto potencial:** [descrever — riscos de fraude, exposição reputacional, golpe direcionado, discriminação, dano patrimonial etc.]
- **Mitigação automática:** [explicar se os dados estavam cifrados, pseudonimizados ou se há outras barreiras que reduzem o risco]

## Medidas tomadas

- **Contenção (imediata):**
  [Listar — bloqueio de IPs, revogação de sessões, isolamento de hosts, rotação de credenciais, etc.]
- **Mitigação (em andamento ou planejada):**
  [Listar — patches, mudanças arquiteturais, treinamento de equipe, contratação de forense externo, etc.]
- **Comunicação aos titulares:**
  - [ ] Realizada em [DD/MM/AAAA] por [e-mail | telefone | aviso público]
  - [ ] A realizar até [DD/MM/AAAA]
  - [ ] Não aplicável — justificativa: [...]

## Plano de continuidade

- **Sistemas afetados:** [listar — Yuris, integração WhatsApp, banco de dados, etc.]
- **Sistemas operacionais agora?** [sim | parcial | não — em recuperação]
- **Previsão de normalização total:** [DD/MM/AAAA]

## Encarregados e contatos

- **Encarregado (DPO):** [nome] — [e-mail] — [telefone]
- **Responsável técnico:** [nome] — [e-mail] — [telefone]
- **Responsável jurídico:** [nome] — [e-mail] — [telefone] (se aplicável)

## Anexos

- [ ] Cópia do registro interno do incidente (export do Painel Master)
- [ ] Texto da comunicação enviada aos titulares
- [ ] Print/log das evidências (sanitizado para não conter dados de outros titulares)
- [ ] Outros: [...]

---

**Assinatura eletrônica do Encarregado**
[NOME] — [DATA] — [CPF mascarado]

> Após o envio, **registre o número de protocolo da ANPD** no campo `notificacao_anpd_protocolo` do incidente em `Painel Master → Incidentes → #INC-NNNNNN`.

---

> **Aviso jurídico interno:** este é um modelo orientativo, baseado no formulário público da ANPD e nas guidelines da Autoridade.  A ANPD pode revisar/atualizar o formulário oficial — sempre verifique a versão vigente em `https://www.gov.br/anpd/`.  Em incidentes de alta/crítica severidade, **revisão por advogado especializado é mandatória** antes do envio.
