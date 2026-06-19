<?php
/**
 * Seed do prompt universal v3 (OTIMIZADO) do agente de pre-atendimento juridico.
 *
 * Versao deduplicada do manual: mesma cobertura de salvaguardas que o candidato
 * extenso, porem ~58% menor (~3.957 tokens). Cortes seguros: catalogo de palavras-chave
 * por area (vive em Taxonomy/ai_area_catalog + {{enabled_areas}}), mecanica de formatacao
 * JSON (garantida pelo Structured Outputs strict) e casos deterministicos (duplicata,
 * mensagem vazia, silencio, idempotencia) tratados no IntakeEngine.
 *
 * Fonte unica de verdade do texto: docs/prompt_v3_otimizado.md (validado por
 * validate_prompt.py: APROVADO, 0 erros; verificacao adversarial: 0 criticos).
 * Placeholders compativeis com IntakeEngine::buildSystemPrompt (10 chaves {{...}}).
 */
$tpl = file_get_contents(__DIR__ . '/../../docs/prompt_v3_otimizado.md');
if ($tpl === false || trim($tpl) === '') {
    throw new \RuntimeException('seed v3: nao consegui ler docs/prompt_v3_otimizado.md');
}

return [
    'name'      => 'pre_atendimento_universal',
    'version'   => 'v3',
    'template'  => rtrim($tpl) . "\n",
    'changelog' => 'v3 otimizado: manual deduplicado (152 secoes -> 17), ~3.957 tokens (~58% menor que o candidato), mesma cobertura de salvaguardas. Cortes: catalogo de areas (Taxonomy/ai_area_catalog), mecanica de JSON (Structured Outputs strict), casos deterministicos (IntakeEngine). Patches da verificacao adversarial: anti-pressao comercial/nao fabricar urgencia, negativa generica anti-enumeracao + identidade ancorada no dono do canal, coerencia urgencia-motivo e encerrar sem proxima pergunta. Urgencia normal/high/critical conforme IntakeSchema.',
];
