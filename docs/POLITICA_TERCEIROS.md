# Política de Contratação de Operadores (Terceiros)

**Base legal:** LGPD Art. 33, Art. 37, Art. 39, Art. 46.
**Versão:** 1.0 — 2026-05-23
**Aplicação:** todos os contratos que envolvam compartilhamento ou processamento de dados pessoais por terceiros em nome da Yuris.

---

## 1. Objetivo

Estabelecer critérios mínimos para **avaliar, contratar e monitorar** terceiros (operadores, suboperadores, fornecedores de infraestrutura) que tratem dados pessoais em nome da Yuris, garantindo conformidade com a LGPD e preservação dos direitos dos titulares.

## 2. Quando esta política se aplica

Aplica-se sempre que houver compartilhamento de dados pessoais — em qualquer volume — com:
- APIs externas (ex.: Evolution WhatsApp, OpenAI, gateway de pagamento);
- Provedores de hospedagem, CDN, backup, monitoramento;
- Ferramentas SaaS contratadas para processar dados (CRM, e-mail marketing, analytics);
- Terceirizados (suporte humano, freelancers, consultorias que acessem o ambiente).

**NÃO se aplica** a softwares 100% on-premise instalados pela Yuris em infraestrutura própria sem comunicação externa (ex.: editor de texto local).

## 3. Fluxo de contratação (5 etapas)

### Etapa 1 — Avaliação prévia (responsável: gestor do projeto + DPO)

Antes de contratar qualquer terceiro, preencher o **checklist** abaixo:

- [ ] Qual a **finalidade** do compartilhamento?  É realmente necessário?
- [ ] Quais **categorias de dados** serão expostas?  Inclui sensíveis (Art. 5 II)?
- [ ] Quantos titulares serão afetados?
- [ ] O terceiro tem **política de privacidade pública** e claramente alinhada à LGPD?
- [ ] O terceiro tem **certificações** reconhecidas? (ISO 27001, SOC 2 Type II, ISO 27701 são as mais relevantes)
- [ ] O terceiro tem **DPO ou contato dedicado** para questões de proteção de dados?
- [ ] Há **transferência internacional**?  Se sim, qual a base legal viável (Art. 33)?
- [ ] O terceiro divulga histórico de incidentes?  Como foram tratados?

**Se houver dado sensível ou de criança/adolescente: necessária aprovação adicional do DPO antes de prosseguir.**

### Etapa 2 — Negociação contratual (responsável: jurídico + DPO)

- Usar `DPA_TEMPLATE.md` como base de negociação.
- Cláusulas que **não devem ser flexibilizadas**:
  - Limitação de tratamento às instruções do controlador (Cl. 5);
  - Obrigação de notificar incidentes em até 24h (Cl. 10);
  - Direito do controlador de auditar (Cl. 11);
  - Eliminação ao final do contrato (Cl. 12);
  - Responsabilidade solidária por descumprimento da LGPD.
- Cláusulas que **podem ser ajustadas** conforme contexto: prazos de SLA, modelo de pricing, jurisdição (se terceiro estrangeiro), suboperadores autorizados.

### Etapa 3 — Registro no inventário

Após assinatura do DPA, registrar no `Painel Master → Operadores → + Novo Operador`:
- Nome, categoria, papel, CNPJ/ID, país;
- Categorias de dados tratados (checkboxes);
- Finalidade e retenção pelo terceiro;
- Transferência internacional + base legal (Art. 33);
- Status DPA = `assinado`, data de assinatura, validade, URL do PDF;
- Certificações;
- Contato DPO do terceiro;
- URL da política de privacidade do terceiro.

O sistema gera automaticamente entrada em `data_processor_history` (auditoria imutável).

### Etapa 4 — Monitoramento contínuo

- **Badge no Painel Master** alerta DPAs vencendo nos próximos 30 dias.
- **Anualmente** o DPO revisa: o terceiro mantém certificações?  Houve incidentes?  Mudou subcontratação sem aviso?
- **Sempre que o terceiro mudar sua política de privacidade**, reavaliar.

### Etapa 5 — Encerramento

Quando o contrato terminar:
- Acionar Cl. 12 do DPA (devolução + eliminação + certidão);
- No Painel Master, marcar o operador como `desativado` com motivo;
- O registro **permanece no histórico** (não é apagado — auditoria preservada).

## 4. Casos especiais

### 4.1 Transferência internacional (Art. 33)

Mapear **antes de contratar** qual a base legal viável.  Em ordem de preferência:
1. **País com decisão de adequação da ANPD** (Art. 33 I) — quando a ANPD listar oficialmente.
2. **Cláusulas-padrão contratuais** (Art. 33 II) — quando a ANPD publicar modelos oficiais.
3. **Consentimento específico e destacado do titular** (Art. 33 VIII) — usado para LLMs; obriga aviso claro no momento da coleta.
4. **Execução de contrato com o titular** (Art. 33 VII) — quando o terceiro é parte da prestação do serviço final.

Documentar a base escolhida no campo `base_legal_transferencia`.

### 4.2 Dados sensíveis

Se o terceiro tratar dados sensíveis (Art. 5 II), **exigir adicionalmente**:
- Cláusula de cifragem em repouso (não apenas em trânsito);
- Restrição de acesso a equipe específica do terceiro com NDA reforçado;
- Possibilidade de auditoria com prazo reduzido (15 dias em vez de 30).

### 4.3 Operadores estrangeiros sem CNPJ

- Exigir identificação fiscal local (EIN nos EUA, VAT na UE, etc.).
- Cláusula de eleição de foro pode ser desafiada — preferir cláusula compromissória de arbitragem em câmara reconhecida (CAM-CCBC, ICC) com lei brasileira aplicável.

### 4.4 Open-source self-hosted

- Software open-source instalado em infra própria da Yuris (ex.: MariaDB, Apache, Evolution API self-hosted) **não exige DPA** — a Yuris é a controladora *e* operadora.
- Registrar com `dpa_status = dispensado` e nota explicativa.
- Responsabilidade pela segurança recai **integralmente** sobre a Yuris.

## 5. Casos vetados

A Yuris **não contratará** operadores que:
- Não tenham política de privacidade pública;
- Estejam baseados em países sem qualquer estrutura de proteção de dados análoga à LGPD/GDPR e sem garantias contratuais;
- Tenham histórico recente (últimos 12 meses) de incidente grave não devidamente comunicado;
- Não aceitem cláusula de notificação obrigatória de incidentes em até 24h;
- Não aceitem cláusula de auditoria;
- Vendam ou monetizem os dados que processam em nome de controladores.

## 6. Responsabilidades

- **DPO** — avaliação prévia, negociação de DPA, monitoramento contínuo, decisão final sobre vetos.
- **Direção** — aprovação de exceções a esta política.
- **Equipe técnica** — implementação dos controles técnicos exigidos no DPA, alertar sobre integrações em desenvolvimento.
- **Jurídico** — redação final do contrato + DPA, análise de cláusulas atípicas.

## 7. Revisão

Esta política é revisada **anualmente** ou sempre que:
- A ANPD publicar normas/guidelines sobre transferência internacional;
- Houver incidente envolvendo operador (lições aprendidas);
- A empresa entrar em novo mercado com operadores adicionais.

---

**Versão:** 1.0 (2026-05-23)
**Próxima revisão prevista:** 2027-05-23.
