<?php
/**
 * Forms integrations — Brevo (API v3). https://developers.brevo.com/reference
 *
 * The transport is injectable so the tests run without the network. The address is fixed here and never
 * comes from settings, so the API key can only ever go to Brevo.
 */

declare(strict_types=1);

require_once __DIR__ . '/NewsletterConnector.php';

class BrevoConnector implements NewsletterConnector {
    private const API = 'https://api.brevo.com/v3';
    /** Lists read for the picker: one page, more than a small organisation has. */
    private const LIST_LIMIT = 50;

    /** @var callable(string, string, string, ?string): array{0: int, 1: string} method, path, key, body → status, body */
    private $transport;

    public function __construct(?callable $transport = null) {
        $this->transport = $transport ?? [self::class, 'send'];
    }

    public function key(): string { return 'brevo'; }

    public function label(): string { return 'Brevo'; }

    public function lists(string $apiKey): array {
        $body = $this->call('GET', '/contacts/lists?limit=' . self::LIST_LIMIT . '&offset=0', $apiKey, null);
        $lists = [];
        foreach ((array) ($body['lists'] ?? []) as $list) {
            if (is_array($list) && isset($list['id'])) {
                $lists[] = ['id' => (int) $list['id'], 'name' => (string) ($list['name'] ?? ('#' . $list['id']))];
            }
        }
        return $lists;
    }

    public function subscribe(string $apiKey, int $listId, string $email, array $attributes, array $doubleOptin): void {
        $attributes = array_filter($attributes, static fn(string $v): bool => $v !== '');
        if (!empty($doubleOptin['template_id'])) {
            $payload = ['email' => $email, 'includeListIds' => [$listId], 'templateId' => (int) $doubleOptin['template_id'], 'redirectionUrl' => (string) ($doubleOptin['redirect_url'] ?? '')];
            if ($attributes !== []) { $payload['attributes'] = $attributes; }
            $this->call('POST', '/contacts/doubleOptinConfirmation', $apiKey, (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }
        $payload = ['email' => $email, 'listIds' => [$listId], 'updateEnabled' => true];
        if ($attributes !== []) { $payload['attributes'] = $attributes; }
        $this->call('POST', '/contacts', $apiKey, (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, mixed> the decoded response */
    private function call(string $method, string $path, string $apiKey, ?string $body): array {
        [$status, $response] = ($this->transport)($method, self::API . $path, $apiKey, $body);
        $decoded = json_decode($response, true);
        $decoded = is_array($decoded) ? $decoded : [];
        if ($status >= 200 && $status < 300) {
            return $decoded;
        }
        $message = trim((string) ($decoded['message'] ?? ''));
        $detail = 'Brevo ' . ($status > 0 ? $status : 'unreachable') . ($message !== '' ? ': ' . mb_substr($message, 0, 200) : '');
        // Rate limits, Brevo's "too early" and its own failures pass; any other refusal would be refused again.
        throw new IntegrationException($detail, $status === 0 || $status === 425 || $status === 429 || $status >= 500);
    }

    /** @return array{0: int, 1: string} */
    private static function send(string $method, string $url, string $apiKey, ?string $body): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => ['accept: application/json', 'content-type: application/json', 'api-key: ' . $apiKey],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        $response = curl_exec($ch);
        $status = $response === false ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$status, is_string($response) ? $response : ''];
    }
}
