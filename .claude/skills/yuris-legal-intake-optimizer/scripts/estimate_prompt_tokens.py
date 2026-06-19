#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
estimate_prompt_tokens.py — estima tokens e CUSTO do agente de pre-atendimento.

Stdlib apenas (usa tiktoken se estiver instalado, senao heuristica). Uso:
  python estimate_prompt_tokens.py <prompt.md> [--out-tokens 350] [--turns 6]

ATENCAO: todos os valores de preco sao ESTIMATIVAS e mudam com o tempo. Ajuste em PRICES.
Nao gravamos preco na logica principal; fica nesta config editavel.
"""
import math
import os
import re
import sys

# --- Config editavel (ESTIMATIVAS; USD por 1.000.000 de tokens) ----------------------
PRICES = {
    # provider/modelo: (preco_input_por_milhao, preco_output_por_milhao)  -- ESTIMADO
    "gpt-4o-mini":      (0.15, 0.60),
    "gpt-4o":          (2.50, 10.00),
    "claude-haiku":     (0.80, 4.00),
    "claude-sonnet":    (3.00, 15.00),
}

# Suposicoes de runtime (tokens por atendimento), editaveis por argumento.
ASSUMPTIONS = {
    "config_tenant_tokens": 120,   # nome escritorio, areas, limites injetados
    "session_state_tokens": 150,   # estado resumido da sessao
    "history_tokens": 400,         # historico resumido por interacao
    "user_input_tokens": 60,       # mensagem tipica do cliente
    "output_tokens": 350,          # saida JSON tipica
    "turns_per_attendance": 6,     # interacoes por atendimento
}


def estimate_tokens(text):
    """Tenta tiktoken; senao heuristica robusta (mistura chars/4 e palavras*1.3)."""
    try:
        import tiktoken  # type: ignore
        enc = tiktoken.get_encoding("cl100k_base")
        return len(enc.encode(text)), "tiktoken(cl100k_base)"
    except Exception:
        chars = len(text)
        words = len(re.findall(r"\S+", text))
        approx = int(round((chars / 4.0 + words * 1.3) / 2.0))
        return approx, "heuristica(chars/4 + palavras*1.3)"


def extract_prompt(text):
    m = re.search(r"<!--\s*BEGIN-PROMPT\s*-->(.*?)<!--\s*END-PROMPT\s*-->", text, re.S)
    return m.group(1).strip() if m else text


def fmt_usd(v):
    return "$%.4f" % v if v < 1 else "$%.2f" % v


def main(argv):
    files = [a for a in argv if not a.startswith("--")]
    if not files:
        print(__doc__)
        return 1
    path = files[0]
    out_tokens = ASSUMPTIONS["output_tokens"]
    turns = ASSUMPTIONS["turns_per_attendance"]
    if "--out-tokens" in argv:
        out_tokens = int(argv[argv.index("--out-tokens") + 1])
    if "--turns" in argv:
        turns = int(argv[argv.index("--turns") + 1])

    with open(path, "r", encoding="utf-8") as f:
        raw = f.read()
    prompt = extract_prompt(raw)
    static_tokens, method = estimate_tokens(prompt)
    chars = len(prompt)

    cfg = ASSUMPTIONS["config_tenant_tokens"]
    state = ASSUMPTIONS["session_state_tokens"]
    hist = ASSUMPTIONS["history_tokens"]
    uin = ASSUMPTIONS["user_input_tokens"]

    # Tokens de INPUT por mensagem: prompt estatico + config + estado + historico + entrada
    input_per_msg = static_tokens + cfg + state + hist + uin
    output_per_msg = out_tokens

    print("=" * 64)
    print("ESTIMATIVA DE TOKENS E CUSTO (valores APROXIMADOS)")
    print("Arquivo: %s" % path)
    print("Metodo de contagem: %s" % method)
    print("=" * 64)
    print("Prompt estatico:        %6d chars  ~ %5d tokens" % (chars, static_tokens))
    print("Config do tenant:                      ~ %5d tokens" % cfg)
    print("Estado da sessao:                      ~ %5d tokens" % state)
    print("Historico resumido:                    ~ %5d tokens" % hist)
    print("Entrada por interacao:                 ~ %5d tokens" % uin)
    print("Saida maxima por interacao:            ~ %5d tokens" % output_per_msg)
    print("-" * 64)
    print("INPUT por mensagem:                    ~ %5d tokens" % input_per_msg)
    print("Interacoes por atendimento:            ~ %5d" % turns)
    print("=" * 64)

    header = "%-16s %12s %12s %12s %12s" % ("modelo", "por msg", "por atend.", "1.000 at.", "10.000 at.")
    print(header)
    print("-" * len(header))
    for model, (pin, pout) in PRICES.items():
        cost_msg = input_per_msg / 1e6 * pin + output_per_msg / 1e6 * pout
        cost_att = cost_msg * turns
        print("%-16s %12s %12s %12s %12s" % (
            model, fmt_usd(cost_msg), fmt_usd(cost_att),
            fmt_usd(cost_att * 1000), fmt_usd(cost_att * 10000)))

    print("\nObs.: estimativa. Precos em PRICES (editar conforme a tabela vigente do provider).")
    print("      'por atend.' assume %d interacoes; ajuste com --turns / --out-tokens." % turns)
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
