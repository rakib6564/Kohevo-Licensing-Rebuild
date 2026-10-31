<?php
/**
 * Slate — the "ask whether an admin bar applies" hint cookie.
 *
 * Public Studio pages are cached and anonymous, and the session cookie is
 * HttpOnly, so a page's script cannot tell a signed-in admin from a visitor.
 * This cookie answers one question for that script: is it worth asking the
 * server? It holds no identity and grants nothing. Anyone can set it; the
 * answer still comes from a real session in admin/front-bar.php, which clears
 * the cookie when there is none.
 *
 * It is only ever written on login, logout, admin screens and that endpoint,
 * never on a public response (StudioHttpResponder would pass it on to a
 * cacheable page). Like the session cookie it ends with the browser session.
 */

declare(strict_types=1);

namespace Slate\Services\Auth;

final class AdminHint
{
    public const COOKIE = 'kohevo_admin_hint';

    /** @return array<string, mixed> setcookie() options: the session cookie's, readable by script, on this site's path. */
    public static function cookieOptions(int $expires = 0): array
    {
        $params = Auth::sessionCookieParams();
        return [
            'expires'  => $expires,
            'path'     => self::sitePath(defined('SLATE_URL') ? (string) SLATE_URL : ''),
            'secure'   => $params['secure'],
            'httponly' => false,
            'samesite' => $params['samesite'],
        ];
    }

    /** The URL path a site lives under (`/fightxlife` on a sub-folder site), `/` otherwise. */
    public static function sitePath(string $siteUrl): string
    {
        $path = parse_url($siteUrl, PHP_URL_PATH);
        $path = is_string($path) ? rtrim($path, '/') : '';
        return $path === '' ? '/' : $path;
    }

    public static function isPresent(): bool
    {
        return ($_COOKIE[self::COOKIE] ?? '') === '1';
    }

    public static function set(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) return;
        setcookie(self::COOKIE, '1', self::cookieOptions());
    }

    public static function clear(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) return;
        setcookie(self::COOKIE, '', self::cookieOptions(time() - 3600));
    }
}
