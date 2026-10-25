<?php
/**
 * Kohevo Studio (studio-builder) — Security policy for the visitor preview document.
 *
 * The builder's "Preview as visitor" shows `admin/preview.php` in a same-origin iframe with
 * `sandbox="allow-same-origin allow-scripts"`: scripts must run (tabs, accordions, carousels behave as on the site)
 * and same-origin keeps the site's own web fonts loading. That combination lets a script in the frame reach the
 * builder, so the preview must never run a script the platform did not ship. The render already guarantees it (tenant
 * code snippets and analytics are Public only, authored HTML is sanitized); this response policy is defence in
 * depth behind that:
 *  - `script-src 'self'`      only the platform's own script files, never inline or third-party script
 *  - `object-src 'none'`, `base-uri 'self'`
 *  - `frame-ancestors 'self'` + SAMEORIGIN: only the builder may frame it
 * Images, styles, fonts and embedded frames (maps, video, forms) are left as on the public page.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Http;

use Slate\Module\StudioBuilder\Render\RenderResult;

final class StudioPreviewPolicy
{
    public const CONTENT_SECURITY_POLICY = "script-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'";

    /** @return array<string, string> */
    public static function headers(RenderResult $result): array
    {
        return array_merge($result->headers, [
            'Content-Security-Policy' => self::CONTENT_SECURITY_POLICY,
            'X-Frame-Options'         => 'SAMEORIGIN',
        ]);
    }
}
