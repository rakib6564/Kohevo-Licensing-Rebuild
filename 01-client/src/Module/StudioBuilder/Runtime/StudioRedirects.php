<?php
/**
 * Kohevo Studio (studio-builder) — the Redirects screen's rules, applied to public requests.
 *
 * Rules are stored per tenant in the `studio_redirects` setting as `{from, to, code}`. A request whose path matches a
 * rule's `from` (case-insensitively, ignoring a trailing slash and the query string) is answered with a 301 or 302 to
 * `to`: a site path (resolved against the site's base URL, so a site in a folder keeps its folder) or an absolute
 * http(s) URL. Studio sees only addresses the platform does not route itself, so a rule cannot take over the admin,
 * login or API, and `/` is never redirected.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Runtime;

use Slate\Data\Database;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Schema\FieldSchema;

final class StudioRedirects
{
    public const SETTING = 'studio_redirects';
    public const MAX_RULES = 500;

    /** @param list<array{from: string, to: string, code: int}> $rules */
    private function __construct(private readonly array $rules) {}

    /** The current tenant's rules. Best-effort: a broken setting means no redirects, never a broken page. */
    public static function fromSettings(?int $tenantId = null): self
    {
        try {
            $raw = Database::setting(self::SETTING, $tenantId);
        } catch (\Throwable) {
            return new self([]);
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return self::fromRules(is_array($decoded) ? $decoded : []);
    }

    /** @param array<int, mixed> $stored */
    public static function fromRules(array $stored): self
    {
        $rules = [];
        foreach (array_slice($stored, 0, self::MAX_RULES) as $rule) {
            if (!is_array($rule) || !is_string($rule['from'] ?? null) || !is_string($rule['to'] ?? null)) {
                continue;
            }
            $from = self::key($rule['from']);
            $to = trim($rule['to']);
            if ($from === null || $from === '/' || $to === '') {
                continue;
            }
            $rules[] = ['from' => $from, 'to' => $to, 'code' => (int) ($rule['code'] ?? 301) === 302 ? 302 : 301];
        }
        return new self($rules);
    }

    /** Whether a rule exists for this request path (cheap: no site context needed). */
    public function matches(string $path): bool
    {
        $key = self::key($path);
        return $key !== null && in_array($key, array_column($this->rules, 'from'), true);
    }

    /** The redirect for a request path, or null when no rule matches (or the rule's target is unusable). */
    public function responseFor(string $path, SiteContext $site): ?PublicResponse
    {
        $key = self::key($path);
        if ($key === null) {
            return null;
        }
        foreach ($this->rules as $rule) {
            if ($rule['from'] !== $key) {
                continue;
            }
            $location = self::location($rule['to'], $site);
            if ($location === null || self::siteKey($rule['to'], $site) === $key) {
                return null; // no target, or a rule pointing at itself
            }
            return PublicResponse::redirect($rule['code'], $location);
        }
        return null;
    }

    /** `/Old-Page/` and `old-page?x=1` both become `/old-page`; a full URL is reduced to its path. Null for nothing usable. */
    public static function key(string $path): ?string
    {
        $path = trim($path);
        if (preg_match('~^https?://~i', $path) === 1) {
            $path = (string) (parse_url($path, PHP_URL_PATH) ?? '/');
        }
        $path = strtok($path, '?#');
        if (!is_string($path) || strlen($path) > 500 || preg_match('/[\x00-\x1F\x7F\s]/', $path) === 1) {
            return null;
        }
        return '/' . strtolower(trim($path, '/'));
    }

    /** A target's path within this site (`/new-page`), or null when it is on another site. */
    private static function siteKey(string $to, SiteContext $site): ?string
    {
        if (preg_match('~^https?://~i', $to) !== 1) {
            return self::key($to);
        }
        $host = parse_url($to, PHP_URL_HOST);
        $path = (string) (parse_url($to, PHP_URL_PATH) ?? '/');
        $base = $site->basePath();
        if (!is_string($host) || $site->host() === null || strcasecmp($host, $site->host()) !== 0 || ($base !== '' && stripos($path, $base) !== 0)) {
            return null;
        }
        return self::key(substr($path, strlen($base)));
    }

    private static function location(string $to, SiteContext $site): ?string
    {
        if (preg_match('~^https?://~i', $to) === 1) {
            return FieldSchema::isSafeUrl($to) ? $to : null;
        }
        if (!str_starts_with($to, '/') || str_starts_with($to, '//') || preg_match('/[\x00-\x1F\x7F\s<>"\']/', $to) === 1) {
            return null;
        }
        return $site->baseUrl !== '' ? $site->absoluteUrl($to) : $site->basePath() . $to;
    }
}
