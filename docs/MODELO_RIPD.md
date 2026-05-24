# Modelo de Relatório de Impacto à Proteção de Dados (RIPD / DPIA)

**Base legal:** LGPD Art. 5 XVII (definição) + Art. 38 (a ANPD pode requerê-lo).
**Versão:** 1.0 — 2026-05-23
**Quando aplicar:** sempre que um novo tratamento (ou alteração relevante em tratamento existente) puder gerar **risco alto** aos direitos e liberdades dos titulares.

---

## 1. Quando o RIPD é obrigatório (na prática)

Realizar antes do lançamento quando o tratamento envolver, por exemplo:

- **Dados sensíveis** em escala (LGPD Art. 5 II — saúde, biometria, religião, etc.);
- **Dados de crianças/adolescentes**;
- **Decisões automatizadas** com impacto significativo (Art. 20);
- **Monitoramento sistemático** de comportamento;
- **Combinação de bases de dados** distintas que gerem perfil ampliado;
- **Transferência internacional** de grande volume para país sem decisão de adequação;
- **Uso de tecnologia emergente** sem precedente regulatório claro (ex.: novo modelo de LLM para análise de petições).

Em caso de dúvida: **fazer**. RIPD é barato em relação ao risco de erro.

---

## 2. Modelo / template

Copie esta seção e preencha. Salve como `RIPD_[ID_PROJETO]_[YYYYMMDD].md` em pasta interna.

---

### RELATÓRIO DE IMPACTO À PROTEÇÃO DE DADOS

#### A. Metadados

- **Título do tratamento:** [...]
- **Responsável pela operação:** [nome / cargo]
- **DPO:** [nome] · [e-mail]
- **Data desta versão:** [DD/MM/AAAA]
- **Versão do documento:** [1.0]
- **Status:** [rascunho / em análise / aprovado / rejeitado]

#### B. Descrição do tratamento

1. **Finalidade(s) específicas:**
   [Texto claro: por quê precisamos tratar estes dados? Quem se beneficia?]

2. **Necessidade demonstrada:**
   [Argumentar por que não dá pra fazer com menos dados, ou agregados, ou anonimizados.]

3. **Base legal aplicável** (LGPD Art. 7 ou Art. 11):
   - [ ] Consentimento (Art. 7 I)
   - [ ] Cumprimento de obrigação legal (Art. 7 II)
   - [ ] Execução de políticas públicas (Art. 7 III)
   - [ ] Estudos por órgão de pesquisa (Art. 7 IV)
   - [ ] Execução de contrato (Art. 7 V)
   - [ ] Exercício regular de direitos em processo (Art. 7 VI)
   - [ ] Proteção da vida (Art. 7 VII)
   - [ ] Tutela da saúde (Art. 7 VIII)
   - [ ] Legítimo interesse (Art. 7 IX) — **se marcado, anexar teste de balanceamento**
   - [ ] Proteção do crédito (Art. 7 X)
   - [ ] Dados sensíveis (Art. 11) — qual hipótese específica?

4. **Categorias de dados pessoais tratados:**
   [PII básica / documentos / financeiros / jurídicos / autenticação / comunicações / sensíveis — detalhar.]

5. **Categorias de titulares:**
   [Usuários finais da plataforma / clientes dos advogados / leads / etc.]

6. **Origem dos dados:** [coleta direta do titular / fornecido por terceiro / scraping / etc.]

7. **Operadores envolvidos:** [listar — verificar inventário `data_processors`]

8. **Fluxo de dados** (descrição ou diagrama):
   [Onde os dados são coletados → onde armazenados → quem acessa → para onde transmitidos.]

#### C. Avaliação de riscos

Para cada risco identificado, classificar:

| Risco identificado | Probabilidade (1-5) | Impacto (1-5) | Severidade total (P×I) | Notas |
|--------------------|---------------------|---------------|------------------------|-------|
| [Exemplo: vazamento por SQL injection] | 2 | 4 | 8 | Mitigado por prepared statements |
| [...] | | | | |
| [...] | | | | |

Riscos com severidade ≥ 12 (4×3 ou 3×4 ou maior) exigem mitigação documentada antes do lançamento.

#### D. Medidas mitigatórias

Para cada risco médio/alto, descrever:

| Risco | Medida adotada | Responsável | Prazo | Status |
|-------|----------------|-------------|-------|--------|
| [...] | [...] | [...] | [...] | [ ] |

#### E. Direitos dos titulares — como são atendidos

- [ ] Como o titular toma conhecimento do tratamento (transparência)?
- [ ] Como pode acessar/corrigir seus dados?
- [ ] Como pode revogar consentimento (se aplicável)?
- [ ] Como pode solicitar eliminação/anonimização?
- [ ] Como pode pedir portabilidade?
- [ ] Como pode se opor (se aplicável)?

#### F. Transferência internacional

- [ ] Não há.
- [ ] Há. Detalhar país, operador, base legal (Art. 33), garantias adicionais.

#### G. Período de retenção

- Prazo previsto para os dados deste tratamento:
- O que acontece ao final (anonimização / eliminação física):
- Como é tecnicamente executado (cron, manual, vinculado a evento):

#### H. Decisão final

- [ ] **APROVADO** — pode prosseguir com as medidas mitigatórias listadas.
- [ ] **APROVADO COM CONDIÇÕES** — listar condições prévias ao lançamento.
- [ ] **REJEITADO** — descrever motivos pelos quais o tratamento não é viável conforme proposto.

**Aprovador (DPO):** _________________________ Data: ___/___/___

**Aprovador (Diretoria, se severidade ≥ alta):** _________________________ Data: ___/___/___

#### I. Revisão programada

- Próxima revisão deste RIPD em: ___/___/___ (recomendado: anual ou ao alterar o tratamento).
- Gatilhos para revisão antecipada:
  - Mudança em base legal aplicável;
  - Incidente envolvendo este tratamento;
  - Mudança em volume ou natureza dos dados;
  - Mudança regulatória relevante.

---

## 3. Histórico de RIPDs realizados

| ID | Tratamento | Data | Resultado | Próx. revisão |
|----|-----------|------|-----------|----------------|
| (vazio — a Yuris ainda não possui tratamentos de alto risco identificados que exijam RIPD formal nesta fase) | | | | |

> A primeira aplicação prática deste modelo deve ocorrer quando: (a) for ativada uma feature de análise automatizada de petições por LLM, ou (b) for processado dado de saúde de cliente (cenário sem precedente atual).

## 4. Conformidade

Atende LGPD Art. 5 XVII + Art. 38 + alinhado a guidance da ANPD (Guia Orientativo de Incidentes 2023 + Guia de RIPD se publicado).

## 5. Revisão deste modelo

Anual. Próxima revisão prevista: **2027-05-23**.
