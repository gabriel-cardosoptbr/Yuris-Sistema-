# Modelo de Acordo de Tratamento de Dados (DPA — Data Processing Agreement)

**Base legal:** LGPD Art. 39 — *"O operador deverá realizar o tratamento segundo as instruções fornecidas pelo controlador, que verificará a observância das próprias instruções e das normas sobre a matéria."*

> **Aviso jurídico essencial:** este é um **modelo de partida**, alinhado às cláusulas mínimas que a LGPD e a doutrina especializada apontam como esperadas em contratos com operadores.
> **Toda assinatura de DPA real DEVE passar por advogado especializado em proteção de dados.**
> Use este modelo para abrir negociação, não como contrato final.

---

## Identificação das partes

**CONTROLADOR:**
- Razão social: [RAZÃO SOCIAL DA EMPRESA YURIS]
- CNPJ: [CNPJ]
- Endereço: [ENDEREÇO]
- Encarregado (DPO): [NOME] — [E-MAIL DPO]

**OPERADOR:**
- Razão social: [NOME DO TERCEIRO]
- CNPJ ou registro internacional: [...]
- País: [...]
- Encarregado/contato responsável: [NOME] — [E-MAIL]

---

## Cláusula 1 — Objeto

O OPERADOR realizará o tratamento de dados pessoais em nome do CONTROLADOR para a(s) seguinte(s) finalidade(s):

> [DESCRIÇÃO ESPECÍFICA — ex.: "envio de mensagens WhatsApp a clientes finais", "hospedagem da base de dados", "processamento de pagamentos de assinaturas"].

## Cláusula 2 — Categorias de dados pessoais

O tratamento envolverá as seguintes categorias:

- [ ] Dados de identificação (nome, e-mail, telefone)
- [ ] Documentos (CPF, RG, OAB, CNH)
- [ ] Dados financeiros
- [ ] Dados de processos jurídicos
- [ ] Credenciais (somente em formato hash/cifrado)
- [ ] Conteúdo de comunicações
- [ ] Dados sensíveis (Art. 5 II — exige cláusulas reforçadas)
- [ ] Dados de crianças e adolescentes (exige instruções específicas — Art. 14)

## Cláusula 3 — Categorias de titulares

- [ ] Usuários finais da plataforma (advogados clientes da Yuris)
- [ ] Clientes dos advogados (contatos, partes processuais)
- [ ] Visitantes do site/aplicativo
- [ ] Funcionários da Yuris (DPA com operador de RH, p.ex.)

## Cláusula 4 — Duração

Este DPA vigora enquanto durar o contrato principal entre as partes, sendo automaticamente renovado a cada [12 / 24] meses, **salvo denúncia escrita com 60 dias de antecedência**.

Validade inicial: até [DATA].

## Cláusula 5 — Instruções do CONTROLADOR

O OPERADOR tratará os dados **exclusivamente** conforme:
1. As instruções documentadas neste DPA;
2. Instruções complementares enviadas por escrito pelo CONTROLADOR (DPO);
3. Obrigações legais aplicáveis (com aviso prévio ao CONTROLADOR, salvo proibição legal).

**Qualquer tratamento fora destas instruções configura o OPERADOR como CONTROLADOR autônomo daquele tratamento, com todas as responsabilidades correlatas.**

## Cláusula 6 — Segurança da informação (Art. 46)

O OPERADOR se compromete a adotar medidas técnicas e administrativas adequadas, incluindo, no mínimo:

- Criptografia em trânsito (TLS 1.2+) e em repouso quando aplicável;
- Controle de acesso baseado em função (RBAC);
- Autenticação forte (MFA) para acessos administrativos;
- Logs de acesso retidos por no mínimo 6 meses;
- Atualizações de segurança aplicadas em até 30 dias da divulgação;
- Avaliações periódicas de vulnerabilidade;
- Plano de continuidade de negócio com RPO/RTO acordados.

## Cláusula 7 — Suboperadores (subcontratação)

O OPERADOR **não poderá** subcontratar terceiros sem **autorização prévia e por escrito** do CONTROLADOR.  Lista inicial de suboperadores autorizados:

| Suboperador | Finalidade | País |
|-------------|-----------|------|
| [...] | [...] | [...] |

Em caso de novo suboperador, o OPERADOR notificará o CONTROLADOR com 30 dias de antecedência, dando-lhe direito a vetar.

## Cláusula 8 — Transferência internacional (Art. 33)

- [ ] **Não há** transferência internacional neste tratamento.
- [ ] **Há** transferência para: [PAÍS(ES)].  Base legal:
  - [ ] Decisão da ANPD reconhecendo país com nível adequado (Art. 33 I)
  - [ ] Cláusulas-padrão contratuais (Art. 33 II)
  - [ ] Regras corporativas globais aprovadas (Art. 33 II)
  - [ ] Autorização específica da ANPD (Art. 33 V)
  - [ ] Cooperação jurídica internacional (Art. 33 III)
  - [ ] Consentimento específico e destacado do titular (Art. 33 VIII)
  - [ ] Cumprimento de obrigação legal (Art. 33 VI)
  - [ ] Execução de contrato com o titular (Art. 33 VII)

## Cláusula 9 — Direitos dos titulares (Art. 18)

O OPERADOR **colaborará** com o CONTROLADOR no atendimento aos direitos dos titulares, fornecendo:
- Resposta a solicitações de acesso em até **5 dias úteis**;
- Eliminação/anonimização de registros em até **15 dias** quando solicitado;
- Portabilidade dos dados em formato estruturado.

## Cláusula 10 — Notificação de incidentes (Art. 48)

O OPERADOR comunicará ao CONTROLADOR **imediatamente** (e nunca após **24 horas**) qualquer incidente de segurança envolvendo dados pessoais, contendo:
- Natureza e escopo do incidente;
- Categorias e número aproximado de titulares afetados;
- Medidas tomadas para conter e mitigar;
- Pessoa de contato para mais informações.

## Cláusula 11 — Auditoria

O CONTROLADOR poderá realizar auditorias (próprias ou por terceiro independente sujeito a NDA) na operação do OPERADOR, com aviso prévio de **30 dias**, no máximo **uma vez por ano**, salvo incidente confirmado, em horário comercial e sem prejudicar a continuidade do serviço.

O OPERADOR fornecerá, anualmente, evidência das certificações vigentes (ex.: ISO 27001, SOC 2 Type II).

## Cláusula 12 — Eliminação ao final

Ao término deste DPA, o OPERADOR:
- [ ] Devolverá ao CONTROLADOR todos os dados em formato estruturado em até **30 dias**;
- [ ] Eliminará as cópias em sua posse em até **60 dias após a devolução**;
- [ ] Fornecerá certidão de eliminação assinada pelo seu DPO.

## Cláusula 13 — Responsabilidade

O OPERADOR responderá solidariamente com o CONTROLADOR pelos danos causados ao titular em razão de tratamento que tenha realizado em desacordo com este DPA ou com a LGPD (Art. 42 §1 II).

Cada parte indenizará a outra pelos prejuízos diretos comprovadamente causados pelo descumprimento de suas obrigações neste DPA.

## Cláusula 14 — Foro

Fica eleito o foro de **[COMARCA DO CONTROLADOR]** para dirimir quaisquer controvérsias.

---

E por estarem assim justos e contratados, assinam o presente em 2 vias.

**CONTROLADOR:** ______________________ Data: ___/___/___
[Nome do representante legal]

**OPERADOR:** ______________________ Data: ___/___/___
[Nome do representante legal]

**DPOs (anuência):**

CONTROLADOR — [NOME DPO] _______________
OPERADOR — [NOME DPO] _______________

---

> **Próximos passos após assinatura:**
> 1. Registrar no `Painel Master → Operadores → editar → DPA Assinado em [DATA] · Validade [DATA] · URL [link]`.
> 2. Arquivar PDF assinado em local seguro (storage interno, não público).
> 3. Configurar lembrete de renovação 60 dias antes do vencimento.

---

**Versão do modelo:** 1.0 — 2026-05-23
**Revisão recomendada:** anual ou quando a ANPD publicar novas guidelines sobre o Art. 33/39.
