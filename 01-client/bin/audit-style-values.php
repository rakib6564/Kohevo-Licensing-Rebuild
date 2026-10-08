<?php
/**
 * Kohevo Studio — audit stored documents for styling the current rules refuse.
 *
 * Read-only. Runs the real document validator over stored revisions and lists every STYLING error
 * (`style`, `style_states`, `tag`): a typed value the guard refuses (`url(...)`, `@import`, `var(...)`),
 * a z-index above 999, a background `fit`/`position` outside the allowed vocabulary, and so on.
 * A block that fails validation is rendered as "unavailable" (nothing, on a public page) and the
 * document cannot be saved until it is fixed, so run this before deploying B2-P3a/P3b.
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
use Slate\Module\StudioBuilder\Document\StyleAudit;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;

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

$registry = ModuleBlockDefinitions::studioRegistry();
$scanned = 0;
$flagged = 0;
foreach (Database::rows($sql, $params) as $row) {
    $scanned++;
    $doc = json_decode((string) $row['document_json'], true);
    if (!is_array($doc)) {
        continue;
    }
    $found = [];
    foreach (StyleAudit::issues($doc, $registry) as $issue) {
        $found[] = [$issue['path'], $issue['code'] . ': ' . $issue['message']];
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

printf("\nScanned %d revision(s); %d with styling the current rules refuse.\n", $scanned, $flagged);
exit($flagged > 0 ? 2 : 0);
