<?php
namespace App\Services\AiIntake;

require_once __DIR__ . '/LlmProviderInterface.php';

/**
 * OpenAiProvider — Chat Completions com Structured Outputs (response_format json_schema
 * strict). Modelo economico default gpt-4o-mini. Uma chamada por mensagem.
 *
 * Boas praticas confirmadas na doc oficial (2026):
 *  - strict:true garante adesao ao schema; checar message.refusal ANTES de parsear content.
 *  - temperatura 0 (deterministico), max_tokens com folga, timeout curto.
 *  - retry com backoff exponencial + jitter em 429/5xx.
 *  - prefixo estavel (system+schema) primeiro -> prompt caching automatico (>=1024 tokens).
 * A chave NUNCA aparece em log/erro ao cliente.
 */
final class OpenAiProvider implements LlmProviderInterface
{
    private string $apiKey;
    private string $base;

    public function __construct(string $apiKey, string $base = 'https://api.openai.com/v1')
    {
        $this->apiKey = $apiKey;
        $this->base   = rtrim($base, '/');
    }

    public function name(): string { return 'openai'; }

    public function complete(string $model, string $systemPrompt, string $userMessage, array $responseFormat, array $opts = []): array
    {
        $model       = $model ?: 'gpt-4o-mini';
        $temperature = $opts['temperature'] ?? 0;
        $maxTokens   = (int)($opts['max_tokens'] ?? 800);
        $timeout     = (int)($opts['timeout'] ?? 45);
        $maxRetries  = (int)($opts['retries'] ?? 3);

        $payload = [
            'model'       => $model,
            'temperature' => $temperature,
            'max_tokens'  => $maxTokens,
            'messages'    => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userMessage],
            ],
            'response_format' => $responseFormat,
        ];

        $attempt = 0;
        $started = microtime(true);
        while (true) {
            $res = $this->post('/chat/completions', $payload, $timeout);
            $status = $res['status'];

            if (($status === 429 || $status >= 500) && $attempt < $maxRetries) {
                $attempt++;
                // backoff exponencial + jitter (us)
                $sleep = (int)min(2000000 * (2 ** ($attempt - 1)), 8000000);
                usleep($sleep + random_int(0, 400000));
                continue;
            }

            $latency = (int)round((microtime(true) - $started) * 1000);

            if ($status !== 200) {
                return $this->fail($model, $latency, "HTTP $status: " . substr((string)$res['body'], 0, 300));
            }

            $data = json_decode((string)$res['body'], true);
            $msg  = $data['choices'][0]['message'] ?? [];
            $usage = [
                'input_tokens'  => (int)($data['usage']['prompt_tokens'] ?? 0),
                'output_tokens' => (int)($data['usage']['completion_tokens'] ?? 0),
                'total_tokens'  => (int)($data['usage']['total_tokens'] ?? 0),
            ];

            if (!empty($msg['refusal'])) {
                return ['ok' => false, 'refused' => true, 'reason' => (string)$msg['refusal'],
                        'data' => null, 'usage' => $usage, 'latency_ms' => $latency, 'error' => null, 'model' => $model];
            }

            $content = $msg['content'] ?? '';
            $parsed  = json_decode((string)$content, true);
            if (!is_array($parsed)) {
                return $this->fail($model, $latency, 'conteudo nao e JSON valido', $usage);
            }

            return ['ok' => true, 'refused' => false, 'reason' => null, 'data' => $parsed,
                    'usage' => $usage, 'latency_ms' => $latency, 'error' => null, 'model' => $model];
        }
    }

    private function fail(string $model, int $latency, string $err, array $usage = []): array
    {
        return ['ok' => false, 'refused' => false, 'reason' => null, 'data' => null,
                'usage' => $usage ?: ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0],
                'latency_ms' => $latency, 'error' => $err, 'model' => $model];
    }

    private function post(string $path, array $body, int $timeout): array
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $resp   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr   = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            return ['status' => 0, 'body' => 'curl: ' . $cerr];
        }
        return ['status' => $status, 'body' => $resp];
    }
}
