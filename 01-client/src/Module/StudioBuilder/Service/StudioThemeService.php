<?php
/**
 * Kohevo Studio (studio-builder) — Design-token write path (Phase 6).
 *
 * The ONLY writer of `studiobuilder_tokens`. Reads and writes go through the
 * tenant-scoped TokenRepository; the values are the same three layers the
 * Phase 4 ThemeResolver already resolves at render time — and this service
 * changes nothing about that order:
 *
 *     neutral defaults  →  tenant branding (admin Settings › Branding)  →  stored Studio tokens
 *
 * What can be stored is deliberately narrow:
 *   - only the token refs the platform defines (ThemeResolver::DEFAULT_TOKENS
 *     keys) — no arbitrary custom properties;
 *   - only values that pass ThemeResolver::sanitizeValue() for the token's
 *     category (colour / length / shadow / font-family patterns). Anything
 *     that could close a declaration, a rule or a <style> element, or carry
 *     url()/expression(), is rejected on write (fail closed) AND dropped again
 *     on read (defence in depth).
 * There is no raw CSS field, no <style> input, and no way to address the
 * platform signature slot: tokens style tenant content (`--sb-*` custom
 * properties) only, and PlatformIdentity / PlatformSignature never read them.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Service;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Exception\StudioTenantScopeException;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Module\StudioBuilder\Repository\TokenRepository;
use Slate\Tenancy\TenantContext;

final class StudioThemeService
{
    public const MAX_TOKENS_PER_GROUP = 64;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly TokenRepository $tokens,
        private readonly ThemeResolver $themes,
    ) {}

    /** @return list<string> the token refs a tenant may override */
    public static function editableTokens(): array
    {
        return array_keys(ThemeResolver::DEFAULT_TOKENS);
    }

    /**
     * Every supported token with its value in each layer and the effective
     * result — the shape the builder's theme panel edits.
     *
     * @return array{group: string, tokens: list<array{ref: string, category: string, default: string, branding: ?string, stored: ?string, effective: string, source: string}>}
     */
    public function layers(string $tokenGroup = ThemeResolver::DEFAULT_GROUP): array
    {
        $this->requireTenantId();
        $group  = self::normalizeGroup($tokenGroup);
        $layers = $this->themes->layers($group);
        $out = [];
        foreach ($layers['defaults'] as $ref => $default) {
            $branding = $layers['branding'][$ref] ?? null;
            $stored   = $layers['stored'][$ref] ?? null;
            $effective = $stored ?? $branding ?? $default;
            $out[] = [
                'category'  => (string) strstr($ref, '.', true),
                'ref'       => $ref,
                'default'   => $default,
                'branding'  => $branding,
                'stored'    => $stored,
                'effective' => $effective,
                'source'    => $stored !== null ? 'studio' : ($branding !== null ? 'branding' : 'default'),
            ];
        }
        return ['group' => $layers['group'], 'tokens' => $out];
    }

    /**
     * Replace the tenant's stored overrides of one token group. `$tokens` is
     * the complete new map `ref => value | null` (null / '' = no override for
     * that ref). Invalid refs or values reject the whole write.
     *
     * @param array<string, mixed> $tokens
     * @return array{group: string, tokens: list<array<string, mixed>>}
     */
    public function saveTokens(string $tokenGroup, array $tokens, int $actorId): array
    {
        $this->requireTenantId();
        $clean = $this->validateTokens($tokenGroup, $tokens);

        $row = $this->tokens->findByGroupKey($tokenGroup);
        $data = [
            'schema_version'    => CanonicalDocumentSchema::SCHEMA_VERSION,
            'tokens_json'       => json_encode($clean === [] ? new \stdClass() : $clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'compiled_css_vars' => (new ResolvedTheme($tokenGroup, $clean))->rootCss(),
            'updated_by'        => $actorId > 0 ? $actorId : null,
        ];
        if ($row !== null) {
            $this->tokens->update((int) $row['id'], $data);
        } else {
            $this->tokens->insert(['token_group' => $tokenGroup] + $data);
        }

        return $this->layers($tokenGroup);
    }

    /**
     * Every rule `saveTokens()` applies before it writes — group identifier,
     * object shape, count limit, platform-defined refs only, per-category
     * value sanitization — without writing anything. Returns the clean,
     * sorted override map; throws StudioValidationException exactly as
     * `saveTokens()` would. (Phase 8A: the package import dry run.)
     *
     * @param array<string, mixed> $tokens
     * @return array<string, string>
     */
    public function validateTokens(string $tokenGroup, array $tokens): array
    {
        $this->requireTenantId();
        if (preg_match(CanonicalDocumentSchema::TOKEN_GROUP_PATTERN, $tokenGroup) !== 1) {
            throw new StudioValidationException([
                ['path' => '$.group', 'code' => 'invalid_token_group', 'message' => 'Invalid token group identifier.'],
            ]);
        }
        if ($tokens !== [] && array_is_list($tokens)) {
            throw new StudioValidationException([
                ['path' => '$.tokens', 'code' => 'invalid_tokens', 'message' => 'tokens must be a JSON object of token ref => value.'],
            ]);
        }
        if (count($tokens) > self::MAX_TOKENS_PER_GROUP) {
            throw new StudioValidationException([
                ['path' => '$.tokens', 'code' => 'too_many_tokens', 'message' => 'Too many tokens in one group.'],
            ]);
        }

        $editable = self::editableTokens();
        $errors = [];
        $clean  = [];
        foreach ($tokens as $ref => $value) {
            $ref = (string) $ref;
            if (!in_array($ref, $editable, true)) {
                $errors[] = ['path' => '$.tokens.' . $ref, 'code' => 'unknown_token', 'message' => "'{$ref}' is not a supported design token."];
                continue;
            }
            if ($value === null || $value === '') {
                continue; // no override
            }
            $safe = ThemeResolver::sanitizeValue($ref, $value);
            if ($safe === null) {
                $errors[] = ['path' => '$.tokens.' . $ref, 'code' => 'invalid_token_value', 'message' => "The value for '{$ref}' is not a valid " . strstr($ref, '.', true) . ' value.'];
                continue;
            }
            $clean[$ref] = $safe;
        }
        if ($errors !== []) {
            throw new StudioValidationException($errors);
        }
        ksort($clean, SORT_STRING);

        return $clean;
    }

    private static function normalizeGroup(string $group): string
    {
        return preg_match(CanonicalDocumentSchema::TOKEN_GROUP_PATTERN, $group) === 1 ? $group : ThemeResolver::DEFAULT_GROUP;
    }

    private function requireTenantId(): int
    {
        if (!$this->tenants->isScoped() || $this->tenants->id() <= 0) {
            throw new StudioTenantScopeException();
        }
        return $this->tenants->id();
    }
}
