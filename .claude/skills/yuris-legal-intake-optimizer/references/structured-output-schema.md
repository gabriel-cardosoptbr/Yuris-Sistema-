# Structured Output do agente (JSON canônico)

O agente responde **somente** com este JSON. O mesmo schema vale em produção e nos testes.

**Fonte de verdade (código):** `app/Services/AiIntake/IntakeSchema.php`
(`IntakeSchema::jsonSchema()` para o `response_format` e a validação; `IntakeSchema::defaults()`
para o fallback). Em produção é enviado como `response_format` `json_schema` **strict** da
OpenAI (envelope `IntakeSchema::responseFormat()`, `name: "pre_atendimento"`, `strict: true`).
Este documento **descreve** esse schema; se divergir do PHP, o PHP vence.

> ⚠️ O validador `scripts/validate_schema.py` é uma ferramenta de desenvolvimento e pode estar
> defasado em relação a `IntakeSchema.php`. Em caso de dúvida, confira sempre o PHP.

## O que o modelo emite (e o que NÃO emite)

O modelo faz **apenas** classificação, extração, urgência, sinalização e resumo. Coisas que
parecem "saída do modelo" mas são do **backend** (não estão no schema):

- **Estado da conversa** (`conversation_state`/máquina de estados): **não existe no schema**. O
  estado é gerido no backend (`IntakeStateMachine` + coluna `ai_intake_sessions.current_state`)
  a partir dos sinais do modelo. Ver `conversation-state-machine.md`.
- **Identidade de canal/sessão** (`channel_id`, `session_id`): resolvidas e persistidas no
  backend (`IntakeSessionRepository`); o modelo não as recebe nem as repete na saída.
- **Texto da resposta ao cliente**: o `IntakeEngine` compõe o texto de forma **determinística**
  (banco de perguntas + mensagens da config: saudação, handoff, urgência, info do escritório). O
  modelo só sugere a **chave** da próxima pergunta (`suggested_next_question_key`), nunca a copy.

Constraints de tamanho/formato (max/min/pattern) **não** vão no schema enviado ao modelo (regra
do strict mode); são validados no backend. Por isso o JSON abaixo não tem `maxLength` etc.

## Campos (nível raiz)

Todos obrigatórios (strict mode: opcional = união com `null`). `additionalProperties: false`.

| Campo | Tipo | Notas |
|---|---|---|
| `intent` | enum | `office_information`,`new_intake`,`existing_case`,`human_request`,`document_submission`,`non_legal`,`unknown` |
| `primary_practice_area` | string\|null | Área provável (código/nome; deve estar nas áreas habilitadas do tenant) ou null. |
| `secondary_practice_areas` | array[string] | Áreas secundárias prováveis (pode ser vazio). |
| `classification_confidence` | number | Confiança da classificação (0..1). Persistida na sessão. |
| `answer_relevant_to_current_question` | boolean | A resposta do cliente atende à pergunta atual? (apoia o anti-repetição). |
| `extracted_data` | object | Ver abaixo. `additionalProperties: false`. |
| `urgency_level` | enum | `normal`,`high`,`critical`. |
| `urgency_reasons` | array[string] | Motivos/sinais de urgência (palavras/critérios casados). Vazio se `normal`. |
| `risk_flags` | array[string] | Marcadores de risco (ex.: tentativa de injection, conflito, fora de escopo). |
| `missing_essential_fields` | array[string] | Campos essenciais ainda faltando (chaves de `extracted_data`). |
| `suggested_next_question_key` | string\|null | **Chave** da próxima pergunta (não o texto) ou null. Chaves: `motivo`,`quando`,`processo`,`prazo`,`documentos`,`cidade`,`area`. |
| `enough_for_handoff` | boolean | Modelo julga ter dados suficientes para encaminhar. |
| `should_handoff_immediately` | boolean | Encaminhar **agora** (urgência/pedido explícito de humano). |
| `should_stop_bot` | boolean | O bot automático deve parar de responder (spam/fora de escopo/já encaminhado). |
| `summary` | string | Resumo objetivo do caso (entra no card/notificação do handoff). |

`extracted_data` (objeto, `additionalProperties: false`, todos obrigatórios):

| Campo | Tipo | Notas |
|---|---|---|
| `name` | string\|null | Nome do contato, se informado. |
| `city` | string\|null | Cidade, se informada. |
| `state` | string\|null | UF, se informada. |
| `main_report` | string\|null | Relato objetivo dos fatos (o "resumo do caso" do cliente). |
| `event_date` | string\|null | Quando aconteceu (texto livre; ex.: "ontem", "10/05"). |
| `has_existing_case` | boolean\|null | Já existe processo? (null = ainda não se sabe). |
| `case_number` | string\|null | Número do processo, se houver. |
| `has_deadline` | boolean\|null | Há prazo/intimação? (null = desconhecido). |
| `deadline_description` | string\|null | Descrição do prazo (ex.: "intimação com 5 dias"). |
| `has_hearing` | boolean\|null | Há audiência marcada? (null = desconhecido). |
| `hearing_date` | string\|null | Data/descrição da audiência. |
| `has_official_notice` | boolean\|null | Recebeu intimação/notificação oficial? (null = desconhecido). |
| `has_documents` | boolean\|null | Cliente tem documentos? (null = desconhecido). |
| `mentioned_documents` | array[string] | Tipos de documento citados/recebidos; sem analisar conteúdo. |

## Schema (machine-readable)

Espelha `IntakeSchema::jsonSchema()`. Strict mode da OpenAI: `additionalProperties:false` em todo
objeto, **todos** os campos em `required`, sem `min/max/pattern` (validados no backend).

```json
{
  "type": "object",
  "additionalProperties": false,
  "required": [
    "intent", "primary_practice_area", "secondary_practice_areas", "classification_confidence",
    "answer_relevant_to_current_question", "extracted_data", "urgency_level", "urgency_reasons",
    "risk_flags", "missing_essential_fields", "suggested_next_question_key", "enough_for_handoff",
    "should_handoff_immediately", "should_stop_bot", "summary"
  ],
  "properties": {
    "intent": {"type": "string", "enum": ["office_information","new_intake","existing_case","human_request","document_submission","non_legal","unknown"]},
    "primary_practice_area": {"type": ["string","null"]},
    "secondary_practice_areas": {"type": "array", "items": {"type": "string"}},
    "classification_confidence": {"type": "number"},
    "answer_relevant_to_current_question": {"type": "boolean"},
    "extracted_data": {
      "type": "object",
      "additionalProperties": false,
      "required": [
        "name", "city", "state", "main_report", "event_date", "has_existing_case",
        "case_number", "has_deadline", "deadline_description", "has_hearing", "hearing_date",
        "has_official_notice", "has_documents", "mentioned_documents"
      ],
      "properties": {
        "name": {"type": ["string","null"]},
        "city": {"type": ["string","null"]},
        "state": {"type": ["string","null"]},
        "main_report": {"type": ["string","null"]},
        "event_date": {"type": ["string","null"]},
        "has_existing_case": {"type": ["boolean","null"]},
        "case_number": {"type": ["string","null"]},
        "has_deadline": {"type": ["boolean","null"]},
        "deadline_description": {"type": ["string","null"]},
        "has_hearing": {"type": ["boolean","null"]},
        "hearing_date": {"type": ["string","null"]},
        "has_official_notice": {"type": ["boolean","null"]},
        "has_documents": {"type": ["boolean","null"]},
        "mentioned_documents": {"type": "array", "items": {"type": "string"}}
      }
    },
    "urgency_level": {"type": "string", "enum": ["normal","high","critical"]},
    "urgency_reasons": {"type": "array", "items": {"type": "string"}},
    "risk_flags": {"type": "array", "items": {"type": "string"}},
    "missing_essential_fields": {"type": "array", "items": {"type": "string"}},
    "suggested_next_question_key": {"type": ["string","null"]},
    "enough_for_handoff": {"type": "boolean"},
    "should_handoff_immediately": {"type": "boolean"},
    "should_stop_bot": {"type": "boolean"},
    "summary": {"type": "string"}
  }
}
```

## Invariantes e decisões (no backend, além do schema)

O modelo **sinaliza**; o `IntakeEngine` **decide**. Não documentar como se o modelo controlasse o
fluxo.

- **Handoff efetivo** (`IntakeEngine`): encaminha para humano quando
  `urgency_level == 'critical'` **ou** `intent ∈ {human_request, existing_case}` **ou**
  `should_handoff_immediately` **ou** `enough_for_handoff` **ou** o limite de perguntas
  (`max_questions`, 3..8) foi atingido. Ao encaminhar: estado → `awaiting_human`, `agent_paused = 1`.
- **Urgência crítica é defendida no servidor**: `Taxonomy::detectUrgency()` pode **forçar**
  `urgency_level = 'critical'` (e ligar `should_handoff_immediately`/`enough_for_handoff`) mesmo
  que o modelo diga o contrário.
- **`urgency_reasons`** acompanha `urgency_level`: vazio quando `normal`; preenchido em
  `high`/`critical` (sinais casados). É **array**, não string.
- **Próxima pergunta**: o backend usa `suggested_next_question_key`, mas **nunca repete** pergunta
  já feita (`asked_questions`) e respeita o limite; se a chave sugerida já foi usada, escolhe a
  próxima essencial (`IntakeEngine::pickNextKey`). O **texto** vem do banco de perguntas, não do modelo.
- **`non_legal`** → encerra com cordialidade (estado `completed`). **`office_information`** sem
  handoff → responde com os dados cadastrados do escritório (estado `identifying_intent`).
- **Mídia/áudio** (mensagem não-texto): o backend confirma o recebimento e marca
  `has_documents`/`mentioned_documents` **sem** chamar o modelo (não envia o conteúdo à OpenAI).

## Mapa de migração (doc antigo → schema real)

Versões antigas deste doc descreviam campos em português que **não existem** no código. Equivalências:

| Doc antigo (inexistente) | Real (`IntakeSchema.php`) |
|---|---|
| `conversation_state` | removido — estado é backend (`current_state` + `IntakeStateMachine`) |
| `channel_id`, `session_id` | removidos — identidade gerida pelo `IntakeSessionRepository` |
| `resposta_ao_cliente` | removido — texto composto pelo `IntakeEngine` (determinístico) |
| `proxima_pergunta` (texto) | `suggested_next_question_key` (apenas a chave) |
| `perguntas_feitas` | removido — `question_count` é coluna da sessão (backend) |
| `intent`: `lead_juridico`/`duvida_geral`/`cliente_existente`/`spam`/`outro` | `new_intake`/`office_information`/`existing_case`/`non_legal`/`human_request`/`document_submission`/`unknown` |
| `urgencia`: `baixa`/`media`/`alta`/`critica` | `urgency_level`: `normal`/`high`/`critical` |
| `urgencia_motivo` (string\|null) | `urgency_reasons` (array[string]) |
| `area_principal` / `area_secundaria` | `primary_practice_area` / `secondary_practice_areas` (array) |
| `encaminhar_humano` / `motivo_encaminhamento` | `enough_for_handoff` + `should_handoff_immediately` (motivo derivado no backend) |
| `encerrar` | `should_stop_bot` (sinal; terminal decidido no backend) |
| `dados_extraidos.resumo_caso` | `extracted_data.main_report` |
| `dados_extraidos.parte_contraria` | (sem equivalente direto) |
| `dados_extraidos.prazo_mencionado` | `extracted_data.has_deadline` + `deadline_description` |
| `dados_extraidos.documentos_citados` | `extracted_data.mentioned_documents` |
| (novos) | `classification_confidence`, `answer_relevant_to_current_question`, `risk_flags`, `missing_essential_fields`, `summary`, e os campos `state`, `case_number`, `has_*`, `hearing_*`, `has_official_notice` |

## Por que sem campo de raciocínio

Campos de "pensamento"/CoT aumentam tokens, custo e risco de vazamento, sem melhorar a saída
estruturada. O backend só precisa de classificação, extração, urgência, risco, sinais de handoff e
resumo; o **texto** ao cliente e o **estado** são do backend. Manter o schema enxuto e estável é o
que permite testar de forma determinística (mesmo schema em produção e nos evals).
