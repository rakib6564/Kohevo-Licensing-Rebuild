<?php
/**
 * Kohevo Studio (studio-builder) — Security policy for the builder canvas document.
 *
 * The canvas is the Phase 4 `renderForEditor()` output shown inside the
 * builder in a SAME-ORIGIN iframe with `sandbox="allow-same-origin"` (no
 * `allow-scripts`, `allow-forms`, `allow-popups` or `allow-top-navigation`).
 * The builder (parent) reads `data-sb-node` / `data-sb-type` from the frame's
 * DOM for selection; the framed document itself can run nothing.
 *
 * This response policy is defence in depth behind the sandbox and the
 * server-side sanitization of every authored value:
 *  - `script-src 'none'`   no script executes even if markup were ever smuggled in
 *  - `form-action 'none'`  nothing can be submitted from the canvas
 *  - `frame-ancestors 'self'` + SAMEORIGIN  only the builder may frame it
 *  - `base-uri 'none'`, `object-src 'none'`
 *  - private, no-store, noindex (inherited from the Editor render mode)
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Http;

use Slate\Module\StudioBuilder\Render\RenderResult;

final class StudioCanvasPolicy
{
    public const CONTENT_SECURITY_POLICY = "default-src 'self'; script-src 'none'; object-src 'none'; base-uri 'none'; "
        . "form-action 'none'; frame-ancestors 'self'; img-src 'self' https: data:; style-src 'self' 'unsafe-inline'; "
        . "font-src 'self' https: data:; connect-src 'none'";

    /** The iframe sandbox the builder must use for the canvas. */
    public const IFRAME_SANDBOX = 'allow-same-origin';

    /**
     * @return array<string, string>
     */
    public static function headers(RenderResult $result): array
    {
        return array_merge($result->headers, [
            'Content-Security-Policy' => self::CONTENT_SECURITY_POLICY,
            'X-Frame-Options'         => 'SAMEORIGIN',
            'Cache-Control'           => 'private, no-store, max-age=0',
            'X-Robots-Tag'            => 'noindex, nofollow',
        ]);
    }
}
