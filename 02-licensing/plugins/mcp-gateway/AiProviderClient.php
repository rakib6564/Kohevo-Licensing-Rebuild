<?php
/**
 * MCP Gateway — generic OpenAI-compatible AI provider connector.
 *
 * One connector for whichever provider the tenant configures (OpenAI,
 * Anthropic via an OpenAI-compatible proxy, OpenRouter, a self-hosted
 * gateway, ...) — base URL + API key + model name, calling the standard
 * POST {base_url}/chat/completions shape. No provider-specific SDK code,
 * so swapping providers is a settings change, not a deploy.
 */

declare(strict_types=1);

class AiProviderClient {

    public static function isConfigured(): bool {
        return self::baseUrl() !== '' && self::apiKey() !== '' && self::model() !== '';
    }

    public static function baseUrl(): string {
        return rtrim((string) Database::setting('mcp-gateway.ai_base_url'), '/');
    }

    public static function model(): string {
        return trim((string) Database::setting('mcp-gateway.ai_model'));
    }

    public static function apiKey(): string {
        $raw = (string) Database::setting('mcp-gateway.ai_api_key');
        if ($raw === '') return '';
        return function_exists('slate_decrypt_secret') ? (string) (slate_decrypt_secret($raw) ?? '') : $raw;
    }

    /**
     * One chat-completion turn. $messages is the OpenAI chat-messages array
     * (role/content, plus tool/tool_calls entries for a tool-calling turn).
     * $tools, if given, is the OpenAI function-calling tools array. Returns
     * the raw assistant message array (content, tool_calls, ...).
     *
     * @throws RuntimeException on missing config, transport failure, or a
     *         non-2xx response — callers decide how to surface that.
     */
    public static function chat(array $messages, array $tools = [], float $temperature = 0.3): array {
        if (!self::isConfigured()) {
            throw new RuntimeException('AI provider is not configured (Settings → AI Connection).');
        }

        $payload = [
            'model'       => self::model(),
            'messages'    => $messages,
            'temperature' => $temperature,
        ];
        if ($tools) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $ch = curl_init(self::baseUrl() . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . self::apiKey(),
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT    => 60,
        ]);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('AI provider request failed: ' . $err);
        }
        $decoded = json_decode((string) $raw, true);
        if ($code < 200 || $code >= 300) {
            $msg = is_array($decoded) ? (string) ($decoded['error']['message'] ?? $raw) : (string) $raw;
            throw new RuntimeException('AI provider returned an error (' . $code . '): ' . mb_substr($msg, 0, 300));
        }
        $message = $decoded['choices'][0]['message'] ?? null;
        if (!is_array($message)) {
            throw new RuntimeException('AI provider returned an unexpected response shape.');
        }
        return $message;
    }
}
