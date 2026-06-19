# Structured Output do agente (JSON canônico)

O agente responde **somente** com este JSON. O mesmo schema vale em produção e nos testes
(`scripts/validate_schema.py` é a fonte de verdade em código; este documento descreve). Sem
texto fora do JSON, sem Chain-of-Thought, `additionalProperties: false`.

## Campos

| Campo | Tipo | Obrigatório | Notas |
|---|---|---|---|
| `channel_id` | integer | sim | Referência ao canal existente. NUNCA credenciais. |
| `session_id` | string | sim | Identificador da sessão de pré-atendimento. |
| `conversation_state` | enum | sim | `greeting`,`collecting`,`clarifying`,`triage`,`awaiting_human`,`completed`,`out_of_scope` |
| `intent` | enum | sim | `lead_juridico`,`duvida_geral`,`cliente_existente`,`spam`,`outro` |
| `area_principal` | string\|null | sim | Área provável (deve estar nas áreas habilitadas do tenant) ou null. |
| `area_secundaria` | string\|null | sim | Segunda área provável ou null. |
| `urgencia` | enum | sim | `baixa`,`media`,`alta`,`critica` |
| `urgencia_motivo` | string\|null | sim | Curto; null se baixa. |
| `dados_extraidos` | object | sim | Ver abaixo. `additionalProperties:false`. |
| `proxima_pergunta` | string\|null | sim | Próxima pergunta ao cliente; null se não há. |
| `perguntas_feitas` | integer | sim | 0..{{MAX_PERGUNTAS}}; controla o limite. |
| `encaminhar_humano` | boolean | sim | true quando deve ir para atendimento humano. |
| `motivo_encaminhamento` | string\|null | sim | Curto; null se não encaminha. |
| `resposta_ao_cliente` | string | sim | Texto que será enviado no WhatsApp (copy Yuris, sem "—"). |
| `encerrar` | boolean | sim | true encerra a sessão automática. |

`dados_extraidos` (objeto, `additionalProperties:false`):

| Campo | Tipo | Notas |
|---|---|---|
| `nome` | string\|null | Nome do contato, se informado. |
| `resumo_caso` | string\|null | Resumo objetivo dos fatos. |
| `parte_contraria` | string\|null | Se mencionada. |
| `prazo_mencionado` | string\|null | Ex.: "audiência amanhã", "intimação com 5 dias". |
| `documentos_citados` | array[string] | Tipos citados; sem analisar conteúdo. |

## Schema (machine-readable)

```json
{
  "type": "object",
  "additionalProperties": false,
  "required": [
    "channel_id","session_id","conversation_state","intent","area_principal",
    "area_secundaria","urgencia","urgencia_motivo","dados_extraidos","proxima_pergunta",
    "perguntas_feitas","encaminhar_humano","motivo_encaminhamento","resposta_ao_cliente",
    "encerrar"
  ],
  "properties": {
    "channel_id": {"type": "integer", "minimum": 1},
    "session_id": {"type": "string", "minLength": 1, "maxLength": 128},
    "conversation_state": {"type": "string", "enum": ["greeting","collecting","clarifying","triage","awaiting_human","completed","out_of_scope"]},
    "intent": {"type": "string", "enum": ["lead_juridico","duvida_geral","cliente_existente","spam","outro"]},
    "area_principal": {"type": ["string","null"], "maxLength": 80},
    "area_secundaria": {"type": ["string","null"], "maxLength": 80},
    "urgencia": {"type": "string", "enum": ["baixa","media","alta","critica"]},
    "urgencia_motivo": {"type": ["string","null"], "maxLength": 200},
    "dados_extraidos": {
      "type": "object",
      "additionalProperties": false,
      "required": ["nome","resumo_caso","parte_contraria","prazo_mencionado","documentos_citados"],
      "properties": {
        "nome": {"type": ["string","null"], "maxLength": 120},
        "resumo_caso": {"type": ["string","null"], "maxLength": 1000},
        "parte_contraria": {"type": ["string","null"], "maxLength": 200},
        "prazo_mencionado": {"type": ["string","null"], "maxLength": 200},
        "documentos_citados": {"type": "array", "items": {"type": "string", "maxLength": 80}, "maxItems": 20}
      }
    },
    "proxima_pergunta": {"type": ["string","null"], "maxLength": 400},
    "perguntas_feitas": {"type": "integer", "minimum": 0, "maximum": 20},
    "encaminhar_humano": {"type": "boolean"},
    "motivo_encaminhamento": {"type": ["string","null"], "maxLength": 200},
    "resposta_ao_cliente": {"type": "string", "minLength": 1, "maxLength": 1500},
    "encerrar": {"type": "boolean"}
  }
}
```

## Invariantes de coerência (checadas além do schema)

- Se `encaminhar_humano = true` → `motivo_encaminhamento` não nulo e
  `conversation_state` ∈ {`awaiting_human`,`triage`,`out_of_scope`}.
- Se `urgencia` ∈ {`alta`,`critica`} → `urgencia_motivo` não nulo.
- Se `encerrar = true` → `proxima_pergunta` é null.
- `perguntas_feitas` nunca passa de `{{MAX_PERGUNTAS}}`; ao atingir o limite sem dados
  suficientes, `encaminhar_humano = true`.
- `area_principal`, quando não nula, pertence a `{{AREAS_HABILITADAS}}`; fora disso,
  `conversation_state = out_of_scope`.
- `channel_id` é o canal resolvido no backend; o modelo apenas o repete, não o escolhe.

## Por que sem campo de raciocínio

Campos de "pensamento"/CoT aumentam tokens, custo e risco de vazamento, sem melhorar a
saída estruturada. O backend só precisa de classificação, extração, urgência, resumo e a
resposta ao cliente. Manter o schema enxuto e estável é o que permite testar de forma
determinística (mesmo schema em produção e nos evals).
