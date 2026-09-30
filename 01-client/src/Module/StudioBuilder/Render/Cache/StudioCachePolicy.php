<?php
/**
 * Kohevo Studio (studio-builder) — HTTP cache policy per runtime mode.
 *
 * Public:  `public, no-cache` + a strong ETag over the final HTML. Any shared
 *          cache must revalidate every request with the origin, so a publish,
 *          an entitlement change or fresh provider data is visible at once;
 *          unchanged pages cost a 304. Public output is rendered for the
 *          anonymous audience only (no session-dependent content), so it
 *          carries no per-user state that a shared cache could leak.
 * Preview/Editor: `private, no-store` + `X-Robots-Tag: noindex, nofollow` —
 *          never stored by any cache, never indexed, never given an ETag.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Cache;

use Slate\Module\StudioBuilder\Render\RenderMode;

final class StudioCachePolicy
{
    /**
     * Phase 9D: powerful browser features a Studio page never uses. Studio
     * output carries no script, iframe, embed or media (FieldSchema rejects
     * them) and the platform signature / language switcher need none of these,
     * so denying them costs nothing and closes them to injected third-party
     * content. Deliberately short: no `fullscreen`/`autoplay` (media) and no
     * directive any current Studio feature could depend on.
     */
    public const PERMISSIONS_POLICY = 'camera=(), microphone=(), geolocation=(), payment=(), usb=()';

    /**
     * @return array<string, string>
     */
    public static function headersFor(RenderMode $mode, ?string $etag = null): array
    {
        $headers = [
            'Content-Type'           => 'text/html; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($mode->isPrivate()) {
            return $headers + [
                'Cache-Control'   => 'private, no-store, max-age=0',
                'Pragma'          => 'no-cache',
                'X-Robots-Tag'    => 'noindex, nofollow',
                'Referrer-Policy' => 'no-referrer',
            ];
        }

        $headers += [
            'Cache-Control'      => 'public, no-cache',
            'Referrer-Policy'    => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => self::PERMISSIONS_POLICY,
        ];
        if ($etag !== null && preg_match('/^[a-f0-9]{64}$/', $etag) === 1) {
            $headers['ETag'] = '"' . $etag . '"';
        }
        return $headers;
    }
}
