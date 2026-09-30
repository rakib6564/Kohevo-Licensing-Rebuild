<?php
/**
 * Kohevo Studio (studio-builder) — Paths a Studio page may never claim.
 *
 * Studio only ever sees paths that no plugin prefix matched (it is the
 * PublicRouter's last-resort handler), but a slug must also never shadow a
 * system path or a module prefix that is merely INACTIVE right now (e.g. a
 * Studio page called `book` would otherwise start/stop existing as Booking is
 * toggled). Checked against this static list plus every currently registered
 * public prefix.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Runtime;

final class StudioReservedRoutes
{
    public const RESERVED = [
        '403', '404', '500',
        'account', 'admin', 'ai-assistant', 'api', 'assets', 'audit', 'bin', 'book', 'booking',
        'claude', 'coaching', 'config', 'cron', 'customer', 'data', 'db', 'db-backups', 'dev-server',
        'docs', 'forms', 'includes', 'index', 'install', 'lang', 'licensing', 'login', 'logout',
        'mcp', 'mcp-gateway', 'media', 'media-library', 'member', 'membership', 'multilang-translate',
        'plugins', 'preview', 'public', 'register', 'robots', 'route', 'shop', 'sitemap', 'src',
        'storefront', 'stripe-payment', 'studio', 'studio-builder', 'templates', 'tests', 'uploads',
        'well-known',
    ];

    public function __construct(private readonly ?\Closure $knownPrefixes = null) {}

    public function isReserved(string $slug): bool
    {
        if (in_array($slug, self::RESERVED, true)) {
            return true;
        }
        foreach ($this->prefixes() as $prefix) {
            $first = explode('/', trim((string) $prefix, '/'))[0] ?? '';
            if ($first !== '' && strtolower($first) === $slug) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private function prefixes(): array
    {
        try {
            if ($this->knownPrefixes !== null) {
                return array_values(array_map('strval', (array) ($this->knownPrefixes)()));
            }
            if (class_exists('PublicRouter')) {
                return array_values(array_map('strval', \PublicRouter::knownPrefixes()));
            }
        } catch (\Throwable $ignored) {
        }
        return [];
    }
}
