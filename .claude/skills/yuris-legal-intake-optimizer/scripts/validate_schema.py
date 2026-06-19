#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
validate_schema.py — valida o Structured Output (JSON) do agente de pre-atendimento
contra o schema canonico. FONTE DE VERDADE do schema (producao == testes).

Stdlib apenas. Uso:
  python validate_schema.py <instancia.json>         # valida 1 objeto ou lista de objetos
  python validate_schema.py --print-schema           # imprime o schema canonico (JSON)
  python validate_schema.py --compare <schema.json>  # confere se schema externo == canonico

Codigo de saida: 0 = tudo valido; 1 = invalido ou erro de uso.

Outros scripts importam: from validate_schema import CANONICAL_SCHEMA, validate_instance, check_coherence
"""
import json
import sys

# --- Schema canonico (espelha references/structured-output-schema.md) -----------------
CANONICAL_SCHEMA = {
    "type": "object",
    "additionalProperties": False,
    "required": [
        "channel_id", "session_id", "conversation_state", "intent", "area_principal",
        "area_secundaria", "urgencia", "urgencia_motivo", "dados_extraidos",
        "proxima_pergunta", "perguntas_feitas", "encaminhar_humano",
        "motivo_encaminhamento", "resposta_ao_cliente", "encerrar",
    ],
    "properties": {
        "channel_id": {"type": "integer", "minimum": 1},
        "session_id": {"type": "string", "minLength": 1, "maxLength": 128},
        "conversation_state": {"type": "string", "enum": [
            "greeting", "collecting", "clarifying", "triage", "awaiting_human",
            "completed", "out_of_scope"]},
        "intent": {"type": "string", "enum": [
            "lead_juridico", "duvida_geral", "cliente_existente", "spam", "outro"]},
        "area_principal": {"type": ["string", "null"], "maxLength": 80},
        "area_secundaria": {"type": ["string", "null"], "maxLength": 80},
        "urgencia": {"type": "string", "enum": ["baixa", "media", "alta", "critica"]},
        "urgencia_motivo": {"type": ["string", "null"], "maxLength": 200},
        "dados_extraidos": {
            "type": "object",
            "additionalProperties": False,
            "required": ["nome", "resumo_caso", "parte_contraria", "prazo_mencionado",
                         "documentos_citados"],
            "properties": {
                "nome": {"type": ["string", "null"], "maxLength": 120},
                "resumo_caso": {"type": ["string", "null"], "maxLength": 1000},
                "parte_contraria": {"type": ["string", "null"], "maxLength": 200},
                "prazo_mencionado": {"type": ["string", "null"], "maxLength": 200},
                "documentos_citados": {"type": "array",
                                       "items": {"type": "string", "maxLength": 80},
                                       "maxItems": 20},
            },
        },
        "proxima_pergunta": {"type": ["string", "null"], "maxLength": 400},
        "perguntas_feitas": {"type": "integer", "minimum": 0, "maximum": 20},
        "encaminhar_humano": {"type": "boolean"},
        "motivo_encaminhamento": {"type": ["string", "null"], "maxLength": 200},
        "resposta_ao_cliente": {"type": "string", "minLength": 1, "maxLength": 1500},
        "encerrar": {"type": "boolean"},
    },
}

_TYPE_MAP = {
    "object": dict, "array": list, "string": str,
    "integer": int, "number": (int, float), "boolean": bool, "null": type(None),
}


def _type_ok(value, type_spec):
    types = type_spec if isinstance(type_spec, list) else [type_spec]
    for t in types:
        py = _TYPE_MAP.get(t)
        if py is None:
            continue
        # bool e subclasse de int em Python; tratar separado
        if t == "integer" and isinstance(value, bool):
            continue
        if t == "number" and isinstance(value, bool):
            continue
        if isinstance(value, py):
            return True
    return False


def validate_instance(obj, schema, path="$"):
    """Validador minimo de JSON-Schema (subset usado pelo agente). Retorna lista de erros."""
    errors = []
    type_spec = schema.get("type")
    if type_spec and not _type_ok(obj, type_spec):
        errors.append("%s: tipo invalido (esperado %s, veio %s)" % (
            path, type_spec, type(obj).__name__))
        return errors  # sem tipo certo, nao adianta checar o resto

    if isinstance(obj, dict):
        props = schema.get("properties", {})
        for req in schema.get("required", []):
            if req not in obj:
                errors.append("%s: campo obrigatorio ausente '%s'" % (path, req))
        if schema.get("additionalProperties", True) is False:
            for k in obj:
                if k not in props:
                    errors.append("%s: propriedade nao permitida '%s'" % (path, k))
        for k, sub in props.items():
            if k in obj:
                errors += validate_instance(obj[k], sub, "%s.%s" % (path, k))

    elif isinstance(obj, list):
        item_schema = schema.get("items")
        if "maxItems" in schema and len(obj) > schema["maxItems"]:
            errors.append("%s: array com %d itens (max %d)" % (path, len(obj), schema["maxItems"]))
        if "minItems" in schema and len(obj) < schema["minItems"]:
            errors.append("%s: array com %d itens (min %d)" % (path, len(obj), schema["minItems"]))
        if item_schema:
            for i, item in enumerate(obj):
                errors += validate_instance(item, item_schema, "%s[%d]" % (path, i))

    elif isinstance(obj, str):
        if "maxLength" in schema and len(obj) > schema["maxLength"]:
            errors.append("%s: string com %d chars (max %d)" % (path, len(obj), schema["maxLength"]))
        if "minLength" in schema and len(obj) < schema["minLength"]:
            errors.append("%s: string com %d chars (min %d)" % (path, len(obj), schema["minLength"]))
        if "enum" in schema and obj not in schema["enum"]:
            errors.append("%s: valor '%s' fora do enum %s" % (path, obj, schema["enum"]))

    elif isinstance(obj, (int, float)) and not isinstance(obj, bool):
        if "minimum" in schema and obj < schema["minimum"]:
            errors.append("%s: %s < minimo %s" % (path, obj, schema["minimum"]))
        if "maximum" in schema and obj > schema["maximum"]:
            errors.append("%s: %s > maximo %s" % (path, obj, schema["maximum"]))

    return errors


def check_coherence(obj):
    """Invariantes de coerencia entre campos (alem do schema). Retorna lista de erros."""
    errors = []
    if not isinstance(obj, dict):
        return ["raiz nao e objeto"]
    g = obj.get
    if g("encaminhar_humano") is True:
        if not g("motivo_encaminhamento"):
            errors.append("coerencia: encaminhar_humano=true exige motivo_encaminhamento")
        if g("conversation_state") not in ("awaiting_human", "triage", "out_of_scope"):
            errors.append("coerencia: encaminhar_humano=true exige conversation_state em "
                          "{awaiting_human,triage,out_of_scope}")
    if g("urgencia") in ("alta", "critica") and not g("urgencia_motivo"):
        errors.append("coerencia: urgencia alta/critica exige urgencia_motivo")
    if g("encerrar") is True and g("proxima_pergunta") is not None:
        errors.append("coerencia: encerrar=true exige proxima_pergunta=null")
    pf = g("perguntas_feitas")
    if isinstance(pf, int) and pf < 0:
        errors.append("coerencia: perguntas_feitas negativo")
    return errors


def _validate_one(obj, label):
    errs = validate_instance(obj, CANONICAL_SCHEMA)
    errs += check_coherence(obj)
    if errs:
        print("  [FALHOU] %s" % label)
        for e in errs:
            print("     - %s" % e)
    else:
        print("  [OK] %s" % label)
    return len(errs) == 0


def main(argv):
    if "--print-schema" in argv:
        print(json.dumps(CANONICAL_SCHEMA, ensure_ascii=False, indent=2))
        return 0

    if "--compare" in argv:
        i = argv.index("--compare")
        if i + 1 >= len(argv):
            print("uso: --compare <schema.json>")
            return 1
        with open(argv[i + 1], "r", encoding="utf-8") as f:
            other = json.load(f)
        same = json.dumps(other, sort_keys=True) == json.dumps(CANONICAL_SCHEMA, sort_keys=True)
        print("schema externo == canonico: %s" % ("SIM" if same else "NAO"))
        return 0 if same else 1

    files = [a for a in argv if not a.startswith("--")]
    if not files:
        print(__doc__)
        return 1

    all_ok = True
    for fp in files:
        print("== %s ==" % fp)
        try:
            with open(fp, "r", encoding="utf-8") as f:
                data = json.load(f)
        except Exception as e:
            print("  [ERRO] nao consegui ler/parsear: %s" % e)
            all_ok = False
            continue
        items = data if isinstance(data, list) else [data]
        for idx, obj in enumerate(items):
            ok = _validate_one(obj, "instancia[%d]" % idx if isinstance(data, list) else "instancia")
            all_ok = all_ok and ok

    print("\nRESULTADO: %s" % ("APROVADO" if all_ok else "REPROVADO"))
    return 0 if all_ok else 1


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
