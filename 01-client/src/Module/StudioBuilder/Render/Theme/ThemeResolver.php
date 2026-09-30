<?php
/**
 * Kohevo Studio (studio-builder) — Theme / Design-Token Resolver.
 *
 * Resolves the symbolic tokens a document references into sanitized CSS
 * values, in three layers (later layers win):
 *
 *   1. Studio neutral defaults (DEFAULT_TOKENS) — so every core token a
 *      default document uses always resolves.
 *   2. Tenant branding (`TenantBranding::resolve()` — accent, ink, canvas,
 *      heading/body fonts), mapped onto Studio tokens.
 *   3. The tenant's stored Studio token set for the document's
 *      `settings.token_group` (`studiobuilder_tokens.tokens_json`, falling back
 *      to the `default` group), read through the tenant-scoped TokenRepository.
 *
 * Stored token format (Phase 4 read contract — no writer exists yet; the
 * design-token UI is Phase 6): `tokens_json` is a flat JSON object of
 * `token ref => CSS value`, e.g. `{"surface.primary":"#ffffff"}`. Unknown refs
 * and unsafe values are dropped, never emitted. `compiled_css_vars` is NOT
 * trusted or emitted raw; the CSS is always regenerated from sanitized values.
 *
 * Platform identity boundary: this resolver never reads or writes
 * `PlatformIdentity`, and no token can reach the platform signature slot —
 * tenant tokens style tenant content only.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Theme;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Repository\TokenRepository;
use Slate\Services\Content\TenantBranding;

final class ThemeResolver
{
    public const DEFAULT_GROUP = 'default';

    /** Neutral Studio defaults — deliberately NOT Kohevo platform branding. */
    public const DEFAULT_TOKENS = [
        'border.default'    => '#e7e5e4',
        'color.accent'      => '#1f2937',
        'font.body'         => 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
        'font.heading'      => 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
        'radius.full'       => '9999px',
        'radius.lg'         => '16px',
        'radius.md'         => '8px',
        'radius.sm'         => '4px',
        'shadow.md'         => '0 4px 12px rgba(0,0,0,0.08)',
        'shadow.sm'         => '0 1px 2px rgba(0,0,0,0.06)',
        'space.lg'          => '3rem',
        'space.md'          => '1.5rem',
        'space.sm'          => '0.75rem',
        'surface.accent'    => '#1f2937',
        'surface.inverse'   => '#111111',
        'surface.muted'     => '#f5f5f4',
        'surface.page'      => '#ffffff',
        'surface.primary'   => '#ffffff',
        'surface.secondary' => '#f5f5f4',
        'text.accent'       => '#1f2937',
        'text.inverse'      => '#ffffff',
        'text.muted'        => '#57534e',
        'text.primary'      => '#111111',
    ];

    /** TenantBranding::resolve() key => Studio token refs it feeds. */
    public const BRANDING_TOKEN_MAP = [
        'accent'  => ['color.accent', 'surface.accent', 'text.accent'],
        'ink'     => ['text.primary'],
        'pageBg'  => ['surface.page'],
        'heading' => ['font.heading'],
        'body'    => ['font.body'],
    ];

    private const COLOR_PATTERN = '/^(#([0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})|(rgb|rgba|hsl|hsla)\(\s*[0-9.%\s,\/]{1,60}\)|transparent|currentColor)$/';
    private const LENGTH        = '(0|-?[0-9]{1,4}(\.[0-9]{1,3})?(px|rem|em|%)?)';
    private const FONT_PATTERN  = '/^[A-Za-z0-9 ,\'"-]{1,200}$/';

    public function __construct(
        private readonly ?TokenRepository $tokens = null,
        private readonly ?\Closure $brandingSource = null,
    ) {}

    public function resolve(string $tokenGroup = self::DEFAULT_GROUP): ResolvedTheme
    {
        if (preg_match(CanonicalDocumentSchema::TOKEN_GROUP_PATTERN, $tokenGroup) !== 1) {
            $tokenGroup = self::DEFAULT_GROUP;
        }
        $resolved = self::DEFAULT_TOKENS;

        foreach ($this->brandingValues() as $brandKey => $value) {
            foreach (self::BRANDING_TOKEN_MAP[$brandKey] ?? [] as $ref) {
                $clean = self::sanitizeValue($ref, $value);
                if ($clean !== null) {
                    $resolved[$ref] = $clean;
                }
            }
        }

        foreach ($this->storedTokens($tokenGroup) as $ref => $value) {
            $clean = self::sanitizeValue($ref, $value);
            if ($clean !== null) {
                $resolved[$ref] = $clean;
            }
        }

        // Deliberately not memoized: one resolver may serve several tenants in a
        // process (cron, tests), and every input below is tenant-scoped.
        return new ResolvedTheme($tokenGroup, $resolved);
    }

    /**
     * The three layers separately (each already sanitized), for the Phase 6
     * design-token UI: neutral defaults, the tenant's branding settings mapped
     * onto tokens, and the stored Studio overrides of `$tokenGroup`. Same
     * inputs and same sanitization as `resolve()`; later layers win there.
     *
     * @return array{group: string, defaults: array<string, string>, branding: array<string, string>, stored: array<string, string>}
     */
    public function layers(string $tokenGroup = self::DEFAULT_GROUP): array
    {
        if (preg_match(CanonicalDocumentSchema::TOKEN_GROUP_PATTERN, $tokenGroup) !== 1) {
            $tokenGroup = self::DEFAULT_GROUP;
        }
        $branding = [];
        foreach ($this->brandingValues() as $brandKey => $value) {
            foreach (self::BRANDING_TOKEN_MAP[$brandKey] ?? [] as $ref) {
                $clean = self::sanitizeValue($ref, $value);
                if ($clean !== null) {
                    $branding[$ref] = $clean;
                }
            }
        }
        $stored = [];
        foreach ($this->storedTokens($tokenGroup) as $ref => $value) {
            $clean = is_string($ref) ? self::sanitizeValue($ref, $value) : null;
            if ($clean !== null) {
                $stored[$ref] = $clean;
            }
        }
        ksort($branding, SORT_STRING);
        ksort($stored, SORT_STRING);
        return ['group' => $tokenGroup, 'defaults' => self::DEFAULT_TOKENS, 'branding' => $branding, 'stored' => $stored];
    }

    /**
     * Return a CSS-safe value for a token, or null. Values are validated per
     * token category; nothing that could close a declaration, a rule, or the
     * surrounding <style> element (`;{}<>\`, comments, url(), expressions) can
     * pass any of these patterns.
     */
    public static function sanitizeValue(string $ref, mixed $value): ?string
    {
        if (!is_string($value) || preg_match(CanonicalDocumentSchema::TOKEN_REF_PATTERN, $ref) !== 1) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > 200) {
            return null;
        }

        $category = strstr($ref, '.', true);
        $ok = match ($category) {
            'color', 'surface', 'text', 'border' => preg_match(self::COLOR_PATTERN, $value) === 1,
            'space', 'radius' => preg_match('/^' . self::LENGTH . '(\s+' . self::LENGTH . '){0,3}$/', $value) === 1,
            'shadow' => $value === 'none' || preg_match(
                '/^(' . self::LENGTH . '\s+){2,4}(#[0-9a-fA-F]{3,8}|rgba?\([0-9.%\s,]{1,60}\))$/',
                $value
            ) === 1,
            'font' => preg_match(self::FONT_PATTERN, $value) === 1,
            default => false,
        };

        return $ok ? $value : null;
    }

    /** @return array<string, string> */
    private function brandingValues(): array
    {
        try {
            $values = $this->brandingSource !== null
                ? ($this->brandingSource)()
                : (class_exists(TenantBranding::class) ? TenantBranding::resolve() : []);
        } catch (\Throwable $ignored) {
            return [];
        }
        $out = [];
        foreach ((array) $values as $k => $v) {
            if (is_string($k) && is_string($v) && trim($v) !== '') {
                $out[$k] = trim($v);
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function storedTokens(string $tokenGroup): array
    {
        if ($this->tokens === null) {
            return [];
        }
        try {
            $row = $this->tokens->findByGroupKey($tokenGroup);
            if ($row === null && $tokenGroup !== self::DEFAULT_GROUP) {
                $row = $this->tokens->findByGroupKey(self::DEFAULT_GROUP);
            }
        } catch (\Throwable $ignored) {
            return [];
        }
        if ($row === null) {
            return [];
        }
        $decoded = json_decode((string) ($row['tokens_json'] ?? ''), true, 8);
        return (is_array($decoded) && !array_is_list($decoded)) ? $decoded : [];
    }
}
