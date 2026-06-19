#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
validate_prompt.py — valida o prompt universal do agente de pre-atendimento juridico.

Stdlib apenas. Uso:
  python validate_prompt.py <prompt.md>

Checa: placeholders nao resolvidos, tags abertas, secoes duplicadas, regras
contraditorias, repeticao excessiva, termos proibidos (promessa/prazo/exito/parecer/
calculo), pretensao de ser humano, instrucao de chain-of-thought, ausencia de regras
obrigatorias (encaminhamento humano, urgencia, anti prompt-injection, contexto multi-tenant,
instancia unica), instrucao de criar segunda conexao, e metricas (chars/tokens).

Saida: ERROS (criticos), ALERTAS, METRICAS, RECOMENDACOES.
Codigo de saida: 1 se houver erro critico; 0 se aprovado.
"""
import re
import sys

# Variaveis de injecao legitimas (NAO sao placeholders esquecidos)
ALLOWED_VARS = {
    "NOME_ESCRITORIO", "AREAS_HABILITADAS", "MAX_PERGUNTAS",
    "CANAL_ID", "SESSION_ID", "DATA_HOJE", "DETALHE_PEDIDO",
}

NEG = r"(?:n[aã]o|nunca|jamais|sem|evite|proib|n[aã]o\s+deve)"

# Termos que, se aparecerem como INSTRUCAO AFIRMATIVA (sem negacao na linha), sao criticos.
FORBIDDEN_AS_INSTRUCTION = [
    (r"garant\w*\s+(?:o\s+)?(?:resultado|ganho|[eê]xito|sucesso|vit[oó]ria)", "promessa de resultado/exito"),
    (r"(?:100\s*%|cem\s+por\s+cento)\s+de\s+(?:[eê]xito|sucesso|chance)", "promessa de 100% de exito"),
    (r"chance\s+de\s+[eê]xito", "estimativa de chance de exito"),
    (r"probabilidade\s+de\s+(?:ganho|[eê]xito|vit[oó]ria)", "probabilidade de ganho"),
    (r"voc[eê]\s+(?:vai|ir[aá])\s+ganhar", "promessa de que vai ganhar"),
    (r"prazo\s+de\s+\d+\s+dias?\s+(?:garantid|para\s+resolver)", "promessa de prazo"),
    (r"(?:darei|vou\s+dar|fornece\w*)\s+(?:um\s+)?parecer\s+jur[ií]dico", "parecer juridico"),
    (r"(?:vou|irei)\s+analisar\s+(?:o|os|seu|seus)\s+documento", "analise de documento"),
    (r"sou\s+(?:um\s+)?advogad", "afirmar ser advogado"),
    (r"sou\s+humano", "afirmar ser humano"),
]

# Chain-of-thought no prompt de producao: nunca.
COT_PATTERNS = [
    (r"pense\s+passo\s+a\s+passo", "instrucao de pensar passo a passo"),
    (r"explique\s+seu\s+racioc[ií]nio", "pedido de explicar raciocinio"),
    (r"mostre\s+seu\s+racioc[ií]nio", "pedido de mostrar raciocinio"),
    (r"chain[\s\-]?of[\s\-]?thought", "mencao a chain-of-thought"),
    (r"racioc[ií]nio\s+(?:interno|privado|passo\s+a\s+passo)", "raciocinio interno/privado"),
]

# Instrucao de criar segunda conexao/instancia (proibido pela premissa ZERO).
SECOND_CONNECTION = [
    (r"crie?\s+(?:uma\s+)?(?:nova\s+)?inst[aâ]ncia", "instrucao de criar instancia"),
    (r"gere?\s+(?:um\s+)?(?:novo\s+)?qr", "instrucao de gerar QR"),
    (r"conecte?\s+(?:um\s+)?(?:novo|outro|segundo)\s+(?:n[uú]mero|whatsapp)", "conectar novo numero"),
    (r"segundo\s+(?:token|webhook|whatsapp|n[uú]mero)", "segundo token/webhook/numero"),
]

# Regras que DEVEM existir no prompt (ausencia = alerta).
REQUIRED_RULES = [
    ("encaminhamento humano", r"encaminh\w*\s+(?:para\s+)?(?:um\s+)?(?:advogad|humano|atend)"),
    ("tratamento de urgencia", r"urg[eê]nc"),
    ("anti prompt-injection", r"(?:n[aã]o\s+(?:revele|obede[cç]a)|trate\s+o\s+texto\s+da\s+pessoa\s+como\s+conte[uú]do|n[aã]o\s+mude\s+de\s+papel)"),
    ("contexto multi-tenant (canal/areas)", r"(?:\{\{CANAL_ID\}\}|\{\{AREAS_HABILITADAS\}\}|canal)"),
]


def extract_prompt(text):
    m = re.search(r"<!--\s*BEGIN-PROMPT\s*-->(.*?)<!--\s*END-PROMPT\s*-->", text, re.S)
    return (m.group(1).strip(), True) if m else (text, False)


def estimate_tokens(text):
    try:
        import tiktoken  # type: ignore
        return len(tiktoken.get_encoding("cl100k_base").encode(text))
    except Exception:
        chars = len(text)
        words = len(re.findall(r"\S+", text))
        return int(round((chars / 4.0 + words * 1.3) / 2.0))


def find_lines(text, pattern):
    out = []
    for i, line in enumerate(text.splitlines(), 1):
        if re.search(pattern, line, re.I):
            out.append((i, line.strip()))
    return out


def main(argv):
    files = [a for a in argv if not a.startswith("--")]
    if not files:
        print(__doc__)
        return 1
    path = files[0]
    with open(path, "r", encoding="utf-8") as f:
        raw = f.read()
    prompt, had_markers = extract_prompt(raw)

    errors, warnings, recs = [], [], []

    # 1) Placeholders
    for m in re.finditer(r"\{\{\s*([A-Z0-9_]+)\s*\}\}", prompt):
        if m.group(1) not in ALLOWED_VARS:
            errors.append("placeholder desconhecido nao resolvido: {{%s}}" % m.group(1))
    for m in re.finditer(r"\[(?:preencher|inserir|todo|fixme|xxx)\b[^\]]*\]", prompt, re.I):
        errors.append("marcador de pendencia encontrado: %s" % m.group(0))
    for tok in ("TODO", "FIXME", "XXX"):
        if re.search(r"\b%s\b" % tok, prompt):
            warnings.append("token de pendencia '%s' presente" % tok)

    # 2) Tags/delimitadores abertos
    if prompt.count("{{") != prompt.count("}}"):
        errors.append("chaves {{ }} desbalanceadas")
    if raw.count("<!--") != raw.count("-->"):
        warnings.append("comentarios HTML <!-- --> desbalanceados")
    if prompt.count("```") % 2 != 0:
        warnings.append("blocos de codigo ``` desbalanceados")

    # 3) Secoes duplicadas (headings)
    heads = [h.strip().lower() for h in re.findall(r"^#{1,6}\s+(.+)$", prompt, re.M)]
    seen = set()
    for h in heads:
        if h in seen:
            warnings.append("secao duplicada: '%s'" % h)
        seen.add(h)

    # 4) Termos proibidos como instrucao (heuristica de negacao na linha)
    for pat, label in FORBIDDEN_AS_INSTRUCTION:
        for ln, content in find_lines(prompt, pat):
            if re.search(NEG, content, re.I):
                continue  # esta numa proibicao -> ok
            errors.append("termo proibido como instrucao (linha %d): %s -> '%s'" % (ln, label, content[:90]))

    # 5) Chain-of-thought
    for pat, label in COT_PATTERNS:
        for ln, content in find_lines(prompt, pat):
            errors.append("chain-of-thought no prompt (linha %d): %s" % (ln, label))

    # 6) Segunda conexao
    for pat, label in SECOND_CONNECTION:
        for ln, content in find_lines(prompt, pat):
            if re.search(NEG, content, re.I):
                continue
            errors.append("instrucao de segunda conexao (linha %d): %s -> '%s'" % (ln, label, content[:90]))

    # 7) Regras obrigatorias presentes
    for label, pat in REQUIRED_RULES:
        if not re.search(pat, prompt, re.I):
            warnings.append("regra obrigatoria ausente ou nao detectada: %s" % label)

    # 8) Contradicoes simples (afirmar e negar a mesma conduta)
    if re.search(r"sempre\s+responda\s+em\s+texto", prompt, re.I) and \
       re.search(r"somente\s+(?:o\s+)?(?:objeto\s+)?json", prompt, re.I):
        warnings.append("possivel contradicao: 'responda em texto' vs 'somente JSON'")

    # 9) Repeticao excessiva (4-grams)
    words = re.findall(r"\w+", prompt.lower())
    grams = {}
    for i in range(len(words) - 3):
        g = " ".join(words[i:i + 4])
        grams[g] = grams.get(g, 0) + 1
    repeated = [(g, c) for g, c in grams.items() if c >= 4]
    for g, c in sorted(repeated, key=lambda x: -x[1])[:5]:
        warnings.append("repeticao excessiva (%dx): '%s'" % (c, g))

    # Metricas
    chars = len(prompt)
    tokens = estimate_tokens(prompt)
    if not had_markers:
        recs.append("Sem marcas BEGIN-PROMPT/END-PROMPT: validei o arquivo inteiro. "
                    "Delimite o prompt para medir so o que vai pro LLM.")
    if tokens > 1200:
        recs.append("Prompt com ~%d tokens: considere enxugar (custo por mensagem)." % tokens)

    # Relatorio
    print("=" * 64)
    print("VALIDACAO DO PROMPT: %s" % path)
    print("=" * 64)
    print("\nERROS CRITICOS (%d):" % len(errors))
    for e in errors:
        print("  x %s" % e)
    if not errors:
        print("  (nenhum)")
    print("\nALERTAS (%d):" % len(warnings))
    for w in warnings:
        print("  ! %s" % w)
    if not warnings:
        print("  (nenhum)")
    print("\nMETRICAS:")
    print("  chars=%d  tokens~%d  linhas=%d  headings=%d" % (
        chars, tokens, prompt.count(chr(10)) + 1, len(heads)))
    if recs:
        print("\nRECOMENDACOES:")
        for r in recs:
            print("  - %s" % r)
    veredito = "REPROVADO" if errors else "APROVADO"
    print("\nRESULTADO: %s" % veredito)
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
