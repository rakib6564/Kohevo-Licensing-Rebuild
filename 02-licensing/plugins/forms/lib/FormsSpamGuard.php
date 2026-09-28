<?php
/**
 * Forms — anti-spam / security guard.
 *
 * One place for everything that decides whether a public submission is
 * allowed through:
 *
 *   - CAPTCHA — Cloudflare Turnstile, Google reCAPTCHA v3, or hCaptcha
 *   - Country allow / deny (GeoIP via proxy header, ip-api.com, or a
 *     bundled MaxMind GeoLite2 .mmdb file)
 *   - Content filtering — keyword blocklist, max-links rule, disposable
 *     email rejection, IP + email blocklists
 *   - A configurable per-IP rate limit
 *   - A signed hidden time-trap (submitted-too-fast = bot)
 *
 * All settings live in the form's settings_json under the `spam` key
 * (see FormsAPI::formSettings()). The public router calls evaluate()
 * once, before validation; the verdict says whether to drop the
 * submission silently (honeypot-style — the visitor still sees success)
 * or reject it with a visible message. Every block is recorded in
 * forms_spam_log when logging is on.
 *
 * Conventions match the rest of the plugin: PDO via Database, prepared
 * statements only, htmlspecialchars on echoed values, no framework.
 */

class FormsSpamGuard
{
    const CAPTCHA_PROVIDERS = ['turnstile', 'recaptcha', 'hcaptcha'];

    /** Server-side verify endpoints per provider. */
    const VERIFY_URLS = [
        'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        'recaptcha' => 'https://www.google.com/recaptcha/api/siteverify',
        'hcaptcha'  => 'https://api.hcaptcha.com/siteverify',
    ];

    /** The token field name each provider posts back. */
    const TOKEN_FIELD = [
        'turnstile' => 'cf-turnstile-response',
        'recaptcha' => 'g-recaptcha-response',
        'hcaptcha'  => 'h-captcha-response',
    ];

    // Resolved country cached for the life of one request so evaluate()
    // and the later submission INSERT never both hit a geo API.
    private static array $countryCache = [];

    // ─────────────────────────────────────────────────────────────
    //  Settings
    // ─────────────────────────────────────────────────────────────

    /** Default spam settings — normalize() merges stored values over these. */
    public static function defaults(): array
    {
        return [
            // CAPTCHA
            'captcha_provider' => 'none',   // none | turnstile | recaptcha | hcaptcha
            'captcha_site_key' => '',
            'captcha_secret'   => '',
            'recaptcha_min'    => 0.5,      // v3 score threshold (0..1)

            // Country
            'country_mode' => 'off',        // off | allow | block
            'country_list' => [],           // ISO-3166-1 alpha-2 codes
            'geo_method'   => 'auto',       // auto | header | api | maxmind
            'maxmind_db'   => '',           // absolute path to a GeoLite2-Country.mmdb

            // Content filtering
            'keywords'         => [],       // blocked words / phrases (case-insensitive)
            'max_links'        => 0,        // max URLs across text fields (0 = no limit)
            'block_disposable' => false,    // reject known disposable-email domains
            'ip_blocklist'     => [],       // exact IPs or CIDR ranges
            'email_blocklist'  => [],       // full emails or @domain entries

            // Rate limit + time-trap
            'rate_limit'  => 5,             // submissions per window per IP (0 = off)
            'rate_window' => 60,            // window length in seconds
            'min_seconds' => 0,             // min seconds between render & submit (0 = off)

            'log_blocked' => true,          // record blocked attempts in forms_spam_log
        ];
    }

    /** Coerce a stored/posted spam settings array into a clean, safe shape. */
    public static function normalize($arr): array
    {
        $out = self::defaults();
        if (!is_array($arr)) return $out;

        $out['captcha_provider'] = in_array($arr['captcha_provider'] ?? '', self::CAPTCHA_PROVIDERS, true)
            ? $arr['captcha_provider'] : 'none';
        $out['captcha_site_key'] = trim((string)($arr['captcha_site_key'] ?? ''));
        $out['captcha_secret']   = trim((string)($arr['captcha_secret'] ?? ''));
        $min = (float)($arr['recaptcha_min'] ?? 0.5);
        $out['recaptcha_min'] = ($min >= 0 && $min <= 1) ? $min : 0.5;

        $out['country_mode'] = in_array($arr['country_mode'] ?? '', ['allow', 'block'], true)
            ? $arr['country_mode'] : 'off';
        $out['country_list'] = self::cleanCodes($arr['country_list'] ?? []);
        $out['geo_method']   = in_array($arr['geo_method'] ?? '', ['header', 'api', 'maxmind'], true)
            ? $arr['geo_method'] : 'auto';
        $out['maxmind_db']   = trim((string)($arr['maxmind_db'] ?? ''));

        $out['keywords']         = self::cleanList($arr['keywords'] ?? []);
        $out['max_links']        = max(0, (int)($arr['max_links'] ?? 0));
        $out['block_disposable'] = !empty($arr['block_disposable']);
        $out['ip_blocklist']     = self::cleanList($arr['ip_blocklist'] ?? []);
        $out['email_blocklist']  = array_map('strtolower', self::cleanList($arr['email_blocklist'] ?? []));

        $out['rate_limit']  = max(0, (int)($arr['rate_limit'] ?? 5));
        $out['rate_window'] = max(10, (int)($arr['rate_window'] ?? 60));
        $out['min_seconds'] = max(0, (int)($arr['min_seconds'] ?? 0));

        $out['log_blocked'] = array_key_exists('log_blocked', $arr) ? !empty($arr['log_blocked']) : true;

        return $out;
    }

    /** Accept an array or a comma/newline string → list of trimmed, unique entries. */
    private static function cleanList($v): array
    {
        if (is_string($v)) $v = preg_split('/[\r\n,]+/', $v);
        if (!is_array($v)) return [];
        $out = [];
        foreach ($v as $item) {
            $s = trim((string)$item);
            if ($s !== '') $out[] = $s;
        }
        return array_values(array_unique($out));
    }

    /** Like cleanList but for 2-letter ISO country codes (upper-cased). */
    private static function cleanCodes($v): array
    {
        if (is_string($v)) $v = preg_split('/[\s,]+/', $v);
        if (!is_array($v)) return [];
        $out = [];
        foreach ($v as $c) {
            $c = strtoupper(trim((string)$c));
            if (preg_match('/^[A-Z]{2}$/', $c)) $out[] = $c;
        }
        return array_values(array_unique($out));
    }

    // ─────────────────────────────────────────────────────────────
    //  CAPTCHA — render + verify
    // ─────────────────────────────────────────────────────────────

    public static function captchaEnabled(array $spam): bool
    {
        return in_array($spam['captcha_provider'] ?? 'none', self::CAPTCHA_PROVIDERS, true)
            && ($spam['captcha_site_key'] ?? '') !== ''
            && ($spam['captcha_secret'] ?? '') !== '';
    }

    /** Provider <script> tag(s) to drop near the public form. */
    public static function captchaScript(array $spam): string
    {
        if (!self::captchaEnabled($spam)) return '';
        $key = htmlspecialchars($spam['captcha_site_key'], ENT_QUOTES, 'UTF-8');
        switch ($spam['captcha_provider']) {
            case 'turnstile':
                return '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
            case 'hcaptcha':
                return '<script src="https://js.hcaptcha.com/1/api.js" async defer></script>';
            case 'recaptcha':
                return '<script src="https://www.google.com/recaptcha/api.js?render=' . $key . '"></script>'
                     . self::recaptchaInlineJs($key);
        }
        return '';
    }

    /** Widget HTML to place just before the submit button (inside the form). */
    public static function captchaWidget(array $spam): string
    {
        if (!self::captchaEnabled($spam)) return '';
        $key = htmlspecialchars($spam['captcha_site_key'], ENT_QUOTES, 'UTF-8');
        switch ($spam['captcha_provider']) {
            case 'turnstile':
                return '<div class="forms-captcha"><div class="cf-turnstile" data-sitekey="' . $key . '"></div></div>';
            case 'hcaptcha':
                return '<div class="forms-captcha"><div class="h-captcha" data-sitekey="' . $key . '"></div></div>';
            case 'recaptcha':
                // v3 is invisible: a hidden token field the inline JS fills on submit.
                return '<input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response">'
                     . '<div class="forms-captcha-note">Protected by reCAPTCHA — the Google '
                     . '<a href="https://policies.google.com/privacy" target="_blank" rel="noopener">Privacy Policy</a> and '
                     . '<a href="https://policies.google.com/terms" target="_blank" rel="noopener">Terms</a> apply.</div>';
        }
        return '';
    }

    /**
     * reCAPTCHA v3 has no visible widget — it scores the request from a
     * token fetched at submit time. Intercept the form submit in the
     * capture phase, fetch a token, stash it in the hidden field and let
     * the submit proceed (the second pass sees the token and passes
     * straight through to any other submit handlers, e.g. forms-logic.js).
     */
    private static function recaptchaInlineJs(string $key): string
    {
        return '<script>(function(){var KEY=' . json_encode($key) . ';'
            . 'function hook(form){if(form.__grc)return;form.__grc=true;'
            . 'form.addEventListener("submit",function(e){'
            . 'var f=form.querySelector("#g-recaptcha-response");'
            . 'if(f&&f.value)return;' // already have a token — allow normal submit
            . 'e.preventDefault();e.stopPropagation();'
            . 'if(typeof grecaptcha==="undefined"){if(f)f.value="";return;}'
            . 'grecaptcha.ready(function(){grecaptcha.execute(KEY,{action:"submit"}).then(function(t){'
            . 'if(f)f.value=t;if(form.requestSubmit){form.requestSubmit();}else{form.submit();}'
            . '});});'
            . '},true);}'
            . 'document.addEventListener("DOMContentLoaded",function(){'
            . 'var f=document.querySelector("form[method=post]");if(f)hook(f);});'
            . '})();</script>';
    }

    /**
     * Verify the CAPTCHA token with the provider. Returns true when the
     * check passes or no CAPTCHA is configured. Fails OPEN on a provider
     * outage (a Google/Cloudflare blip shouldn't lock out real users) —
     * but fails CLOSED on a missing or rejected token.
     */
    public static function verifyCaptcha(array $spam, array $post, string $ip): bool
    {
        if (!self::captchaEnabled($spam)) return true;
        $provider = $spam['captcha_provider'];
        $token = (string)($post[self::TOKEN_FIELD[$provider] ?? ''] ?? '');
        if ($token === '') return false;

        $url = self::VERIFY_URLS[$provider] ?? '';
        if ($url === '') return true;

        $res = self::httpPost($url, http_build_query([
            'secret'   => $spam['captcha_secret'],
            'response' => $token,
            'remoteip' => $ip,
        ]));
        if ($res === null) {
            self::warn('CAPTCHA verify unreachable for ' . $provider . ' — failing open.');
            return true;
        }
        $json = json_decode($res, true);
        if (!is_array($json)) return false;
        if (empty($json['success'])) return false;

        // reCAPTCHA v3 returns a score; enforce the configured threshold.
        if ($provider === 'recaptcha' && array_key_exists('score', $json)) {
            return (float)$json['score'] >= (float)$spam['recaptcha_min'];
        }
        return true;
    }

    // ─────────────────────────────────────────────────────────────
    //  Country resolution
    // ─────────────────────────────────────────────────────────────

    /**
     * Resolve an IP to an ISO-3166-1 alpha-2 country code, or '' if
     * unknown. `auto` tries the cheapest source first: a proxy header
     * (Cloudflare etc.), then a bundled MaxMind DB if a path is set,
     * then the free ip-api.com lookup.
     */
    public static function resolveCountry(string $ip, string $method = 'auto', string $mmdb = ''): string
    {
        if ($ip === '') return '';
        $cacheKey = $method . '|' . $mmdb . '|' . $ip;
        if (isset(self::$countryCache[$cacheKey])) return self::$countryCache[$cacheKey];

        $country = '';
        if ($method === 'header' || $method === 'auto') {
            $country = self::countryFromHeader();
        }
        if ($country === '' && ($method === 'maxmind' || ($method === 'auto' && $mmdb !== ''))) {
            $country = self::countryFromMaxmind($ip, $mmdb);
        }
        if ($country === '' && ($method === 'api' || $method === 'auto')) {
            $country = self::countryFromApi($ip);
        }

        return self::$countryCache[$cacheKey] = $country;
    }

    /** Country from a trusted proxy/CDN header (Cloudflare and friends). */
    private static function countryFromHeader(): string
    {
        foreach (['HTTP_CF_IPCOUNTRY', 'HTTP_X_COUNTRY_CODE', 'HTTP_X_GEO_COUNTRY', 'HTTP_X_APPENGINE_COUNTRY'] as $h) {
            $v = strtoupper(trim((string)($_SERVER[$h] ?? '')));
            if (preg_match('/^[A-Z]{2}$/', $v) && $v !== 'XX') return $v;
        }
        return '';
    }

    /** Country from the free, no-key ip-api.com endpoint (best-effort). */
    private static function countryFromApi(string $ip): string
    {
        $res = self::httpGet('http://ip-api.com/json/' . urlencode($ip) . '?fields=status,countryCode', 4);
        if ($res === null) return '';
        $j = json_decode($res, true);
        if (is_array($j) && ($j['status'] ?? '') === 'success') {
            $cc = strtoupper((string)($j['countryCode'] ?? ''));
            if (preg_match('/^[A-Z]{2}$/', $cc)) return $cc;
        }
        return '';
    }

    /**
     * Country from a MaxMind GeoLite2-Country .mmdb. Prefers the official
     * \MaxMind\Db\Reader if it's installed (via Composer); otherwise uses
     * the compact built-in reader below. Always fails safe (returns '')
     * so a parse hiccup never produces a wrong block.
     */
    private static function countryFromMaxmind(string $ip, string $mmdb): string
    {
        if ($mmdb === '' || !is_readable($mmdb)) return '';
        if (class_exists('\\MaxMind\\Db\\Reader')) {
            try {
                $reader = new \MaxMind\Db\Reader($mmdb);
                $rec = $reader->get($ip);
                $reader->close();
                $cc = $rec['country']['iso_code'] ?? ($rec['registered_country']['iso_code'] ?? '');
                $cc = strtoupper((string)$cc);
                return preg_match('/^[A-Z]{2}$/', $cc) ? $cc : '';
            } catch (\Throwable $e) {
                return '';
            }
        }
        try {
            return (new FormsMmdbReader($mmdb))->countryIso($ip);
        } catch (\Throwable $e) {
            return '';
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  Content filtering
    // ─────────────────────────────────────────────────────────────

    /**
     * Gather the scannable text values from $_POST, keyed by field name.
     * File / signature / display fields are skipped (their values are
     * base64 blobs or empty and would only create false positives).
     */
    public static function scannableText(array $form, array $post): array
    {
        $skip = ['file', 'signature', 'heading', 'step', 'hidden'];
        $out  = [];
        foreach (($form['fields'] ?? []) as $f) {
            $type = $f['type'] ?? '';
            $name = $f['name'] ?? '';
            if ($name === '' || in_array($type, $skip, true)) continue;
            $v = $post[$name] ?? '';
            if (is_array($v)) $v = implode(' ', array_map('strval', $v));
            $out[$name] = (string)$v;
        }
        return $out;
    }

    /**
     * Run the content rules over the scannable text. Returns
     * ['code'=>..., 'reason'=>...] for the first rule that trips, or null.
     */
    public static function checkContent(array $spam, array $texts): ?array
    {
        $blob = implode("\n", $texts);

        foreach ($spam['keywords'] as $kw) {
            if ($kw !== '' && mb_stripos($blob, $kw) !== false) {
                return ['code' => 'keyword', 'reason' => 'Blocked keyword: ' . $kw];
            }
        }

        if ($spam['max_links'] > 0) {
            $n = preg_match_all('~\bhttps?://~i', $blob);
            if ($n > $spam['max_links']) {
                return ['code' => 'links', 'reason' => $n . ' links (limit ' . $spam['max_links'] . ')'];
            }
        }

        if ($spam['email_blocklist'] || $spam['block_disposable']) {
            foreach (self::extractEmails($blob) as $em) {
                $em     = strtolower($em);
                $domain = substr((string)strrchr($em, '@'), 1);
                foreach ($spam['email_blocklist'] as $b) {
                    if ($b === $em
                        || ($b !== '' && $b[0] === '@' && $domain === ltrim($b, '@'))
                        || $domain === $b) {
                        return ['code' => 'email', 'reason' => 'Blocked email: ' . $em];
                    }
                }
                if ($spam['block_disposable'] && self::isDisposable($domain)) {
                    return ['code' => 'disposable', 'reason' => 'Disposable email domain: ' . $domain];
                }
            }
        }

        return null;
    }

    private static function extractEmails(string $text): array
    {
        if (!preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $text, $m)) return [];
        return array_values(array_unique($m[0]));
    }

    /** A small built-in set of throwaway-email domains. */
    public static function isDisposable(string $domain): bool
    {
        static $list = [
            'mailinator.com', 'guerrillamail.com', 'guerrillamail.info', 'sharklasers.com',
            '10minutemail.com', '10minutemail.net', 'tempmail.com', 'temp-mail.org',
            'throwawaymail.com', 'trashmail.com', 'trashmail.net', 'getnada.com', 'nada.email',
            'maildrop.cc', 'dispostable.com', 'yopmail.com', 'yopmail.net', 'fakeinbox.com',
            'mailnesia.com', 'mintemail.com', 'mohmal.com', 'spamgourmet.com', 'mytemp.email',
            'tempinbox.com', 'emailondeck.com', 'mailcatch.com', 'tempr.email', 'discard.email',
            'getairmail.com', 'inboxbear.com', 'spam4.me', 'grr.la', 'pokemail.net',
            'mailsac.com', 'burnermail.io', 'moakt.com', 'tmail.ws', 'mailtemp.net',
        ];
        $domain = strtolower(trim($domain));
        return $domain !== '' && in_array($domain, $list, true);
    }

    // ─────────────────────────────────────────────────────────────
    //  IP blocklist, rate limit, time-trap
    // ─────────────────────────────────────────────────────────────

    public static function ipBlocked(array $spam, string $ip): bool
    {
        if ($ip === '') return false;
        foreach ($spam['ip_blocklist'] as $entry) {
            if (self::ipMatch($ip, $entry)) return true;
        }
        return false;
    }

    /** Match an IP against an exact address or a CIDR range (v4 + v6). */
    private static function ipMatch(string $ip, string $entry): bool
    {
        $entry = trim($entry);
        if ($entry === '') return false;
        if (strpos($entry, '/') === false) {
            return @inet_pton($ip) !== false && @inet_pton($entry) === @inet_pton($ip);
        }
        [$subnet, $bits] = explode('/', $entry, 2);
        $bits = (int)$bits;
        $ipBin     = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false) return false;
        if (strlen($ipBin) !== strlen($subnetBin)) return false; // mixed v4/v6
        $bytes = intdiv($bits, 8);
        $rem   = $bits % 8;
        if ($bytes > 0 && strncmp($ipBin, $subnetBin, $bytes) !== 0) return false;
        if ($rem === 0) return true;
        $mask = chr((0xff << (8 - $rem)) & 0xff);
        return (($ipBin[$bytes] & $mask) === ($subnetBin[$bytes] & $mask));
    }

    /** True when the IP is under the per-IP rate limit (or the limit is off). */
    public static function rateOk(array $spam, string $ip): bool
    {
        if ($spam['rate_limit'] <= 0 || $ip === '') return true;
        $count = (int) Database::value(
            "SELECT COUNT(*) FROM forms_submissions
              WHERE tenant_id = ? AND ip = ? AND created_at > (NOW() - INTERVAL ? SECOND)",
            [current_tenant_id(), $ip, $spam['rate_window']]
        );
        return $count < $spam['rate_limit'];
    }

    /** Signed hidden field carrying the render time, for the time-trap. */
    public static function timeTrapField(): string
    {
        $ts  = time();
        $sig = self::sign((string)$ts);
        return '<input type="hidden" name="_fts" value="' . $ts . '.' . $sig . '">';
    }

    /**
     * True when enough time has passed since the form was rendered. A
     * missing or tampered token fails (only matters when min_seconds>0,
     * and the field is always emitted in that case, so real submits have it).
     */
    public static function timeTrapOk(array $spam, array $post): bool
    {
        if ($spam['min_seconds'] <= 0) return true;
        $token = (string)($post['_fts'] ?? '');
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) return false;
        [$ts, $sig] = $parts;
        if (!hash_equals(self::sign($ts), $sig)) return false;
        return (time() - (int)$ts) >= $spam['min_seconds'];
    }

    private static function sign(string $v): string
    {
        // CORE-1: no secret means no signature. Callers treat '' as invalid.
        return (string) (slate_sign('forms-timetrap', $v, 16) ?? '');
    }

    // ─────────────────────────────────────────────────────────────
    //  Orchestration
    // ─────────────────────────────────────────────────────────────

    /**
     * Run every enabled check, cheapest first. Returns:
     *   ['ok'=>bool, 'silent'=>bool, 'code'=>string, 'reason'=>string,
     *    'message'=>string, 'country'=>string]
     *
     * `silent` blocks are dropped honeypot-style (the visitor still sees
     * the success page); non-silent blocks show `message`. Blocks are
     * logged here so the router stays thin.
     */
    public static function evaluate(array $form, array $spam, array $post, string $ip): array
    {
        $texts = self::scannableText($form, $post);

        $deny = function (string $code, string $reason, bool $silent, string $message, string $country = '')
                use ($form, $spam, $ip, $texts) {
            self::logBlock($form, $spam, $code, $reason, $ip, $country, $texts);
            return ['ok' => false, 'silent' => $silent, 'code' => $code,
                    'reason' => $reason, 'message' => $message, 'country' => $country];
        };

        // 1. IP blocklist — silent (don't tip off the sender).
        if (self::ipBlocked($spam, $ip)) {
            return $deny('ip', 'Blocklisted IP: ' . $ip, true, '');
        }

        // 2. Time-trap — silent.
        if (!self::timeTrapOk($spam, $post)) {
            return $deny('timetrap', 'Submitted too fast (under ' . $spam['min_seconds'] . 's)', true, '');
        }

        // 3. Content rules — silent.
        $content = self::checkContent($spam, $texts);
        if ($content !== null) {
            return $deny($content['code'], $content['reason'], true, '');
        }

        // 4. Country — visible (a legitimate policy, not a spam trap).
        $country = '';
        if ($spam['country_mode'] !== 'off') {
            $country = self::resolveCountry($ip, $spam['geo_method'], $spam['maxmind_db']);
            if ($country !== '') { // unknown country never blocks — fail open
                $inList = in_array($country, $spam['country_list'], true);
                $blocked = ($spam['country_mode'] === 'allow') ? !$inList : $inList;
                if ($blocked) {
                    return $deny('country', 'Country ' . $country . ' (' . $spam['country_mode'] . ' list)',
                        false, "Sorry — this form isn't available in your region.", $country);
                }
            }
        }

        // 5. Rate limit — visible.
        if (!self::rateOk($spam, $ip)) {
            return $deny('rate', 'Rate limit exceeded for ' . $ip, false,
                'Too many submissions. Please wait a moment and try again.', $country);
        }

        // 6. CAPTCHA — visible (last; it's the most expensive check).
        if (!self::verifyCaptcha($spam, $post, $ip)) {
            return $deny('captcha', 'CAPTCHA verification failed', false,
                'Captcha verification failed. Please try again.', $country);
        }

        return ['ok' => true, 'silent' => false, 'code' => '', 'reason' => '',
                'message' => '', 'country' => $country];
    }

    /** Record a blocked attempt in forms_spam_log (best-effort). */
    public static function logBlock(array $form, array $spam, string $code, string $reason,
                                    string $ip, string $country, array $texts): void
    {
        if (empty($spam['log_blocked'])) return;
        try {
            $snippet = mb_substr(trim(implode(' | ', $texts)), 0, 500);
            Database::insert('forms_spam_log', [
                'tenant_id'  => current_tenant_id(),
                'form_id'    => (int)($form['id'] ?? 0),
                'code'       => mb_substr($code, 0, 40),
                'reason'     => mb_substr($reason, 0, 255),
                'ip'         => $ip !== '' ? $ip : null,
                'country'    => $country !== '' ? $country : null,
                'user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                'snippet'    => $snippet,
            ]);
        } catch (\Throwable $e) {
            self::warn('spam log insert failed: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  HTTP + logging helpers
    // ─────────────────────────────────────────────────────────────

    private static function httpPost(string $url, string $body, int $timeout = 6): ?string
    {
        if (!function_exists('curl_init')) return null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Slate-Forms/0.6',
        ]);
        $res = curl_exec($ch);
        $err = curl_errno($ch);
        curl_close($ch);
        return ($res === false || $err) ? null : (string) $res;
    }

    private static function httpGet(string $url, int $timeout = 5): ?string
    {
        if (!function_exists('curl_init')) return null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'Slate-Forms/0.6',
        ]);
        $res = curl_exec($ch);
        $err = curl_errno($ch);
        curl_close($ch);
        return ($res === false || $err) ? null : (string) $res;
    }

    private static function warn(string $msg): void
    {
        if (function_exists('slate_log')) slate_log('Forms spam guard: ' . $msg, 'warning');
    }
}


/**
 * Compact, dependency-free MaxMind DB reader — just enough to pull a
 * country ISO code out of a GeoLite2-Country .mmdb. Used only when the
 * official \MaxMind\Db\Reader isn't installed. Best-effort: any parse
 * anomaly bubbles up as an exception that the caller turns into ''
 * (unknown country → never blocks), so a malformed DB can't cause a
 * wrong block. Implements the MaxMind DB file format spec:
 * https://maxmind.github.io/MaxMind-DB/
 */
class FormsMmdbReader
{
    private string $data;
    private int $nodeCount;
    private int $recordSize;     // bits per record
    private int $nodeByteSize;   // bytes per node (recordSize*2/8)
    private int $treeSize;       // bytes
    private int $ipVersion;
    private int $dataStart;      // byte offset of the data section

    public function __construct(string $path)
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException('mmdb unreadable');
        }
        $this->data = $bytes;
        $this->readMetadata();
        $this->nodeByteSize = intdiv($this->recordSize * 2, 8);
        $this->treeSize     = $this->nodeCount * $this->nodeByteSize;
        $this->dataStart    = $this->treeSize + 16; // 16-byte separator
    }

    public function countryIso(string $ip): string
    {
        $rec = $this->lookup($ip);
        if (!is_array($rec)) return '';
        $cc = $rec['country']['iso_code'] ?? ($rec['registered_country']['iso_code'] ?? '');
        $cc = strtoupper((string) $cc);
        return preg_match('/^[A-Z]{2}$/', $cc) ? $cc : '';
    }

    private function readMetadata(): void
    {
        $marker = "\xab\xcd\xefMaxMind.com";
        $pos = strrpos($this->data, $marker);
        if ($pos === false) throw new \RuntimeException('no metadata marker');
        $offset = $pos + strlen($marker);
        [$meta] = $this->decode($offset, 0); // metadata pointers are relative to metadata start (0 base here)
        if (!is_array($meta)) throw new \RuntimeException('bad metadata');
        $this->nodeCount  = (int)($meta['node_count'] ?? 0);
        $this->recordSize = (int)($meta['record_size'] ?? 0);
        $this->ipVersion  = (int)($meta['ip_version'] ?? 6);
        if ($this->nodeCount <= 0 || !in_array($this->recordSize, [24, 28, 32], true)) {
            throw new \RuntimeException('unsupported mmdb params');
        }
    }

    private function lookup(string $ip): ?array
    {
        $packed = @inet_pton($ip);
        if ($packed === false) return null;
        $bits = [];
        foreach (str_split($packed) as $ch) {
            $b = ord($ch);
            for ($i = 7; $i >= 0; $i--) $bits[] = ($b >> $i) & 1;
        }
        // An IPv4 address in a v6 tree: skip the first 96 zero bits.
        if ($this->ipVersion === 6 && strlen($packed) === 4) {
            $node = $this->walkZeros(96);
            if ($node < 0) return null;
        } else {
            $node = 0;
        }

        foreach ($bits as $bit) {
            if ($node >= $this->nodeCount) break;
            $node = $this->readRecord($node, $bit);
        }
        if ($node === $this->nodeCount) return null;       // not found
        if ($node < $this->nodeCount) return null;          // ran out of bits at a node
        $offset = ($node - $this->nodeCount) + $this->treeSize;
        [$val] = $this->decode($offset, $this->dataStart);
        return is_array($val) ? $val : null;
    }

    private function walkZeros(int $count): int
    {
        $node = 0;
        for ($i = 0; $i < $count; $i++) {
            if ($node >= $this->nodeCount) return $node;
            $node = $this->readRecord($node, 0);
        }
        return $node;
    }

    private function readRecord(int $node, int $bit): int
    {
        $base = $node * $this->nodeByteSize;
        if ($this->recordSize === 24) {
            $o = $base + ($bit ? 3 : 0);
            return (ord($this->data[$o]) << 16) | (ord($this->data[$o + 1]) << 8) | ord($this->data[$o + 2]);
        }
        if ($this->recordSize === 28) {
            if ($bit === 0) {
                $mid = (ord($this->data[$base + 3]) & 0xf0) >> 4;
                return ($mid << 24) | (ord($this->data[$base]) << 16)
                     | (ord($this->data[$base + 1]) << 8) | ord($this->data[$base + 2]);
            }
            $mid = ord($this->data[$base + 3]) & 0x0f;
            return ($mid << 24) | (ord($this->data[$base + 4]) << 16)
                 | (ord($this->data[$base + 5]) << 8) | ord($this->data[$base + 6]);
        }
        // 32-bit
        $o = $base + ($bit ? 4 : 0);
        return (ord($this->data[$o]) << 24) | (ord($this->data[$o + 1]) << 16)
             | (ord($this->data[$o + 2]) << 8) | ord($this->data[$o + 3]);
    }

    /**
     * Decode the MMDB data field at $offset. $dataStart is the base that
     * pointer values are relative to. Returns [value, nextOffset].
     */
    private function decode(int $offset, int $dataStart): array
    {
        $ctrl = ord($this->data[$offset]);
        $offset++;
        $type = $ctrl >> 5;
        if ($type === 0) {            // extended type
            $type = 7 + ord($this->data[$offset]);
            $offset++;
        }

        if ($type === 1) {            // pointer
            $ss = ($ctrl >> 3) & 0x3;
            $low = $ctrl & 0x7;
            if ($ss === 0) {
                $p = ($low << 8) | ord($this->data[$offset]);
                $offset += 1;
            } elseif ($ss === 1) {
                $p = ($low << 16) | (ord($this->data[$offset]) << 8) | ord($this->data[$offset + 1]);
                $p += 2048;
                $offset += 2;
            } elseif ($ss === 2) {
                $p = ($low << 24) | (ord($this->data[$offset]) << 16)
                   | (ord($this->data[$offset + 1]) << 8) | ord($this->data[$offset + 2]);
                $p += 526336;
                $offset += 3;
            } else {
                $p = (ord($this->data[$offset]) << 24) | (ord($this->data[$offset + 1]) << 16)
                   | (ord($this->data[$offset + 2]) << 8) | ord($this->data[$offset + 3]);
                $offset += 4;
            }
            [$val] = $this->decode($dataStart + $p, $dataStart);
            return [$val, $offset];
        }

        // Size from the low 5 bits (+ extension bytes).
        $size = $ctrl & 0x1f;
        if ($size === 29) {
            $size = 29 + ord($this->data[$offset]);
            $offset += 1;
        } elseif ($size === 30) {
            $size = 285 + ((ord($this->data[$offset]) << 8) | ord($this->data[$offset + 1]));
            $offset += 2;
        } elseif ($size === 31) {
            $size = 65821 + ((ord($this->data[$offset]) << 16)
                  | (ord($this->data[$offset + 1]) << 8) | ord($this->data[$offset + 2]));
            $offset += 3;
        }

        switch ($type) {
            case 2: // UTF-8 string
                $val = substr($this->data, $offset, $size);
                return [$val, $offset + $size];
            case 5: // uint16
            case 6: // uint32
            case 9: // uint64
            case 10: // uint128
                $val = 0;
                for ($i = 0; $i < $size; $i++) $val = ($val << 8) | ord($this->data[$offset + $i]);
                return [$val, $offset + $size];
            case 7: // map
                $map = [];
                for ($i = 0; $i < $size; $i++) {
                    [$k, $offset] = $this->decode($offset, $dataStart);
                    [$v, $offset] = $this->decode($offset, $dataStart);
                    $map[(string)$k] = $v;
                }
                return [$map, $offset];
            case 11: // array
                $arr = [];
                for ($i = 0; $i < $size; $i++) {
                    [$v, $offset] = $this->decode($offset, $dataStart);
                    $arr[] = $v;
                }
                return [$arr, $offset];
            case 14: // boolean (value is the size, no payload bytes)
                return [(bool)$size, $offset];
            case 8: // int32
                $val = 0;
                for ($i = 0; $i < $size; $i++) $val = ($val << 8) | ord($this->data[$offset + $i]);
                return [$val, $offset + $size];
            case 3: // double
                $val = $size === 8 ? unpack('E', substr($this->data, $offset, 8))[1] : 0.0;
                return [$val, $offset + $size];
            case 15: // float
                $val = $size === 4 ? unpack('G', substr($this->data, $offset, 4))[1] : 0.0;
                return [$val, $offset + $size];
            case 4: // bytes
            default:
                $val = substr($this->data, $offset, $size);
                return [$val, $offset + $size];
        }
    }
}
