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

    /**
     * Vet an AI base URL before it is saved or called. It must be https,
     * carry no credentials, and every address its host resolves to must be
     * public (no private, loopback, link-local or reserved ranges), so the
     * admin-configured endpoint cannot be pointed at internal services.
     * $resolve maps a host to its IPs; it is injectable for tests.
     *
     * @return array{ok: bool, error: ?string, host?: string, port?: int, ip?: string}
     */
    public static function vetBaseUrl(string $url, ?callable $resolve = null): array {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
            return ['ok' => false, 'error' => 'https_required'];
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return ['ok' => false, 'error' => 'credentials_in_url'];
        }
        $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
        if ($host === '') return ['ok' => false, 'error' => 'host_missing'];
        $port = (int)($parts['port'] ?? 443);

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $resolve ??= static function (string $h): array {
                $ips = @gethostbynamel($h) ?: [];
                foreach (@dns_get_record($h, DNS_AAAA) ?: [] as $rec) {
                    if (!empty($rec['ipv6'])) $ips[] = $rec['ipv6'];
                }
                return $ips;
            };
            $ips = $resolve($host);
        }
        if (!$ips) return ['ok' => false, 'error' => 'host_unresolvable'];
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                || self::inBlockedRange($ip)) {
                return ['ok' => false, 'error' => 'private_address'];
            }
        }
        return ['ok' => true, 'error' => null, 'host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    /**
     * Ranges PHP's NO_PRIV/NO_RES flags let through but that are not public
     * internet hosts: carrier-grade NAT, benchmarking, multicast, and the
     * IPv6 transition prefixes that embed an IPv4 address (NAT64, 6to4,
     * Teredo), which could otherwise wrap 127.0.0.1 or 169.254.169.254.
     */
    private const BLOCKED_RANGES = [
        '100.64.0.0/10', '198.18.0.0/15', '224.0.0.0/4',
        '64:ff9b::/96', '64:ff9b:1::/48', '2002::/16', '2001::/32', 'ff00::/8', '100::/64',
    ];

    private static function inBlockedRange(string $ip): bool {
        $bin = @inet_pton($ip);
        if ($bin === false) return true;
        foreach (self::BLOCKED_RANGES as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $netBin = inet_pton($net);
            if (strlen($netBin) !== strlen($bin)) continue;
            $bytes = intdiv((int) $bits, 8);
            $rest  = (int) $bits % 8;
            if (substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) continue;
            if ($rest === 0) return true;
            $mask = chr((0xFF << (8 - $rest)) & 0xFF);
            if ((($bin[$bytes] ^ $netBin[$bytes]) & $mask) === "\0") return true;
        }
        return false;
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

        // Re-vet at call time (a value saved before this check existed, or a
        // DNS change since) and pin curl to the vetted address so it talks to
        // exactly what was checked (no DNS rebinding), https only, no redirects.
        $vet = self::vetBaseUrl(self::baseUrl());
        if (!$vet['ok']) {
            throw new RuntimeException('AI provider URL is not allowed (' . $vet['error'] . '): it must be a public https endpoint (Settings → AI Connection).');
        }
        $pinIp = str_contains($vet['ip'], ':') ? '[' . $vet['ip'] . ']' : $vet['ip'];

        $ch = curl_init(self::baseUrl() . '/chat/completions');
        // An IP-literal host is never looked up, so it needs no pin (and an
        // IPv6 literal cannot be written as a HOST:PORT:ADDR entry anyway).
        if (!filter_var($vet['host'], FILTER_VALIDATE_IP)) {
            curl_setopt($ch, CURLOPT_RESOLVE, [$vet['host'] . ':' . $vet['port'] . ':' . $pinIp]);
        }
        curl_setopt_array($ch, [
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
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
