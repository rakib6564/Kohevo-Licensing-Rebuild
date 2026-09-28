<?php
/**
 * MLT — AutoTranslate. Pluggable machine-translation provider interface,
 * used by the "Auto-translate" bulk action in the admin grid.
 *
 * Ships with one provider: LibreTranslate (open source, self-hostable,
 * https://libretranslate.com). IMPORTANT — read before wiring this up:
 *
 *   libretranslate.com's own public endpoint now REQUIRES a paid API key
 *   (https://portal.libretranslate.com) due to bot abuse; it is no longer
 *   free/keyless the way it used to be. This class still defaults to
 *   https://libretranslate.com/translate because that's the canonical
 *   endpoint and it's what LibreTranslate-compatible self-hosted /
 *   community instances also implement — but you have three real options
 *   for actually getting free translations out of it:
 *
 *     1. Self-host it (free, unlimited): `docker run -p 5000:5000
 *        libretranslate/libretranslate`, then set the endpoint setting to
 *        your own http://your-server:5000/translate. No API key needed.
 *     2. Buy a low-cost API key from https://portal.libretranslate.com and
 *        set it in the plugin's Auto-translate settings.
 *     3. Point the endpoint setting at a different community-run
 *        LibreTranslate-compatible instance (availability and uptime of
 *        these varies — check libretranslate.com's docs for anything
 *        current, since public mirror lists go stale fast).
 *
 *   Whichever you choose, nothing else in this file changes — endpoint and
 *   api_key are just two settings.
 *
 * To add a second provider (DeepL, Google, etc.), implement
 * MLT_TranslateProviderInterface and add a case to
 * MLT_AutoTranslate::provider().
 */

interface MLT_TranslateProviderInterface {
    /**
     * Translate one string. Must throw \RuntimeException with a clear
     * message on any failure (network, auth, rate limit, bad response) —
     * callers catch \Throwable and surface $e->getMessage() to the admin.
     */
    public function translate(string $text, string $sourceLocale, string $targetLocale): string;
}

/**
 * LibreTranslate REST client. Implements the same request shape LibreTranslate
 * documents at https://docs.libretranslate.com/guides/api_usage/ — works
 * against libretranslate.com (with a key), a self-hosted instance (no key),
 * or any other LibreTranslate-compatible server.
 */
class MLT_LibreTranslateProvider implements MLT_TranslateProviderInterface {

    private string $endpoint;
    private ?string $apiKey;
    private int $timeoutSeconds;

    public function __construct(string $endpoint = 'https://libretranslate.com/translate', ?string $apiKey = null, int $timeoutSeconds = 15) {
        $this->endpoint       = rtrim($endpoint, '/') === '' ? 'https://libretranslate.com/translate' : $endpoint;
        $this->apiKey         = ($apiKey !== null && trim($apiKey) !== '') ? trim($apiKey) : null;
        $this->timeoutSeconds = max(3, $timeoutSeconds);
    }

    public function translate(string $text, string $sourceLocale, string $targetLocale): string {
        if (trim($text) === '') return '';
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('curl extension is not available on this server.');
        }

        $payload = [
            'q'      => $text,
            'source' => $this->normalizeLocale($sourceLocale),
            'target' => $this->normalizeLocale($targetLocale),
            'format' => 'text',
        ];
        if ($this->apiKey !== null) {
            $payload['api_key'] = $this->apiKey;
        }

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $body = curl_exec($ch);
        $curlErr = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErr) {
            throw new \RuntimeException("LibreTranslate request failed: $curlErr");
        }
        $data = is_string($body) ? json_decode($body, true) : null;

        if ($httpCode === 403 || $httpCode === 429) {
            $msg = is_array($data) && !empty($data['error']) ? $data['error'] : 'rate limited / forbidden';
            throw new \RuntimeException(
                "LibreTranslate refused the request (HTTP $httpCode): $msg. " .
                "If you're using https://libretranslate.com, it now requires a paid API key " .
                "(https://portal.libretranslate.com) — self-host LibreTranslate instead for free unlimited use, " .
                "or add your key in Auto-translate settings."
            );
        }
        if ($httpCode >= 400) {
            $msg = is_array($data) && !empty($data['error']) ? $data['error'] : "HTTP $httpCode";
            throw new \RuntimeException("LibreTranslate error: $msg");
        }
        if (!is_array($data) || !isset($data['translatedText']) || !is_string($data['translatedText'])) {
            throw new \RuntimeException('LibreTranslate returned an unexpected response.');
        }
        return $data['translatedText'];
    }

    /** LibreTranslate wants bare ISO 639-1 codes (en, fr, de…), not en-US style. */
    private function normalizeLocale(string $locale): string {
        $locale = strtolower(trim($locale));
        return explode('-', $locale)[0] ?: 'en';
    }
}

/**
 * Facade: reads the configured provider + credentials from plugin settings
 * and exposes translate()/translateBatch() to the rest of the plugin
 * (StringRepo::bulkAutoTranslate, the admin API's `auto_translate` action).
 */
class MLT_AutoTranslate {

    /** slug => display name, for the settings UI. */
    const PROVIDERS = [
        'libretranslate' => 'LibreTranslate',
    ];

    private static ?MLT_TranslateProviderInterface $providerInstance = null;

    /** Build (and cache) the configured provider from plugin settings. */
    public static function provider(): MLT_TranslateProviderInterface {
        if (self::$providerInstance !== null) return self::$providerInstance;

        $slug = Database::setting('multilang-translate.autotranslate_provider') ?: 'libretranslate';
        $endpoint = Database::setting('multilang-translate.autotranslate_endpoint') ?: 'https://libretranslate.com/translate';
        $apiKey = Database::setting('multilang-translate.autotranslate_api_key') ?: null;

        switch ($slug) {
            case 'libretranslate':
            default:
                return self::$providerInstance = new MLT_LibreTranslateProvider($endpoint, $apiKey);
        }
    }

    /** Allow tests / callers to inject a provider instead of reading settings. */
    public static function setProvider(MLT_TranslateProviderInterface $provider): void {
        self::$providerInstance = $provider;
    }

    public static function resetProvider(): void {
        self::$providerInstance = null;
    }

    /**
     * Translate one string. Returns ['ok' => bool, 'text' => string, 'error' => ?string]
     * — never throws, so a bulk loop can keep going after one failure.
     */
    public static function translate(string $text, string $sourceLocale, string $targetLocale): array {
        try {
            $translated = self::provider()->translate($text, $sourceLocale, $targetLocale);
            return ['ok' => true, 'text' => $translated, 'error' => null];
        } catch (\Throwable $e) {
            slate_log('MLT auto-translate failed: ' . $e->getMessage(), 'error');
            return ['ok' => false, 'text' => '', 'error' => $e->getMessage()];
        }
    }
}
