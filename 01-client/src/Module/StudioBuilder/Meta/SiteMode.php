<?php
/**
 * Kohevo Studio — the site's public mode: live, Coming Soon (HTTP 200) or Maintenance (HTTP 503), who gets past it, and
 * which published template visitors see instead of the site.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Meta;

use Slate\Data\Database;

final class SiteMode
{
    public const MODE = 'studio_site_mode';
    public const ACCESS = 'studio_site_mode_access';
    public const TEMPLATE = 'studio_site_mode_template';

    public const ALL = [self::MODE, self::ACCESS, self::TEMPLATE];

    public const OFF = 'off';
    public const COMING_SOON = 'coming_soon';
    public const MAINTENANCE = 'maintenance';

    /** @return array{mode: string, access: string, template: int} */
    public static function read(int $tenantId): array
    {
        try {
            $mode = (string) Database::setting(self::MODE, $tenantId);
            $access = (string) Database::setting(self::ACCESS, $tenantId);
            $template = (int) Database::setting(self::TEMPLATE, $tenantId);
        } catch (\Throwable) {
            return ['mode' => self::OFF, 'access' => 'logged_in', 'template' => 0];
        }
        return [
            'mode' => in_array($mode, [self::COMING_SOON, self::MAINTENANCE], true) ? $mode : self::OFF,
            'access' => $access === 'admins' ? 'admins' : 'logged_in',
            'template' => max(0, $template),
        ];
    }
}
