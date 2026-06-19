# Baseline do prompt universal (preservada)

Snapshot do prompt vigente ANTES de qualquer otimização, para comparação honesta
antes/depois. Arquivo preservado: `system-prompt-baseline.md` (cópia de
`templates/system-prompt-template.md`).

## Métricas da baseline (medidas pelos scripts em 2026-06-19)

| Métrica | Valor |
|---|---|
| chars (prompt estático) | 4333 |
| tokens (~heurística chars/4 + palavras*1.3) | ~958 |
| linhas | 80 |
| headings | 6 (TAREFA, INSTRUÇÕES, DO, DON'T, EXAMPLES, CONTEXT) |
| validate_prompt | APROVADO (0 erros, 0 alertas) |
| Custo estimado gpt-4o-mini | ~US$0,0005 / msg • ~US$2,78 / 1.000 atend. |
| Custo estimado claude-haiku | ~US$0,0028 / msg • ~US$16,50 / 1.000 atend. |

Suposições de custo (editáveis em `scripts/estimate_prompt_tokens.py`): config 120 +
estado 150 + histórico 400 + entrada 60 tokens; saída 350 tokens; 6 interações/atendimento.

## Testes da baseline

- `run_conversation_evals.py --mode local`: 34/34 casos OK (11 conversa + 23 Evolution).
- Schema canônico: carrega e valida todos os golden outputs dos casos.
- Arquitetura Evolution (varredura no repo real): 0 críticos.

## Como comparar antes/depois

1. Antes de editar o prompt, confirme que esta baseline está atualizada (rode os 5 scripts
   e anote os números aqui ou em uma cópia datada).
2. Edite o prompt em `templates/system-prompt-template.md`.
3. Rode de novo os 5 scripts sobre o prompt novo.
4. Aceite a mudança só se ficou **mais claro, determinístico, econômico, seguro, testável e
   compatível com instância única**. Menor não é, sozinho, melhor.

## Pendências conhecidas (não bloqueiam a baseline)

- Contagem de tokens é heurística (tiktoken não instalado). Para custo fino, instalar
  tiktoken no ambiente de dev (a skill não depende dele).
- Modo `api --live` (chamadas reais ao provider) é guardado e será ligado na fase de
  implementação, com chave + limite de chamadas.
