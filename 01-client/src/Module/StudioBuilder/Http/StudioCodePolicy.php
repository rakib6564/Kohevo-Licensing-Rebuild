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
 * Head and footer snippets (Search Console / Bing / Meta verification, ad pixels, any vendor tag) ARE
 * supported, under conditions that came with the decision to ship them:
 *
 *   - They live in NEW settings (`studio_code_head_snippet`, `studio_code_footer_snippet`). The legacy
 *     `studio_code_head` / `studio_code_footer` values were stored by an old screen that no renderer ever read;
 *     they are still never read, so nothing a tenant once typed there can suddenly start to run.
 *   - Only administrators (`studio-builder.admin`) may change them, and every save is audited with sizes and a
 *     SHA-256 of each snippet (never its content).
 *   - Public pages only: never the canvas, never Preview, like analytics.
 *   - A snippet is a short list of vendor tags, not a page: only `script`, `noscript`, `style`, `link`, `meta`
 *     (head) and those plus `img` / `iframe` (footer) are accepted, script and style bodies are scanned as raw
 *     text, and anything that could close or reshape the document (`</head>`, `<body>`, `<base>`, `<form>` …)
 *     is refused with a reason. This is a guard against accidents, not a boundary against an administrator.
 *   - Verification meta tags (Google, Bing, Meta, Pinterest) are structured fields: only the token is stored,
 *     so no markup at all comes from them.
 *
 * ── The three rules that make this safe ──────────────────────────────────
 *
 * (a) NO SCRIPTS in the Editor canvas. `StudioCanvasPolicy` pins the canvas to
 *     `script-src 'none'`; emitting anything script-shaped there would either
 *     break that contract or invite a future bypass. Scripts, analytics and
 *     arbitrary head markup stay out. What the canvas DOES get is what makes
 *     it look like the published page: the tenant stylesheet and the font
 *     `<link>`s of the head snippet (https, an allowlist of font hosts only),
 *     followed by a small guard so tenant CSS cannot stop the author from
 *     selecting blocks or scrolling.
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

    /** Legacy values of a screen that no renderer ever read. Still never read, so old content cannot start to run. */
    public const DEFERRED_SETTINGS = ['studio_code_head', 'studio_code_footer'];

    /** Tenant stylesheet ceiling (64 KiB) — a stylesheet is not a file host. */
    public const MAX_CUSTOM_CSS_BYTES = 65536;

    public const SETTING_HEAD_SNIPPET   = 'studio_code_head_snippet';
    public const SETTING_FOOTER_SNIPPET = 'studio_code_footer_snippet';
    public const SETTING_VERIFICATION   = 'studio_code_site_verification';

    /** Search-engine and platform ownership meta tags: service key => meta name. */
    public const VERIFICATION_SERVICES = [
        'google'    => 'google-site-verification',
        'bing'      => 'msvalidate.01',
        'facebook'  => 'facebook-domain-verification',
        'pinterest' => 'p:domain_verify',
    ];

    /** One snippet (32 KiB) is far more than any vendor tag; a larger one is a file, not a snippet. */
    public const MAX_SNIPPET_BYTES = 32768;

    private const VERIFICATION_TOKEN_PATTERN = '/^[A-Za-z0-9_\-]{8,128}$/';

    /** Tags a snippet may contain, by placement. */
    private const HEAD_TAGS   = ['script', 'noscript', 'style', 'link', 'meta'];
    private const FOOTER_TAGS = ['script', 'noscript', 'style', 'link', 'meta', 'img', 'iframe'];
    /** Elements whose content is raw text (not scanned for tags) and which must be closed. */
    private const RAW_TEXT_TAGS = ['script', 'style'];
    private const VOID_TAGS = ['link', 'meta', 'img'];

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
        $css = preg_replace('~@import\s+url\([^)]+\)\s*;?|@import\b[^;]*;?~i', '', $css) ?? $css;

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
     * Prepare a stylesheet an administrator typed in the builder: refuse one over the ceiling (cutting it at a byte
     * boundary could leave a half rule, so it is rejected instead) and otherwise reduce it. `changed` tells the
     * author that something was removed, so the editor can show the stylesheet that was really stored.
     *
     * @return array{css: string, bytes: int, changed: bool}
     * @throws \InvalidArgumentException when the input is longer than MAX_CUSTOM_CSS_BYTES
     */
    public static function prepareCustomCss(string $raw): array
    {
        if (strlen($raw) > self::MAX_CUSTOM_CSS_BYTES) {
            throw new \InvalidArgumentException('The stylesheet is larger than ' . self::MAX_CUSTOM_CSS_BYTES . ' bytes.');
        }
        $css = self::sanitizeCustomCss($raw);
        return ['css' => $css, 'bytes' => strlen($css), 'changed' => $css !== trim($raw)];
    }

    /**
     * The ownership token from what an administrator pasted: the bare token or the whole `<meta ...>` tag the
     * service shows. '' means "nothing"; null means "not a token" (the screen then refuses the save).
     */
    public static function verificationToken(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('~content\s*=\s*(?:"([^"]*)"|\'([^\']*)\')~i', $raw, $m) === 1) {
            $raw = trim($m[1] !== '' ? $m[1] : ($m[2] ?? ''));
        }
        return preg_match(self::VERIFICATION_TOKEN_PATTERN, $raw) === 1 ? $raw : null;
    }

    /**
     * Why a snippet is refused, or null when it is acceptable. `$placement` is 'head' or 'footer'.
     * Reasons: too_large, control_characters, tag:NAME (a tag outside the allowed list), unclosed:NAME, stray_close:NAME.
     */
    public static function snippetProblem(string $html, string $placement): ?string
    {
        if (strlen($html) > self::MAX_SNIPPET_BYTES) {
            return 'too_large';
        }
        if (preg_match('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~', $html) === 1) {
            return 'control_characters';
        }
        $allowed = $placement === 'footer' ? self::FOOTER_TAGS : self::HEAD_TAGS;
        $pos = 0;
        $len = strlen($html);
        $open = [];
        while ($pos < $len && ($lt = strpos($html, '<', $pos)) !== false) {
            if (substr($html, $lt, 4) === '<!--') {
                $end = strpos($html, '-->', $lt + 4);
                if ($end === false) {
                    return 'unclosed:comment';
                }
                $pos = $end + 3;
                continue;
            }
            if (preg_match('~\G<(/?)([A-Za-z][A-Za-z0-9-]*)~', $html, $m, 0, $lt) !== 1) {
                // A bare `<` that is not a tag (text such as "a < b", or `<!doctype`, `<?php`).
                if (preg_match('~\G<[!?]~', $html, $m2, 0, $lt) === 1) {
                    return 'tag:' . substr($html, $lt, 2);
                }
                $pos = $lt + 1;
                continue;
            }
            $closing = $m[1] === '/';
            $name = strtolower($m[2]);
            if (!in_array($name, $allowed, true)) {
                return 'tag:' . $name;
            }
            // Find the end of the tag, honouring quoted attribute values.
            if (preg_match('~\G(?:[^>"\']|"[^"]*"|\'[^\']*\')*>~', $html, $tag, 0, $lt + strlen($m[0])) !== 1) {
                return 'unclosed:' . $name;
            }
            $after = $lt + strlen($m[0]) + strlen($tag[0]);
            if ($closing) {
                $last = array_pop($open);
                if ($last !== $name) {
                    return 'stray_close:' . $name;
                }
                $pos = $after;
                continue;
            }
            $selfClosed = str_ends_with($tag[0], '/>');
            if (in_array($name, self::RAW_TEXT_TAGS, true) && !$selfClosed) {
                if (preg_match('~</' . $name . '\s*>~i', $html, $c, PREG_OFFSET_CAPTURE, $after) !== 1) {
                    return 'unclosed:' . $name;
                }
                $pos = $c[0][1] + strlen($c[0][0]);
                continue;
            }
            if (!in_array($name, self::VOID_TAGS, true) && !$selfClosed) {
                $open[] = $name;
            }
            $pos = $after;
        }
        return $open === [] ? null : 'unclosed:' . end($open);
    }

    /**
     * A snippet ready to store: trimmed, or a refusal with the reason as the exception message.
     *
     * @throws \InvalidArgumentException
     */
    public static function prepareSnippet(string $raw, string $placement): string
    {
        $html = trim($raw);
        $problem = self::snippetProblem($html, $placement);
        if ($problem !== null) {
            throw new \InvalidArgumentException($problem);
        }
        return $html;
    }

    /** `<meta>` ownership tags for the services this tenant verified. Public only; tokens are re-validated here. */
    public static function verificationMarkup(int $tenantId, RenderMode $mode): string
    {
        if ($mode !== RenderMode::Public) {
            return '';
        }
        $raw = self::setting(self::SETTING_VERIFICATION, $tenantId);
        $stored = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($stored)) {
            return '';
        }
        $out = '';
        foreach (self::VERIFICATION_SERVICES as $service => $metaName) {
            $token = is_string($stored[$service] ?? null) ? $stored[$service] : '';
            if ($token !== '' && preg_match(self::VERIFICATION_TOKEN_PATTERN, $token) === 1) {
                $out .= '<meta name="' . $metaName . '" content="' . $token . '">';
            }
        }
        return $out;
    }

    /** A stored snippet, re-checked at render time: one that fails (edited in the database) is simply not emitted. */
    private static function snippet(int $tenantId, string $key, string $placement): string
    {
        $raw = self::setting($key, $tenantId);
        if (!is_string($raw) || trim($raw) === '') {
            return '';
        }
        $html = trim($raw);
        return self::snippetProblem($html, $placement) === null ? $html : '';
    }

    /**
     * The tenant stylesheet for this tenant, sanitized. Never throws.
     */
    public static function customCss(int $tenantId): string
    {
        return self::sanitizeCustomCss((string) (self::setting(self::SETTING_CUSTOM_CSS, $tenantId) ?? ''));
    }

    /** Hosts whose stylesheets the Editor canvas may load (also listed in StudioCanvasPolicy's `style-src`). */
    public const EDITOR_FONT_HOSTS = ['fonts.googleapis.com', 'fonts.bunny.net', 'use.typekit.net', 'p.typekit.net', 'fonts.gstatic.com'];

    /**
     * The font `<link>`s of the head snippet, rebuilt from their parts: `rel` stylesheet / preconnect /
     * dns-prefetch, an https `href` on an allowed font host. Anything else in the snippet (scripts,
     * styles, other links, other attributes) is dropped, so the canvas gets the site's fonts and nothing else.
     */
    public static function editorFontLinks(int $tenantId): string
    {
        return self::fontLinksFrom(self::snippet($tenantId, self::SETTING_HEAD_SNIPPET, 'head'));
    }

    /** Pure core of editorFontLinks(): the allowed font links of one head snippet. */
    public static function fontLinksFrom(string $html): string
    {
        if ($html === '' || !preg_match_all('/<link\b[^>]*>/i', $html, $tags)) {
            return '';
        }
        $out = '';
        foreach ($tags[0] as $tag) {
            if (!preg_match('/\brel\s*=\s*["\']?(stylesheet|preconnect|dns-prefetch)\b/i', $tag, $rel)) {
                continue;
            }
            if (!preg_match('/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tag, $href)) {
                continue;
            }
            $url = html_entity_decode($href[1] !== '' ? $href[1] : ($href[2] ?? ''), ENT_QUOTES);
            $parts = parse_url($url);
            if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !in_array(strtolower((string) ($parts['host'] ?? '')), self::EDITOR_FONT_HOSTS, true)) {
                continue;
            }
            $kind = strtolower($rel[1]);
            $out .= '<link rel="' . $kind . '" href="' . htmlspecialchars($url, ENT_QUOTES) . '"' . ($kind === 'stylesheet' ? '' : ' crossorigin') . '>';
        }
        return $out;
    }

    /** Declared after the tenant stylesheet in the Editor canvas: tenant CSS must not block selecting or scrolling. */
    public static function editorGuardCss(): string
    {
        return '<style data-sb="editor-guard">html,body,[data-sb-node]{pointer-events:auto!important}'
            . 'html{overflow-y:auto!important}body{overflow:visible!important}</style>';
    }

    /**
     * Markup for <head>. In Editor only the font links (rule (a)); '' whenever nothing is
     * configured, so the common case emits nothing at all.
     */
    public static function headMarkup(int $tenantId, RenderMode $mode): string
    {
        if ($mode === RenderMode::Editor) {
            return self::editorFontLinks($tenantId);
        }
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

        return $out . self::verificationMarkup($tenantId, $mode) . self::snippet($tenantId, self::SETTING_HEAD_SNIPPET, 'head');
    }

    /** Markup for the end of <body> — the GTM container iframe, Public only. */
    public static function bodyMarkup(int $tenantId, RenderMode $mode): string
    {
        if ($mode !== RenderMode::Public) {
            return '';
        }
        $gtm = self::gtmId(self::setting(self::SETTING_GTM, $tenantId));
        return ($gtm !== null ? self::gtmBodySnippet($gtm) : '') . self::snippet($tenantId, self::SETTING_FOOTER_SNIPPET, 'footer');
    }

    /**
     * The tenant stylesheet block for <head>, or '' when none is configured.
     * Emitted BEFORE the signature guard so the platform always wins the
     * cascade (rule (c)).
     */
    public static function customCssMarkup(int $tenantId, RenderMode $mode): string
    {
        $css = self::customCss($tenantId);
        if ($css === '') {
            return '';
        }
        return '<style data-sb="tenant-css">' . $css . '</style>' . ($mode === RenderMode::Editor ? self::editorGuardCss() : '');
    }

    /**
     * Platform rules that re-assert the signature AFTER tenant CSS is parsed.
     * Same specificity, later in document order, `!important` — so a tenant
     * cannot hide or restyle the Kohevo identity with custom CSS.
     */
    /**
     * Stacking level of the signature. Above every value an author can set
     * (`CanonicalDocumentSchema::Z_INDEX_MAX`), below the platform's own
     * overlays (modal/offcanvas/lightbox), which are transient and ours.
     */
    public const SIGNATURE_Z_INDEX = 1000;

    public static function signatureProtectionCss(): string
    {
        return '<style data-sb="platform-signature-guard">'
            . '.sb-platform-signature{display:block!important;visibility:visible!important;'
            . 'opacity:1!important;position:relative!important;top:auto!important;left:auto!important;right:auto!important;bottom:auto!important;inset:auto!important;transform:none!important;translate:none!important;rotate:none!important;scale:none!important;mix-blend-mode:normal!important;pointer-events:auto!important;isolation:auto!important;'
            . 'filter:none!important;clip-path:none!important;width:auto!important;'
            . 'height:auto!important;overflow:visible!important;float:none!important;'
            . 'margin:0!important;padding:0!important;border:0!important;'
            . 'content-visibility:visible!important;z-index:' . self::SIGNATURE_Z_INDEX . '!important;'
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