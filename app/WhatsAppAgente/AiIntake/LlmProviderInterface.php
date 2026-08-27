<?php
namespace App\WhatsAppAgente\AiIntake;

/**
 * Contrato de um provedor de LLM para o pre-atendimento. UMA chamada por mensagem.
 *
 * complete() deve devolver SEMPRE um array no formato:
 *   [
 *     'ok'         => bool,        // houve resposta utilizavel (data preenchido)
 *     'refused'    => bool,        // o modelo recusou (safety) — tratar antes de parsear
 *     'reason'     => ?string,     // motivo da recusa (se refused)
 *     'data'       => ?array,      // objeto estruturado (ja decodificado) conforme IntakeSchema
 *     'usage'      => ['input_tokens'=>int,'output_tokens'=>int,'total_tokens'=>int],
 *     'latency_ms' => int,
 *     'error'      => ?string,     // mensagem tecnica (logada server-side, nunca ao cliente)
 *     'model'      => string,
 *   ]
 *
 * O provider NUNCA deve lancar excecao para fora; falhas viram ['ok'=>false,'error'=>...].
 */
interface LlmProviderInterface
{
    public function name(): string;

    public function complete(
        string $model,
        string $systemPrompt,
        string $userMessage,
        array $responseFormat,
        array $opts = []
    ): array;
}
