<?php
/**
 * Kohevo Studio (studio-builder) — The public site's one authoritative locale.
 *
 * Phase 9A: a public Studio page is single-language content, so its language
 * is the TENANT's configured site language (`default_language`, validated by
 * `I18n::siteLocale()`), never the visitor's. While pinned, core I18n ignores
 * `X-Slate-Force-Locale`, `?lang=` and the session, so everything the page
 * derives from the locale follows the tenant:
 *
 *   - `<html lang>` (SiteContext::fromEnvironment() -> I18n::currentLocale())
 *   - Studio-generated strings (Html::t() -> __())
 *   - the compile fingerprint (SiteContext::fingerprint()), so the ONE stored
 *     `published` artifact per page is compiled for the same locale on every
 *     request and at publish time — visitor locale changes cannot make it
 *     flip between variants.
 *
 * The rest of the application keeps its visitor locale: authoring renders
 * (preview/editor) and every non-Studio page never pin.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Runtime;

use Slate\Services\I18n\I18n;

final class StudioPublicLocale
{
    /** The tenant's public site locale — server-side settings only, never the request. */
    public static function resolve(): string
    {
        return I18n::siteLocale();
    }

    /**
     * Pin the public locale until restore(); returns the previous pin. The
     * HTTP adapter pins for the rest of a public Studio response, so output
     * filters that run after the render (e.g. multilang-translate's buffer)
     * see the same locale.
     */
    public static function pin(): ?string
    {
        return I18n::pinLocale(self::resolve());
    }

    public static function restore(?string $previous): void
    {
        I18n::pinLocale($previous);
    }

    /**
     * Run $fn with the public locale pinned, then restore the previous pin.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function run(callable $fn): mixed
    {
        $previous = self::pin();
        try {
            return $fn();
        } finally {
            self::restore($previous);
        }
    }
}
