<?php
/**
 * Kohevo Studio (studio-builder) — Tenant Code & Analytics Policy.
 *
 * Governs the ONLY two pieces of tenant-authored configuration Studio emits
 * into a public page, both written by `admin/code-tracking.php`:
 *
 *   1. `studio_code_custom_css` — a global stylesheet, emitted in <head>.
 *   2. GA4 / GTM container IDs — converted here into Google's own fixed
 *      snippets. The tenant never supplies the snippet, only a validated ID,
 *      so no tenant-authored markup reaches the page through these fields.
 *
 * Raw `<script>` injection (head/footer) is DELIBERATELY NOT IMPLEMENTED.
 * The admin screen stores `studio_code_head` / `studio_code_footer`, but this
 * policy never reads them: executing tenant-authored JavaScript inside a
 * licensed commercial product is a privilege escalation that needs its own
 * permission, its own audit trail, and a sandbox story. Until that exists the
 * fields are inert by construction rather than by a flag someone can flip.
 *
 * ── The three rules that make this safe ──────────────────────────────────
 *
 * (a) NEVER in the Editor canvas. `StudioCanvasPolicy` pins the canvas to
 *     `script-src 'none'`; emitting anything script-shaped there would either
 *     break that contract or invite a future bypass. Custom CSS is withheld
 *     too, so the canvas shows the tenant's real published design.
 *
 * (b) NEVER analytics outside Public. Preview is an authenticated authoring
 *     surface — firing GA4/GTM from it would contaminate the tenant's real
 *     analytics with editor traffic.
 *
 * (c) The platform signature is emitted AFTER the tenant stylesheet and its
 *     protection rules are declared with `!important`. CSS resolves equal
 *     specificity by document order, so a tenant writing
 *     `.sb-platform-signature{display:none!important}` still loses to the
 *     later platform block. This keeps the identity guarantee in
 *     `PageDocumentAssembler` true even when custom CSS exists.
 *
 * Every read is best-effort: a database problem must never break a page.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Http;

use Slate\Module\StudioBuilder\Render\RenderMode;

final class StudioCodePolicy
{
    public const SETTING_CUSTOM_CSS = 'studio_code_custom_css';
    public const SETTING_GA4        = 'studio_code_ga4_id';
    public const SETTING_GTM        = 'studio_code_gtm_id';

    /** Stored by the admin screen, deliberately never rendered. */
    public const DEFERRED_SETTINGS = ['studio_code_head', 'studio_code_footer'];

    /** Tenant stylesheet ceiling (64 KiB) — a stylesheet is not a file host. */
    public const MAX_CUSTOM_CSS_BYTES = 65536;

    private const GA4_PATTERN = '/^G-[A-Z0-9]{4,24}$/';
    private const GTM_PATTERN = '/^GTM-[A-Z0-9]{4,12}$/';

    /**
     * Normalize a GA4 measurement ID, or null when absent/malformed.
     * Uppercased and trimmed; anything outside the documented shape is
     * dropped rather than interpolated into a Google-owned URL.
     */
    public static function ga4Id(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $id = strtoupper(trim($raw));
        return preg_match(self::GA4_PATTERN, $id) === 1 ? $id : null;
    }

    /** Normalize a GTM container ID, or null. Same contract as `ga4Id()`. */
    public static function gtmId(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $id = strtoupper(trim($raw));
        return preg_match(self::GTM_PATTERN, $id) === 1 ? $id : null;
    }

    /**
     * Reduce a tenant stylesheet to plain cascade rules.
     *
     * This is deliberately a REDUCE, not a full CSS parser: the goal is to
     * remove constructs that turn a stylesheet into an execution or
     * exfiltration vector, while leaving every legitimate cascade intact.
     *
     * Removed: `</style` (early tag break-out), comments (so a payload cannot
     * hide a keyword behind one), `@import` (arbitrary remote fetch from a
     * page that should be self-contained), the legacy IE/Gecko script hooks
     * (`expression()`, `behavior`, `-moz-binding`), and `javascript:` /
     * `vbscript:` / `data:text/html` URL schemes.
     *
     * CSS is not a security boundary against a hostile tenant — it is against
     * an ACCIDENTAL one, plus the identity guarantee in (c) above. A tenant
     * with the code permission is trusted to style their own site.
     */
    public static function sanitizeCustomCss(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        // Comments first: no later pattern may be evadable by splitting a
        // keyword across a comment.
        $css = preg_replace('~/\*.*?\*/~s', '', $raw) ?? '';
        $css = str_ireplace('</style', '', $css);

        // Remove whole tag constructs. CSS has no legitimate use for `<`/`>`,
        // so anything shaped like `<tag ...>` is an attempt to close this
        // <style> element and start another — stripping `</style` alone would
        // leave a dangling `<script>` behind, which is exactly the sort of
        // half-measure that turns into a bypass later.
        $css = preg_replace('~<[^>]*>?~', '', $css) ?? '';

        // Legacy dynamic-property and binding hooks.
        $css = preg_replace('~expression\s*\(~i', 'void(', $css) ?? $css;
        $css = preg_replace('~(?<![-\w])(?:-moz-)?binding\s*:~i', 'blocked:', $css) ?? $css;
        $css = preg_replace('~(?<![-\w])(?:-ms-)?behaviou?r\s*:~i', 'blocked:', $css) ?? $css;

        // Remote fetch from a page that should be self-contained.
        $css = preg_replace('~@import\b[^;]*;?~i', '', $css) ?? $css;

        // Script-bearing URL schemes, in url() and bare.
        $css = preg_replace('~\b(?:javascript|vbscript)\s*:~i', 'blocked:', $css) ?? $css;
        $css = preg_replace('~data\s*:\s*text/html~i', 'blocked:', $css) ?? $css;

        // Residual control characters.
        $css = preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~', '', $css) ?? $css;

        $css = trim($css);

        if (strlen($css) > self::MAX_CUSTOM_CSS_BYTES) {
            $css = substr($css, 0, self::MAX_CUSTOM_CSS_BYTES);
        }

        return $css;
    }

    /**
     * The tenant stylesheet for this tenant, sanitized. Never throws.
     */
    public static function customCss(int $tenantId): string
    {
        return self::sanitizeCustomCss((string) (self::setting(self::SETTING_CUSTOM_CSS, $tenantId) ?? ''));
    }

    /**
     * Markup for <head>. Returns '' in Editor (rule (a)) and whenever nothing
     * is configured, so the common case emits nothing at all.
     */
    public static function headMarkup(int $tenantId, RenderMode $mode): string
    {
        if ($mode !== RenderMode::Public) {
            return '';
        }

        $out = '';
        $ga4 = self::ga4Id(self::setting(self::SETTING_GA4, $tenantId));
        if ($ga4 !== null) {
            $out .= self::ga4Snippet($ga4);
        }
        $gtm = self::gtmId(self::setting(self::SETTING_GTM, $tenantId));
        if ($gtm !== null) {
            $out .= self::gtmHeadSnippet($gtm);
        }

        return $out;
    }

    /** Markup for the end of <body> — the GTM container iframe, Public only. */
    public static function bodyMarkup(int $tenantId, RenderMode $mode): string
    {
        if ($mode !== RenderMode::Public) {
            return '';
        }
        $gtm = self::gtmId(self::setting(self::SETTING_GTM, $tenantId));
        return $gtm !== null ? self::gtmBodySnippet($gtm) : '';
    }

    /**
     * The tenant stylesheet block for <head>, or '' when none is configured.
     * Emitted BEFORE the signature guard so the platform always wins the
     * cascade (rule (c)).
     */
    public static function customCssMarkup(int $tenantId, RenderMode $mode): string
    {
        if ($mode === RenderMode::Editor) {
            return '';
        }
        $css = self::customCss($tenantId);
        return $css === '' ? '' : '<style data-sb="tenant-css">' . $css . '</style>';
    }

    /**
     * Platform rules that re-assert the signature AFTER tenant CSS is parsed.
     * Same specificity, later in document order, `!important` — so a tenant
     * cannot hide or restyle the Kohevo identity with custom CSS.
     */
    public static function signatureProtectionCss(): string
    {
        return '<style data-sb="platform-signature-guard">'
            . '.sb-platform-signature{display:block!important;visibility:visible!important;'
            . 'opacity:1!important;position:static!important;transform:none!important;'
            . 'filter:none!important;clip-path:none!important;width:auto!important;'
            . 'height:auto!important;overflow:visible!important;float:none!important;'
            . 'margin:0!important;padding:0!important;border:0!important;'
            . 'content-visibility:visible!important;z-index:auto!important;'
            . 'text-indent:0!important;letter-spacing:normal!important;line-height:normal!important;'
            . 'font-size:inherit!important;color:inherit!important;background:none!important;'
            . 'text-decoration:none!important;}'
            . '</style>';
    }

    /** Google's fixed GA4 snippet. Only a validated measurement ID varies. */
    private static function ga4Snippet(string $id): string
    {
        return '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $id . '"></script>'
            . '<script>window.dataLayer=window.dataLayer||[];'
            . 'function gtag(){dataLayer.push(arguments);}'
            . "gtag('js',new Date());gtag('config','" . $id . "');</script>";
    }

    private static function gtmHeadSnippet(string $id): string
    {
        return "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':"
            . 'new Date().getTime(),event:\'gtm.js\'});var f=d.getElementsByTagName(s)[0],'
            . "j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;"
            . "j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;"
            . "f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','"
            . $id . "');</script>";
    }

    private static function gtmBodySnippet(string $id): string
    {
        return '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id='
            . $id . '" height="0" width="0" style="display:none;visibility:hidden"'
            . ' title="Google Tag Manager"></iframe></noscript>';
    }

    /** Best-effort tenant setting read. Any failure is treated as "unset". */
    private static function setting(string $key, int $tenantId): mixed
    {
        try {
            if (!\class_exists('Database')) {
                return null;
            }
            return \Database::setting($key, $tenantId);
        } catch (\Throwable $ignored) {
            // A settings outage must degrade the page, never fail it.
            return null;
        }
    }

    private function __construct() {}
}