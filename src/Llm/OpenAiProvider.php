<?php

declare(strict_types=1);

namespace Aidelnicek\Llm;

/**
 * OpenAI provider pro LLM vrstvu.
 *
 * Autentizace: env proměnná OPENAI_AUTH_BEARER obsahuje bearer token.
 * Hodnota může být API klíč (sk-...) nebo OAuth access token —
 * kód nerozlišuje typ, HTTP hlavička je v obou případech identická:
 *   Authorization: Bearer <OPENAI_AUTH_BEARER>
 */
class OpenAiProvider implements LlmInterface
{
    private string $bearerToken;
    private string $model;
    private string $baseUrl;
    private LlmLogger $logger;

    public function __construct()
    {
        $this->bearerToken = getenv('OPENAI_AUTH_BEARER') ?: '';
        $this->model       = getenv('OPENAI_MODEL')       ?: 'gpt-4o';
        $this->baseUrl     = rtrim(getenv('OPENAI_BASE_URL') ?: 'https://api.openai.com/v1', '/');
        $this->logger      = new LlmLogger();

        if (empty($this->bearerToken)) {
            throw new \RuntimeException(
                'OpenAI bearer token není nastaven. Nastavte env proměnnou OPENAI_AUTH_BEARER.'
            );
        }
    }

    public function complete(string $systemPrompt, string $userPrompt, array $options = []): string
    {
        $payload = [
            'model'       => $this->model,
            'messages'    => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ],
            'temperature' => (float) ($options['temperature'] ?? 0.7),
            'max_tokens'  => (int)   ($options['max_tokens']  ?? 4096),
        ];

        $requestAt = date('Y-m-d H:i:s');
        $startMs   = microtime(true);
        $status    = 'ok';
        $errorMsg  = null;
        $response  = '';
        $tokensIn  = null;
        $tokensOut = null;

        try {
            $raw       = $this->callApi('/chat/completions', $payload);
            $decoded   = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $response  = $decoded['choices'][0]['message']['content'] ?? '';
            $tokensIn  = $decoded['usage']['prompt_tokens']     ?? null;
            $tokensOut = $decoded['usage']['completion_tokens'] ?? null;
        } catch (\Throwable $e) {
            $status   = 'error';
            $errorMsg = $e->getMessage();
            throw new \RuntimeException('OpenAI API chyba: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->logger->log([
                'provider'      => 'openai',
                'model'         => $this->model,
                'user_id'       => $options['user_id'] ?? null,
                'prompt_system' => $systemPrompt,
                'prompt_user'   => $userPrompt,
                'response_text' => $response,
                'tokens_in'     => $tokensIn,
                'tokens_out'    => $tokensOut,
                'request_at'    => $requestAt,
                'duration_ms'   => (int) round((microtime(true) - $startMs) * 1000),
                'status'        => $status,
                'error_message' => $errorMsg,
            ]);
        }

        return $response;
    }

    public function getName(): string  { return 'openai'; }
    public function getModel(): string { return $this->model; }

    private function callApi(string $endpoint, array $payload): string
    {
        $url  = $this->baseUrl . $endpoint;
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->bearerToken,
            ],
        ]);

        $body     = curl_exec($ch);
        $errno    = curl_errno($ch);
        $errStr   = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException("curl chyba #{$errno}: {$errStr}");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException(
                "OpenAI HTTP {$httpCode}: " . substr((string) $body, 0, 300)
            );
        }

        return (string) $body;
    }
}
