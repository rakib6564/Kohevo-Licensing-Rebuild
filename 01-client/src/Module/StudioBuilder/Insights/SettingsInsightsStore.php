<?php
/**
 * Kohevo Studio — InsightsStore on the tenant's settings. None of the keys are in StudioBackupFormat::SETTING_KEYS, so a
 * backup never carries them; bin/rekey-secrets.php re-encrypts the sealed ones.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Insights;

use Slate\Data\Database;

final class SettingsInsightsStore implements InsightsStore
{
    public function __construct(private readonly int $tenantId)
    {
    }

    public function get(string $key): string
    {
        return (string) Database::setting($key, $this->tenantId);
    }

    public function set(string $key, string $value): void
    {
        Database::setSetting($key, $value, $this->tenantId);
    }
}
