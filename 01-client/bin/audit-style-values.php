<?php
/**
 * Kohevo Studio — audit stored documents for style values the typed guard refuses.
 *
 * Read-only. Lists every block whose `style` carries a free-form value
 * (`url(...)`, `@import`, `var(...)`, an unknown function, ...) that
 * `StyleValueGuard` no longer accepts. Such a value is already NOT written to
 * the page (the renderer re-checks), but saving the document fails validation
 * until the value is reset, so run this before deploying B2-P3a.
 *
 * Usage:
 *   php bin/audit-style-values.php [--tenant=ID] [--all-revisions]
 *
 * By default only the newest revision of each page is scanned.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from CLI: php bin/audit-style-values.php\n");
    exit(1);
}

define('SLATE_ALLOW_LIVE_DB', 1);
require_once __DIR__ . '/../config.php';

use Slate\Data\Database;
use Slate\Module\StudioBuilder\Document\StyleValueGuard;

$tenant = null;
$all    = false;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--tenant=')) {
        $tenant = (int) substr($arg, 9);
    }
    if ($arg === '--all-revisions') {
        $all = true;
    }
}

$sql = 'SELECT r.id, r.tenant_id, r.page_id, r.revision_number, r.document_json FROM studiobuilder_revisions r';
$where = [];
$params = [];
if ($tenant !== null) {
    $where[]  = 'r.tenant_id = ?';
    $params[] = $tenant;
}
if (!$all) {
    $where[] = 'r.revision_number = (SELECT MAX(x.revision_number) FROM studiobuilder_revisions x WHERE x.page_id = r.page_id AND x.tenant_id = r.tenant_id)';
}
if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY r.tenant_id, r.page_id, r.revision_number';

/** @param array<string,mixed> $node */
$walk = static function (array $node, string $path, array &$found) use (&$walk): void {
    if (isset($node['style']) && is_array($node['style'])) {
        foreach (StyleValueGuard::styleIssues($node['style']) as $prop => $value) {
            $found[] = [$path . '.style.' . $prop, $value];
        }
    }
    foreach (['blocks', 'children'] as $key) {
        foreach (is_array($node[$key] ?? null) ? $node[$key] : [] as $i => $child) {
            if (is_array($child)) {
                $walk($child, $path . '.' . $key . '[' . $i . ']' . (isset($child['id']) ? '(' . $child['id'] . ')' : ''), $found);
            }
        }
    }
};

$scanned = 0;
$flagged = 0;
foreach (Database::rows($sql, $params) as $row) {
    $scanned++;
    $doc = json_decode((string) $row['document_json'], true);
    if (!is_array($doc)) {
        continue;
    }
    $found = [];
    foreach (is_array($doc['sections'] ?? null) ? $doc['sections'] : [] as $i => $section) {
        if (is_array($section)) {
            $walk($section, 'sections[' . $i . ']', $found);
        }
    }
    if ($found === []) {
        continue;
    }
    $flagged++;
    printf("tenant %d page %d revision %d (id %d)\n", $row['tenant_id'], $row['page_id'], $row['revision_number'], $row['id']);
    foreach ($found as [$where, $value]) {
        printf("  %s = %s\n", $where, strlen($value) > 120 ? substr($value, 0, 117) . '...' : $value);
    }
}

printf("\nScanned %d revision(s); %d with refused style values.\n", $scanned, $flagged);
exit($flagged > 0 ? 2 : 0);
