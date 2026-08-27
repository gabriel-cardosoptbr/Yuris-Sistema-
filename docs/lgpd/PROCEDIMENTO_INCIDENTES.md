# Procedimento de Resposta a Incidentes de Segurança (LGPD Art. 48)

**Versão:** 1.0 — 2026-05-23
**Responsável:** Encarregado de Proteção de Dados (DPO) — Yuris
**Aplicação:** Toda equipe técnica, operacional e administrativa

---

## 1. Objetivo

Definir o fluxo padronizado de **detecção, contenção, mitigação e comunicação** de incidentes de segurança envolvendo dados pessoais armazenados, transmitidos ou processados pela plataforma Yuris, em cumprimento ao **Art. 48 da Lei 13.709/2018 (LGPD)**.

## 2. Definição

Considera-se **incidente de segurança com dados pessoais** todo evento, confirmado ou suspeito, que tenha causado ou possa causar:

- Acesso não autorizado a dados pessoais;
- Destruição, perda, alteração, comunicação ou divulgação não autorizadas;
- Indisponibilidade não programada de sistemas que armazenam dados pessoais;
- Comprometimento de credenciais (senhas, tokens, chaves de API);
- Falhas em provedores terceiros (operadores) que tratem dados em nome da Yuris.

> **Incidente ≠ vulnerabilidade.** Uma vulnerabilidade descoberta antes de ser explorada gera ticket de hardening, não incidente — salvo se houver evidência de exploração.

## 3. Papéis e responsabilidades

| Papel | Responsabilidade |
|------|------------------|
| **Quem detecta** | Notificar o DPO imediatamente (canal: e-mail DPO + abrir registro em `Painel Master → Incidentes`). |
| **DPO** | Conduzir a investigação; decidir sobre notificação à ANPD e titulares; aprovar comunicados. |
| **Equipe técnica** | Conter, coletar evidências, aplicar correções, fornecer dados ao DPO. |
| **Direção** | Aprovar comunicados públicos; mobilizar recursos extraordinários (forense, jurídico). |

## 4. Fluxo padrão (6 passos)

### Passo 1 — Registro (T+0)
- Quem detecta cria o incidente em `Painel Master → Incidentes → + Novo Incidente`.
- Preencher minimamente: **título, tipo, severidade preliminar, detectado_em, descrição interna**.
- Salvar.  Sistema gera identificador `INC-NNNNNN`.

### Passo 2 — Avaliação inicial (T+1h para crítica, T+4h para alta)
DPO classifica:
- **Severidade definitiva:** baixa | média | alta | crítica.
- **Categorias de dados afetados:** PII básica | documentos | financeiro | jurídico | autenticação | comunicações | sensíveis.
- **Estimativa de titulares e registros** afetados.
- **Probabilidade** de exploração / divulgação.

Status do incidente: `em_analise`.

### Passo 3 — Contenção (T+12h para crítica)
Equipe técnica aplica medidas imediatas:
- Bloqueio de IPs maliciosos, revogação de sessões/tokens, rotação de credenciais comprometidas.
- Isolamento de hosts comprometidos; snapshot de evidências (logs, dumps).
- Atualização do campo "medidas_imediatas" no incidente.

Status: `contido` quando o vetor estiver neutralizado.

### Passo 4 — Notificação ANPD e Titulares (T+ a critério, normalmente até 2 dias úteis após contenção)
**Critérios de notificação (Art. 48 §1):**
- Risco de dano relevante aos titulares (financeiro, físico, moral, discriminatório, reputacional).
- Comprometimento de **dados sensíveis** (Art. 5, II).
- Vazamento massivo ainda que de dados básicos.

**Quando NÃO notificar:** falsos positivos confirmados, incidentes contidos antes de causar acesso a dados pessoais reais (ex.: tentativa bloqueada por WAF).

**Como notificar:**
- **ANPD:** Portal ANPD (`https://www.gov.br/anpd/`). Usar template em `MODELO_NOTIFICACAO_ANPD.md`. Registrar protocolo no campo `notificacao_anpd_protocolo`.
- **Titulares:** E-mail individualizado (preferencial), telefone para incidentes críticos, ou aviso público quando contato direto for inviável. Usar template em `MODELO_NOTIFICACAO_TITULAR.md`.

Status: `notificado_anpd` → `notificado_titulares`.

### Passo 5 — Mitigação e correção definitiva (T+7d a 30d)
- Identificar **causa raiz** (não confundir com sintoma).
- Aplicar correções permanentes (patches, mudanças de arquitetura, treinamento).
- Documentar lições aprendidas em `medidas_corretivas`.
- Atualizar políticas/procedimentos se necessário.

Status: `mitigado`.

### Passo 6 — Encerramento
- Confirmar que todas as ações pendentes foram concluídas.
- Anexar evidências finais (relatório forense, comprovantes de notificação).
- Encerrar incidente.  Sistema marca `encerrado_em` e congela registro.

Status: `encerrado`.

> Após encerrado, o registro permanece **imutável na timeline** (`security_incident_events`).  Apenas comentários novos podem ser adicionados via `add_event`.

## 5. Matriz de severidade × prazo

| Severidade | Critério típico | Prazo p/ avaliação | Prazo p/ notificação |
|------------|-----------------|---------------------|----------------------|
| **Crítica** | Dados sensíveis vazados, ransomware com criptografia ativa, >10.000 titulares afetados | 1h | 24h |
| **Alta**    | Vazamento de PII básica em escala, comprometimento de credenciais admin | 4h | 48h |
| **Média**   | Acesso indevido contido sem dados extraídos, brute-force massivo bloqueado | 24h | quando aplicável |
| **Baixa**   | Tentativas isoladas sem sucesso, anomalia investigada e descartada | 72h | normalmente não notificado |

## 6. Comunicação interna

- **Crítica/Alta:** notificar DPO + Direção em até 30 min.  Status semanal até encerramento.
- **Média/Baixa:** comunicado por e-mail para o DPO em 24h.

Toda comunicação interna sobre o incidente deve ser registrada como `tipo_evento=comentario` no incidente.

## 7. Retenção dos registros

`security_incidents` e `security_incident_events` são **mantidos indefinidamente**.  Triggers SQL bloqueiam UPDATE/DELETE em events (`trg_sie_no_update`, `trg_sie_no_delete` — migration 054).  Para auditoria pela ANPD ou em ações judiciais.

## 8. Treinamento

Este procedimento deve ser revisado **anualmente** e sempre após incidentes de severidade alta/crítica.  Toda nova contratação técnica recebe treinamento em até 30 dias.

---

**Revisão:** 1.0 (2026-05-23) — versão inicial, alinhada à Etapa 8 do roadmap LGPD.
**Próxima revisão prevista:** 2027-05-23.
