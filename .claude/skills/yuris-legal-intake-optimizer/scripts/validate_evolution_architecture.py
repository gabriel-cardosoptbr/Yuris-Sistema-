#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
validate_evolution_architecture.py — scanner heuristico que protege a PREMISSA ZERO:
uma unica instancia da Evolution; o bot nao cria conexao/credencial/QR/webhook proprios.

Stdlib apenas. Uso:
  python validate_evolution_architecture.py [--root <path>] [arquivo ...]

Sem argumentos, escaneia o diretorio atual. Procura anti-padroes e classifica em
CRITICO (sai com codigo != 0) e ALERTA (sai 0, mas exige revisao humana).

Limitacoes: e heuristica de texto. Casos como "dois agentes na mesma conversa" e
"bypass cross-tenant" nem sempre sao detectaveis estaticamente; sao listados como
verificacoes MANUAIS no final.
"""
import os
import re
import sys

SCAN_EXT = (".php", ".js", ".sql", ".py")
# Arquivos onde criar instancia / mexer em credencial Evolution e ESPERADO (modulo WhatsApp).
ALLOWED_PROVISION = ("evolutionapiservice.php", "whatsapp_config.php",
                     "whatsappprovisioningservice.php")
# Telas/ं endpoints do AGENTE: nao podem expor credencial/QR.
AGENT_SCREEN = ("agente.php", "agent_settings.php", "agent.js", "agent_instances.php")

FORBIDDEN_TABLES = re.compile(
    r"\b(bot_whatsapp_instances|ai_whatsapp_instances|agent_evolution_instances|"
    r"bot_evolution_credentials|bot_whatsapp_connections)\b", re.I)

CREATE_INSTANCE = re.compile(r"(createInstance\s*\(|instance/create|\bcreate\s+instance\b)", re.I)
EVO_CREDENTIAL = re.compile(r"(evolution_api_key|evolution_base_url|admin[_\s]?key|qr_code_base64|webhook_url)", re.I)
DIRECT_EVO_CALL = re.compile(r"(/message/send|/instance/create|/instance/connect|manager/instance)", re.I)
CHANNEL_ID_FRONT = re.compile(r"\$_(?:GET|POST|REQUEST)\[\s*['\"]channel_id['\"]\s*\]")
AGENT_WEBHOOK_FILE = re.compile(r"agent.*webhook|webhook.*agent", re.I)


def iter_files(targets):
    for t in targets:
        if os.path.isfile(t):
            yield t
        elif os.path.isdir(t):
            for dp, _dn, fn in os.walk(t):
                if any(skip in dp for skip in (os.sep + ".git", "node_modules", os.sep + "vendor")):
                    continue
                for f in fn:
                    if f.lower().endswith(SCAN_EXT):
                        yield os.path.join(dp, f)


def read(path):
    try:
        with open(path, "r", encoding="utf-8", errors="replace") as f:
            return f.read()
    except Exception:
        return ""


def lines_of(text, rx):
    return [(i, ln.strip()) for i, ln in enumerate(text.splitlines(), 1) if rx.search(ln)]


def main(argv):
    targets = [a for a in argv if not a.startswith("--")]
    if "--root" in argv:
        targets.append(argv[argv.index("--root") + 1])
    if not targets:
        targets = ["."]

    criticos, alertas = [], []
    scanned = 0

    for path in iter_files(targets):
        base = os.path.basename(path).lower()
        text = read(path)
        if not text:
            continue
        scanned += 1

        norm_path = path.replace("\\", "/").lower()
        # Contexto do AGENTE: a premissa proibe o BOT criar instancia/usar credencial/QR.
        # O modulo WhatsApp (instances.php, whatsapp_config.php, EvolutionApiService) PODE.
        is_agent = (base in AGENT_SCREEN) or ("agent" in norm_path)

        for ln, content in lines_of(text, FORBIDDEN_TABLES):
            criticos.append((path, ln, "tabela de credencial/instancia propria do bot", content[:100]))

        if is_agent:
            for ln, content in lines_of(text, CREATE_INSTANCE):
                criticos.append((path, ln, "criacao de instancia no fluxo do AGENTE (proibido pela premissa ZERO)", content[:100]))
            for ln, content in lines_of(text, EVO_CREDENTIAL):
                alertas.append((path, ln, "credencial/QR da Evolution referida na tela/endpoint do agente (revisar)", content[:100]))
            for ln, content in lines_of(text, DIRECT_EVO_CALL):
                alertas.append((path, ln, "envio direto a Evolution no fluxo do agente (use EvolutionApiService)", content[:100]))

        if AGENT_WEBHOOK_FILE.search(base):
            alertas.append((path, 0, "arquivo parece um webhook separado do agente (reutilize o webhook unico)", base))

        # channel_id vindo do front sem autorizacao no mesmo arquivo
        if CHANNEL_ID_FRONT.search(text) and not re.search(r"resolveForRequest|::check\(|::assert\(", text):
            for ln, content in lines_of(text, CHANNEL_ID_FRONT):
                alertas.append((path, ln, "channel_id do front sem resolveForRequest/check no arquivo", content[:100]))

        # Checagens de AUSENCIA no webhook unico
        if base == "webhook.php":
            for token, label in (("wamid", "idempotencia por wamid"),
                                 ("fromMe", "checagem de fromMe (anti-loop)"),
                                 ("agent_paused", "pausa por atendimento humano")):
                if token not in text:
                    alertas.append((path, 0, "webhook sem %s" % label, "ausente"))

    # Relatorio
    print("=" * 70)
    print("VALIDACAO DA ARQUITETURA EVOLUTION (instancia unica)")
    print("Arquivos escaneados: %d" % scanned)
    print("=" * 70)
    print("\nCRITICOS (%d):" % len(criticos))
    for p, ln, label, ev in criticos:
        print("  x %s:%s  %s\n       %s" % (p, ln, label, ev))
    if not criticos:
        print("  (nenhum)")
    print("\nALERTAS (%d):" % len(alertas))
    for p, ln, label, ev in alertas:
        print("  ! %s:%s  %s\n       %s" % (p, ln, label, ev))
    if not alertas:
        print("  (nenhum)")

    print("\nVERIFICACOES MANUAIS (nao detectaveis 100%% por texto):")
    print("  - Nunca dois agentes ativos na mesma conversa (UNIQUE em whatsapp_instance_id ajuda).")
    print("  - Filial so acessa canal da matriz com grant ativo + flag ligada.")
    print("  - Token/instancia resolvidos so no backend (resolveForRequest).")
    print("  - Resposta do bot sai pela MESMA instancia (credenciais do dono do canal).")

    print("\nRECOMENDACOES:")
    if criticos:
        print("  - Remover qualquer criacao de instancia/credencial fora do modulo WhatsApp.")
    if alertas:
        print("  - Revisar os alertas: confirmar que credenciais nunca saem em resposta de API,")
        print("    que o agente usa o webhook unico e o EvolutionApiService para enviar.")
    if not criticos and not alertas:
        print("  - Nenhum anti-padrao encontrado nos arquivos escaneados.")

    print("\nRESULTADO: %s" % ("REPROVADO (risco critico)" if criticos else "APROVADO"))
    return 1 if criticos else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
