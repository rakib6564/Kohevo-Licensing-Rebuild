<?php
/**
 * Slate — Template Library JSON export.
 *
 * Route: /admin/template-export.php?id=<document_templates.id>   (a saved template)
 *        /admin/template-export.php?page=<content_pages.id>      (a page's current working draft)
 *
 * Streams the schema-1 document as a downloadable .json file — the counterpart
 * to admin/templates.php's Import form, which re-validates and re-imports
 * exactly this shape via DocumentValidator.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

use Slate\Presentation\DocumentValidator;
use Slate\Services\Content\ContentServiceFactory;

Auth::require();
Auth::requirePerm('content.view');

$name = 'template';
$document = null;

if (!empty($_GET['page'])) {
    $pageId = (int) $_GET['page'];
    $page = ContentServiceFactory::pageRepository()->find($pageId);
    if (!$page) {
        http_response_code(404);
        die('Page not found.');
    }
    $working = ContentServiceFactory::revisionStore()->working(
        \Slate\Services\Content\ContentPageRepository::OWNER_TYPE,
        $pageId,
    );
    if ($working === null) {
        http_response_code(404);
        die('This page has no saved draft yet.');
    }
    $document = \Slate\Services\Content\RevisionStore::documentOf($working);
    $name = (string) ($page['title'] ?? 'page');
} elseif (!empty($_GET['id'])) {
    $row = ContentServiceFactory::templateRepository()->find((int) $_GET['id']);
    if (!$row) {
        http_response_code(404);
        die('Template not found.');
    }
    $document = ContentServiceFactory::templateRepository()->documentOf($row);
    $name = (string) ($row['name'] ?? 'template');
} else {
    http_response_code(400);
    die('Missing id or page parameter.');
}

if ($document === null) {
    http_response_code(500);
    die('Template document could not be read.');
}

// Re-validate on the way out too — never export a shape the importer would reject.
$validated = DocumentValidator::validate($document, ContentServiceFactory::validatorRegistry());

$slug = preg_replace('/[^a-z0-9-]+/', '-', strtolower($name)) ?: 'template';
$payload = [
    'slate_template_export' => 1,
    'name' => $name,
    'exported_at' => gmdate('c'),
    'document' => $validated['valid'] ? $validated['document'] : $document,
];

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $slug . '.json"');
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
