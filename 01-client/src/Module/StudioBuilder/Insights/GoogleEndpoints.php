<?php
/**
 * Kohevo Studio — Google's addresses. STUDIO_GOOGLE_FAKE_URL points them at tests/fixtures/fake-google.php in a sandbox; only
 * a loopback address is honoured, so a wrong setting can never send a token to another host.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Insights;

final class GoogleEndpoints
{
    public const AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const TOKEN = 'https://oauth2.googleapis.com/token';
    public const REVOKE = 'https://oauth2.googleapis.com/revoke';
    public const USERINFO = 'https://openidconnect.googleapis.com/v1/userinfo';
    public const SEARCH_CONSOLE = 'https://www.googleapis.com/webmasters/v3';
    public const ANALYTICS_DATA = 'https://analyticsdata.googleapis.com/v1beta';
    public const ANALYTICS_ADMIN = 'https://analyticsadmin.googleapis.com/v1beta';
    public const PAGESPEED = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    /** `https://host/path` as `<fake>/host/path` when a loopback fake is set, otherwise unchanged. */
    public static function url(string $url, ?string $fake = null): string
    {
        $fake = $fake ?? (string) ($_ENV['STUDIO_GOOGLE_FAKE_URL'] ?? getenv('STUDIO_GOOGLE_FAKE_URL'));
        if ($fake === '' || !in_array(parse_url($fake, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) {
            return $url;
        }
        return rtrim($fake, '/') . '/' . substr($url, strlen('https://'));
    }
}
