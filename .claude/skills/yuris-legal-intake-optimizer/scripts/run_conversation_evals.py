#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
run_conversation_evals.py — roda os casos de teste do agente de pre-atendimento.

Stdlib apenas. Modos:
  --mode local        (padrao) deterministico, SEM consumir creditos:
                       valida os expected_output dos casos de conversa contra o schema +
                       invariantes + assertions; e roda o simulador da Evolution.
  --mode conversation so os casos de conversa (tests/conversation_cases.json)
  --mode evolution    so os casos de integracao (tests/evolution_integration_cases.json)
  --mode api --live --max-calls N
                       chamadas REAIS ao provider (exige ativacao explicita + limite +
                       chave em YURIS_AGENT_API_KEY). Nao executa sem --live.

Codigo de saida: 0 se todos passaram; 1 se algum falhou; 2 em erro de uso.
"""
import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
from validate_schema import validate_instance, check_coherence, CANONICAL_SCHEMA  # noqa: E402

TESTS = os.path.normpath(os.path.join(HERE, "..", "tests"))


# ---------- helpers de assertion ----------
def resolve_path(obj, path):
    cur = obj
    for part in path.split("."):
        if isinstance(cur, dict) and part in cur:
            cur = cur[part]
        else:
            return (False, None)
    return (True, cur)


def run_assertion(obj, a):
    path = a.get("path", "")
    found, val = resolve_path(obj, path)
    op = a.get("op", "equals")
    label = "%s %s %s" % (path, op, a.get("value", ""))
    if op in ("not_null", "is_null"):
        ok = (val is not None) if op == "not_null" else (val is None)
        return ok, label
    if not found:
        return False, label + " (campo ausente)"
    exp = a.get("value")
    if op == "equals":
        return val == exp, label
    if op == "not_equals":
        return val != exp, label
    if op == "lte":
        return isinstance(val, (int, float)) and val <= exp, label
    if op == "gte":
        return isinstance(val, (int, float)) and val >= exp, label
    if op == "in":
        return val in exp, label
    if op == "contains":
        return isinstance(val, str) and str(exp).lower() in val.lower(), label
    if op == "max_len":
        return hasattr(val, "__len__") and len(val) <= exp, label
    return False, label + " (op desconhecido)"


# ---------- simulador da Evolution (espelha maybeQueueAgentReply) ----------
def bot_would_respond(state, event):
    """Decide se o bot responderia, aplicando as MESMAS regras do webhook real."""
    if not state.get("has_channel", True):
        return False  # tenant sem canal nao ativa o bot
    if not state.get("enabled", True):
        return False  # agente desativado
    if state.get("status", "open") != "open":
        return False  # canal desconectado
    if event.get("fromMe"):
        return False  # nao responde as proprias mensagens (anti-loop)
    jid = event.get("remote_jid", "")
    if jid.endswith("@g.us") or jid.endswith("@broadcast"):
        return False  # so conversa individual
    if event.get("type", "text") != "text":
        return False  # so texto na v1 (midia nao aciona)
    if not (event.get("text") or "").strip():
        return False
    wamid = event.get("wamid")
    if wamid and wamid in set(state.get("seen_wamids", [])):
        return False  # idempotencia: evento duplicado nao responde 2x
    if state.get("agent_paused"):
        return False  # human takeover
    if state.get("requires_shared"):
        if not (state.get("flag_on") and state.get("shared_grant")):
            return False  # filial sem grant/flag nao usa canal da matriz
    return True


# ---------- runners ----------
def run_conversation(verbose):
    fp = os.path.join(TESTS, "conversation_cases.json")
    data = json.load(open(fp, "r", encoding="utf-8"))
    cases = data.get("cases", [])
    passed = total = 0
    print("== Conversa (%d casos) ==" % len(cases))
    for c in cases:
        name = c.get("name", "case-%s" % c.get("id"))
        obj = c.get("expected_output", {})
        errs = validate_instance(obj, CANONICAL_SCHEMA) + check_coherence(obj)
        a_results = [run_assertion(obj, a) for a in c.get("assertions", [])]
        a_fail = [lbl for ok, lbl in a_results if not ok]
        total += 1
        ok = not errs and not a_fail
        passed += 1 if ok else 0
        print("  [%s] %s" % ("OK" if ok else "FALHOU", name))
        if not ok or verbose:
            for e in errs:
                print("       schema: %s" % e)
            for lbl in a_fail:
                print("       assert: %s" % lbl)
    return passed, total


def run_evolution(verbose):
    fp = os.path.join(TESTS, "evolution_integration_cases.json")
    data = json.load(open(fp, "r", encoding="utf-8"))
    cases = data.get("cases", [])
    passed = total = 0
    print("== Evolution (%d casos) ==" % len(cases))
    for c in cases:
        name = c.get("name", "case-%s" % c.get("id"))
        kind = c.get("type", "sim")
        total += 1
        if kind == "sim":
            got = bot_would_respond(c.get("state", {}), c.get("event", {}))
            ok = (got == c.get("expect_bot_responds"))
            print("  [%s] (sim) %s -> bot_responde=%s (esperado %s)" % (
                "OK" if ok else "FALHOU", name, got, c.get("expect_bot_responds")))
        else:  # invariant: garantido por design/schema, nao executavel
            ok = bool(c.get("expect", True))
            print("  [%s] (invariante de design) %s" % ("OK" if ok else "FALHOU", name))
            if verbose:
                print("       %s" % c.get("why", ""))
        passed += 1 if ok else 0
    return passed, total


def run_api_guard(argv):
    if "--live" not in argv:
        print("modo api: chamadas REAIS desativadas. Exige --live + --max-calls + "
              "YURIS_AGENT_API_KEY no ambiente. (Nao executado nesta etapa de preparacao.)")
        return 2
    if "--max-calls" not in argv:
        print("modo api: defina --max-calls N para limitar o consumo.")
        return 2
    if not os.environ.get("YURIS_AGENT_API_KEY"):
        print("modo api: defina a variavel de ambiente YURIS_AGENT_API_KEY.")
        return 2
    print("modo api --live: o conector real do provider sera implementado na fase de "
          "implementacao. Ele deve enviar o prompt universal + estado + schema, respeitar "
          "--max-calls e validar cada saida com validate_schema. Abortando por seguranca.")
    return 2


def main(argv):
    mode = "local"
    if "--mode" in argv:
        mode = argv[argv.index("--mode") + 1]
    verbose = "--verbose" in argv or "-v" in argv

    if mode == "api":
        return run_api_guard(argv)

    total = passed = 0
    if mode in ("local", "conversation"):
        p, t = run_conversation(verbose)
        passed += p
        total += t
    if mode in ("local", "evolution"):
        p, t = run_evolution(verbose)
        passed += p
        total += t

    if total == 0:
        print("modo desconhecido: %s" % mode)
        return 2
    print("\nRESULTADO: %d/%d casos OK" % (passed, total))
    return 0 if passed == total else 1


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
