<?php
namespace App\WhatsAppAgente\AiIntake;

/**
 * AnthropicProvider — fallback. A Anthropic Messages API nao tem response_format
 * json_schema como a OpenAI; aqui pedimos JSON estrito pelo prompt e validamos no backend
 * (IntakeSchema). O caminho recomendado de Structured Outputs e o OpenAiProvider.
 */
final class AnthropicProvider implements LlmProviderInterface
{
    private string $apiKey;
    private string $base;

    public function __construct(string $apiKey, string $base = 'https://api.anthropic.com/v1')
    {
        $this->apiKey = $apiKey;
        $this->base   = rtrim($base, '/');
    }

    public function name(): string { return 'anthropic'; }

    public function complete(string $model, string $systemPrompt, string $userMessage, array $responseFormat, array $opts = []): array
    {
        $model     = $model ?: 'claude-3-5-haiku-20241022';
        $maxTokens = (int)($opts['max_tokens'] ?? 900);
        $timeout   = (int)($opts['timeout'] ?? 45);
        $maxRetries = (int)($opts['retries'] ?? 3);

        // Anexa instrucao de JSON-only + o schema como guia (a validacao real e no backend).
        $schema = $responseFormat['json_schema']['schema'] ?? [];
        $sys = $systemPrompt . "\n\nIMPORTANTE: responda SOMENTE com um objeto JSON valido que "
             . "siga exatamente este JSON Schema (sem texto fora do JSON, sem markdown):\n"
             . json_encode($schema, JSON_UNESCAPED_UNICODE);

        $payload = [
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'temperature'=> $opts['temperature'] ?? 0,
            'system'     => $sys,
            'messages'   => [['role' => 'user', 'content' => $userMessage]],
        ];

        $attempt = 0;
        $started = microtime(true);
        while (true) {
            $res = $this->post('/messages', $payload, $timeout);
            $status = $res['status'];
            if (($status === 429 || $status >= 500) && $attempt < $maxRetries) {
                $attempt++;
                usleep((int)min(2000000 * (2 ** ($attempt - 1)), 8000000) + random_int(0, 400000));
                continue;
            }
            $latency = (int)round((microtime(true) - $started) * 1000);
            if ($status !== 200) {
                return ['ok'=>false,'refused'=>false,'reason'=>null,'data'=>null,
                        'usage'=>['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0],
                        'latency_ms'=>$latency,'error'=>"HTTP $status: ".substr((string)$res['body'],0,300),'model'=>$model];
            }
            $data = json_decode((string)$res['body'], true);
            $text = '';
            foreach (($data['content'] ?? []) as $blk) {
                if (($blk['type'] ?? '') === 'text') $text .= $blk['text'];
            }
            // remove cercas de codigo se vierem
            $text = trim(preg_replace('/^```(?:json)?|```$/m', '', $text));
            $parsed = json_decode($text, true);
            $usage = [
                'input_tokens'  => (int)($data['usage']['input_tokens'] ?? 0),
                'output_tokens' => (int)($data['usage']['output_tokens'] ?? 0),
                'total_tokens'  => (int)(($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0)),
            ];
            if (!is_array($parsed)) {
                return ['ok'=>false,'refused'=>false,'reason'=>null,'data'=>null,'usage'=>$usage,
                        'latency_ms'=>$latency,'error'=>'conteudo nao e JSON valido','model'=>$model];
            }
            return ['ok'=>true,'refused'=>false,'reason'=>null,'data'=>$parsed,'usage'=>$usage,
                    'latency_ms'=>$latency,'error'=>null,'model'=>$model];
        }
    }

    private function post(string $path, array $body, int $timeout): array
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
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
        if ($resp === false) return ['status' => 0, 'body' => 'curl: ' . $cerr];
        return ['status' => $status, 'body' => $resp];
    }
}
