<?php
/**
 * Kohevo Studio (studio-builder) — whole-site backup and restore.
 *
 * An application-layer helper OWNED by StudioApplicationService, like the package helper: it runs only after the
 * pipeline there (tenant -> authentication -> entitlement -> permission), and does no authorization or audit of its own.
 * The file format is `Backup/StudioBackupFormat`.
 *
 * Restoring goes through the canonical services, never raw writes to `document_json`: each page's content arrives as a new
 * `import` revision, so a restore shows up in the page's history and can be rolled back there, and a live page goes
 * through the real publish (validation, compile, forms sync, homepage claim).
 *
 * What a restore does, and does not do:
 *   - A page is matched by uuid, then by address. A match is updated, an archived match comes back, the rest are created.
 *     A new page keeps the backup's uuid, because other documents (Global Components, header and footer partials) name
 *     pages by uuid: that one choice is why nothing needs remapping when a site is moved.
 *   - Pages that are not in the backup are left alone. A restore adds and replaces; it never deletes.
 *   - A page that is live now but was a draft in the backup stays live: Studio has no unpublish, so this is reported.
 *   - Media ids differ between sites, so references go through the file path. Existing files are never overwritten: a
 *     path already holding a different file is reported and skipped, and the blocks using it lose that image.
 *   - One page that cannot be restored is reported and the rest continue (a module this site lacks, a block it does not
 *     know). The preview names them first.
 *   - Settings and tokens replace what is there, and apply at once: they have no draft. Snippets and custom CSS pass the
 *     same checks as the Tracking & code screen, so a tampered file cannot store what that screen would refuse.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Application;

use Slate\Data\Database;
use Slate\Module\StudioBuilder\Backup\StudioBackupFormat;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Document\DocumentUpgrader;
use Slate\Module\StudioBuilder\Document\ValidatedDocument;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Http\StudioCodePolicy;
use Slate\Module\StudioBuilder\Menu\StudioMenus;
use Slate\Module\StudioBuilder\Meta\PageMeta;
use Slate\Module\StudioBuilder\Package\PackageDocumentMapper;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Repository\PageRepository;
use Slate\Module\StudioBuilder\Repository\RevisionRepository;
use Slate\Module\StudioBuilder\Repository\TemplateRepository;
use Slate\Module\StudioBuilder\Service\StudioPageAddressService;
use Slate\Module\StudioBuilder\Service\StudioRevisionService;
use Slate\Module\StudioBuilder\Service\StudioTemplateService;
use Slate\Module\StudioBuilder\Service\StudioThemeService;
use Slate\Services\Media\Media;

final class StudioBackupService
{
    /** Restored first, because other documents name them by uuid. */
    private const SHARED_TYPES = [CanonicalDocumentSchema::COMPONENT_DOCUMENT_TYPE, 'header_partial', 'footer_partial'];

    /** Read back as JSON by their screens. */
    private const JSON_SETTINGS = [StudioMenus::SETTING_KEY, 'studio_redirects', 'studio_disabled_blocks', 'studio_custom_post_types', 'studio_meta_fields', StudioCodePolicy::SETTING_VERIFICATION];

    public function __construct(
        private readonly PageRepository $pageRepo,
        private readonly RevisionRepository $revisionRepo,
        private readonly TemplateRepository $templateRepo,
        private readonly StudioPageAddressService $pages,
        private readonly StudioRevisionService $revisions,
        private readonly StudioTemplateService $templates,
        private readonly ?StudioThemeService $themes,
        private readonly BlockRegistry $registry,
    ) {}

    // ── Backup ──────────────────────────────────────────────────────────────

    /**
     * Write a backup of the current tenant to `$path`. The zip is built under a temporary name and moved into place, so a
     * failed run never leaves a file that looks like a backup.
     *
     * @return array{pages: int, templates: int, token_groups: int, settings: int, media: int, files: int}
     */
    public function write(int $tenantId, string $path, bool $withMedia, string $site): array
    {
        $partial = $path . '.part';
        $zip = new \ZipArchive();
        if ($zip->open($partial, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new \RuntimeException('The backup file could not be created.');
        }
        try {
            $counts = ['pages' => 0, 'templates' => 0, 'token_groups' => 0, 'settings' => 0, 'media' => 0, 'files' => 0];

            foreach ($this->pageRepo->allForBackup() as $row) {
                $counts['pages']++;
                $zip->addFromString(StudioBackupFormat::pageEntry($counts['pages']), self::json($this->pageRecord($row, $tenantId)));
            }

            $templates = [];
            foreach ($this->templateRepo->allCustom() as $row) {
                $templates[] = [
                    'template_key' => (string) $row['template_key'], 'template_type' => (string) $row['template_type'],
                    'category' => (string) ($row['category'] ?? 'general'), 'name' => (string) $row['name'],
                    'description' => isset($row['description']) && $row['description'] !== '' ? (string) $row['description'] : null,
                    'document' => $this->upgraded(CanonicalJson::decode((string) $row['document_json'])),
                ];
            }
            $counts['templates'] = count($templates);
            $zip->addFromString(StudioBackupFormat::ENTRY_TEMPLATES, self::json($templates));

            $tokens = [];
            foreach ($this->tokenGroups() as $group) {
                $stored = [];
                foreach ($this->themes?->layers($group)['tokens'] ?? [] as $token) {
                    if ($token['stored'] !== null) {
                        $stored[(string) $token['ref']] = (string) $token['stored'];
                    }
                }
                if ($stored !== []) {
                    $tokens[$group] = $stored;
                }
            }
            $counts['token_groups'] = count($tokens);
            $zip->addFromString(StudioBackupFormat::ENTRY_TOKENS, self::json((object) $tokens));

            $settings = [];
            foreach (StudioBackupFormat::SETTING_KEYS as $key) {
                $value = Database::setting($key, $tenantId);
                if ($value !== null && $value !== false && $value !== '') {
                    $settings[$key] = (string) $value;
                }
            }
            $counts['settings'] = count($settings);
            $zip->addFromString(StudioBackupFormat::ENTRY_SETTINGS, self::json((object) $settings));

            $media = [];
            foreach (Database::rows('SELECT * FROM media_files WHERE tenant_id = ? ORDER BY id ASC', [$tenantId]) as $row) {
                $mediaPath = (string) $row['path'];
                if (!Media::isManagedPath($mediaPath)) {
                    continue;
                }
                $abs = SLATE_ROOT . $mediaPath;
                $exists = is_file($abs);
                $media[] = [
                    'id' => (int) $row['id'], 'path' => $mediaPath, 'kind' => (string) $row['kind'], 'mime' => (string) $row['mime'],
                    'alt_text' => (string) ($row['alt_text'] ?? ''), 'title' => (string) ($row['title'] ?? ''),
                    'description' => (string) ($row['description'] ?? ''), 'original_name' => (string) ($row['original_name'] ?? ''),
                    'sha256' => $exists ? (string) hash_file('sha256', $abs) : '',
                ];
                if ($withMedia && $exists) {
                    $entry = StudioBackupFormat::fileEntry($mediaPath);
                    $zip->addFile($abs, $entry);
                    $zip->setCompressionName($entry, \ZipArchive::CM_STORE); // images and video are compressed already
                    $counts['files']++;
                }
            }
            $counts['media'] = count($media);
            $zip->addFromString(StudioBackupFormat::ENTRY_MEDIA, self::json($media));

            $zip->addFromString(StudioBackupFormat::ENTRY_MANIFEST, self::json([
                'format' => StudioBackupFormat::FORMAT, 'version' => StudioBackupFormat::VERSION,
                'created_at' => gmdate('Y-m-d\TH:i:s\Z'), 'site' => $site, 'with_media' => $withMedia, 'counts' => $counts,
            ]));
            if (!$zip->close()) {
                throw new \RuntimeException('The backup file could not be finished.');
            }
        } catch (\Throwable $e) {
            @$zip->close();
            @unlink($partial);
            throw $e;
        }
        if (!rename($partial, $path)) {
            @unlink($partial);
            throw new \RuntimeException('The backup file could not be saved.');
        }
        return $counts;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function pageRecord(array $row, int $tenantId): array
    {
        $id = (int) $row['id'];
        $working = $this->revisionDocument($id, (int) ($row['active_draft_revision_id'] ?? 0)) ?? CanonicalDocumentSchema::emptyDocument((string) $row['page_type']);
        $published = $this->revisionDocument($id, (int) ($row['published_revision_id'] ?? 0));
        $meta = Database::setting(PageMeta::SETTING_PREFIX . $id, $tenantId);
        return [
            'id' => $id, 'uuid' => (string) $row['uuid'], 'title' => (string) $row['title'], 'slug' => (string) $row['slug'],
            'page_type' => (string) $row['page_type'], 'status' => (string) $row['status'], 'route_mode' => (string) $row['route_mode'],
            'working' => $working, 'published' => $published,
            'meta' => is_string($meta) && $meta !== '' ? $meta : null,
        ];
    }

    /** @return ?array<string, mixed> a stored revision at this site's block versions, or null */
    private function revisionDocument(int $pageId, int $revisionId): ?array
    {
        $revision = $revisionId > 0 ? $this->revisionRepo->findByIdForPage($pageId, $revisionId) : null;
        return $revision !== null ? $this->upgraded(CanonicalJson::decode((string) $revision['document_json'])) : null;
    }

    /** @param array<string, mixed> $document @return array<string, mixed> */
    private function upgraded(array $document): array
    {
        return DocumentUpgrader::upgrade($document, $this->registry)['document'];
    }

    // ── Reading a backup ────────────────────────────────────────────────────

    /** @return \ZipArchive an opened backup. @throws StudioValidationException when it is not one */
    public function open(string $path): \ZipArchive
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw self::invalid('This file is not a zip archive.');
        }
        return $zip;
    }

    /** @return array<string, mixed> */
    public function manifest(\ZipArchive $zip): array
    {
        $manifest = self::entry($zip, StudioBackupFormat::ENTRY_MANIFEST);
        $problems = StudioBackupFormat::manifestProblems($manifest);
        if ($problems !== []) {
            throw self::invalid($problems[0]);
        }
        return $manifest;
    }

    /**
     * What a restore would do, writing nothing.
     *
     * @param array<string, mixed> $validationOptions the application layer's tenant-scoped checks
     * @return array<string, mixed>
     */
    public function preview(\ZipArchive $zip, array $validationOptions): array
    {
        $manifest = $this->manifest($zip);
        $records = $this->pageRecords($zip);
        $media = $this->mediaIndex($zip);
        $templates = self::entry($zip, StudioBackupFormat::ENTRY_TEMPLATES);
        $options = $this->withBackupReferences($validationOptions, $records, is_array($templates) ? $templates : []);
        $options['media_exists'] = static fn(int $id): bool => true; // files arrive with the restore, not before

        $report = ['manifest' => $manifest, 'new' => [], 'update' => [], 'blocked' => $records['blocked'], 'kept' => [], 'still_live' => [],
            'templates' => is_array($templates) ? count($templates) : 0, 'media' => ['existing' => 0, 'new' => 0, 'missing' => 0, 'conflict' => 0],
            'settings' => 0, 'token_groups' => 0];

        $inBackup = [];
        foreach ($records['pages'] as $page) {
            $row = $this->matchingPage($page);
            if ($row !== null) {
                $inBackup[(int) $row['id']] = true;
            }
            try {
                $this->validated((array) $page['working'], (string) $page['page_type'], $options);
                if ($page['published'] !== null) {
                    $this->validated((array) $page['published'], (string) $page['page_type'], $options);
                }
            } catch (StudioValidationException $e) {
                $report['blocked'][] = ['title' => (string) $page['title'], 'reason' => self::firstError($e)];
                continue;
            }
            $label = ['title' => (string) $page['title'], 'slug' => (string) $page['slug'], 'page_type' => (string) $page['page_type']];
            $report[$row === null ? 'new' : 'update'][] = $label;
            if ($row !== null && $page['published'] === null && ($row['status'] ?? '') === 'published') {
                $report['still_live'][] = $label;
            }
        }
        foreach ($this->pageRepo->allForBackup() as $row) {
            if (!isset($inBackup[(int) $row['id']])) {
                $report['kept'][] = ['title' => (string) $row['title'], 'slug' => (string) $row['slug'], 'page_type' => (string) $row['page_type']];
            }
        }
        foreach ($media as $row) {
            $report['media'][$this->mediaAction($zip, $row)['action']]++;
        }
        $settings = self::entry($zip, StudioBackupFormat::ENTRY_SETTINGS);
        $report['settings'] = is_array($settings) ? count(array_intersect_key($settings, array_flip(StudioBackupFormat::SETTING_KEYS))) : 0;
        $tokens = self::entry($zip, StudioBackupFormat::ENTRY_TOKENS);
        $report['token_groups'] = is_array($tokens) ? count($tokens) : 0;
        return $report;
    }

    // ── Restore ─────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $validationOptions
     * @param callable(int, int): array<string, mixed> $publish (page id, expected revision id) => the real publish
     * @param callable(int, array<string, string>): void $updateAddress (page id, changes) => the real address update
     * @return array<string, mixed> what happened, page by page where it matters
     */
    public function restore(\ZipArchive $zip, int $tenantId, int $actorId, array $validationOptions, callable $publish, callable $updateAddress): array
    {
        $this->manifest($zip);
        $records = $this->pageRecords($zip);
        $templates = self::entry($zip, StudioBackupFormat::ENTRY_TEMPLATES);
        $templates = is_array($templates) ? $templates : [];
        $options = $this->withBackupReferences($validationOptions, $records, $templates);

        $out = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'templates' => 0, 'token_groups' => [], 'settings' => 0,
            'media' => ['existing' => 0, 'new' => 0, 'missing' => 0, 'conflict' => 0],
            'failed' => $records['blocked'], 'warnings' => [], 'page_ids' => []];

        $mediaMap = $this->restoreMedia($zip, $tenantId, $out);
        $this->restoreSettings($zip, $tenantId, $mediaMap, $out);
        $this->restoreTokens($zip, $actorId, $out);

        $mapMedia = fn(array $document): array => PackageDocumentMapper::rewriteMedia($document, $this->registry, static fn(int $id): ?int => $mediaMap[$id] ?? null)['document'];

        $shared = array_filter($records['pages'], static fn(array $p): bool => in_array($p['page_type'], self::SHARED_TYPES, true));
        $others = array_filter($records['pages'], static fn(array $p): bool => !in_array($p['page_type'], self::SHARED_TYPES, true));
        foreach ($shared as $page) {
            $this->restorePage($page, $tenantId, $actorId, $options, $mapMedia, $publish, $updateAddress, $out);
        }
        foreach ($templates as $template) {
            $this->restoreTemplate($template, $actorId, $options, $mapMedia, $out);
        }
        foreach ($others as $page) {
            $this->restorePage($page, $tenantId, $actorId, $options, $mapMedia, $publish, $updateAddress, $out);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed> $options
     * @param array<string, mixed> $out
     */
    private function restorePage(array $page, int $tenantId, int $actorId, array $options, callable $mapMedia, callable $publish, callable $updateAddress, array &$out): void
    {
        $title = (string) $page['title'];
        try {
            $type = (string) $page['page_type'];
            $working = $this->validated($mapMedia((array) $page['working']), $type, $options);
            $published = $page['published'] !== null ? $this->validated($mapMedia((array) $page['published']), $type, $options) : null;

            $row = $this->matchingPage($page);
            $created = $row === null;
            $wasArchived = ($row['status'] ?? '') === 'archived';
            $row = $created ? $this->createPage($page, $actorId, $options) : $this->adoptPage($page, $row);
            $id = (int) $row['id'];
            $changed = $wasArchived;

            if ($published !== null && !$this->sameAsRevision($id, (int) ($row['published_revision_id'] ?? 0), $published)) {
                $revision = $this->revisions->createDraftRevision($id, $published, (int) $row['active_draft_revision_id'], $actorId, 'import', 'Restored from a backup');
                $publish($id, (int) $revision['revision']['id']);
                $row = $this->pageRepo->find($id) ?? $row;
                $changed = true;
            }
            if (!$this->sameAsRevision($id, (int) ($row['active_draft_revision_id'] ?? 0), $working)) {
                $this->revisions->createDraftRevision($id, $working, (int) $row['active_draft_revision_id'], $actorId, 'import', 'Restored from a backup');
                $changed = true;
            }
            if ($published === null && ($row['status'] ?? '') === 'published') {
                $out['warnings'][] = ['title' => $title, 'code' => 'still_live'];
            }

            $changed = $this->restoreAddress($page, $row, $updateAddress, $out) || $changed;
            if (is_string($page['meta'] ?? null)) {
                Database::setSetting(PageMeta::SETTING_PREFIX . $id, $page['meta'], $tenantId);
            }
            $out['page_ids'][] = $id;
            $out[$created ? 'created' : ($changed ? 'updated' : 'unchanged')]++;
        } catch (StudioValidationException $e) {
            $out['failed'][] = ['title' => $title, 'reason' => self::firstError($e)];
        } catch (\Throwable $e) {
            error_log('[studio-builder] restore of page ' . $title . ': ' . $e::class . ': ' . $e->getMessage());
            $out['failed'][] = ['title' => $title, 'reason' => $e->getMessage()];
        }
    }

    /** The page this record restores into; an archived match comes back first. @param array<string, mixed> $page @return ?array<string, mixed> */
    private function matchingPage(array $page): ?array
    {
        $row = $this->pageRepo->findByUuid((string) $page['uuid']);
        return $row ?? $this->pageRepo->findBySlug((string) $page['slug'], (string) $page['page_type']);
    }

    /** @param array<string, mixed> $page @param array<string, mixed> $options @return array<string, mixed> */
    private function createPage(array $page, int $actorId, array $options): array
    {
        // `homepage` is applied after the publish, when there is a live page to claim the role with.
        $routeMode = (string) $page['route_mode'] === 'homepage' ? 'standalone' : (string) $page['route_mode'];
        $created = $this->pages->createPage((string) $page['title'], (string) $page['slug'], (string) $page['page_type'], $routeMode, $actorId, $options, 'import');
        $id = (int) $created['page']['id'];
        $this->pageRepo->update($id, ['uuid' => (string) $page['uuid']]);
        return $this->pageRepo->find($id) ?? $created['page'];
    }

    /**
     * A page matched by address takes the backup's uuid, so references in the backup hold; an archived page comes back as
     * a draft, because a draft cannot be written to it otherwise.
     *
     * @param array<string, mixed> $page @param array<string, mixed> $row @return array<string, mixed>
     */
    private function adoptPage(array $page, array $row): array
    {
        $data = [];
        if ((string) $row['uuid'] !== (string) $page['uuid']) {
            $data['uuid'] = (string) $page['uuid'];
        }
        if (($row['status'] ?? '') === 'archived') {
            $data['status'] = 'draft';
        }
        if ($data === []) {
            return $row;
        }
        $this->pageRepo->update((int) $row['id'], $data);
        return $this->pageRepo->find((int) $row['id']) ?? $row;
    }

    /** Title, address and homepage role, put back through the real update. @param array<string, mixed> $page @param array<string, mixed> $row @param array<string, mixed> $out */
    private function restoreAddress(array $page, array $row, callable $updateAddress, array &$out): bool
    {
        $changes = [];
        foreach (['title', 'slug', 'route_mode'] as $key) {
            if ((string) $page[$key] !== (string) ($row[$key] ?? '')) {
                $changes[$key] = (string) $page[$key];
            }
        }
        if ($changes === []) {
            return false;
        }
        try {
            $updateAddress((int) $row['id'], $changes);
            return true;
        } catch (StudioValidationException $e) {
            $out['warnings'][] = ['title' => (string) $page['title'], 'code' => 'address_kept', 'detail' => self::firstError($e)];
            return false;
        }
    }

    /** @param array<string, mixed> $document */
    private function sameAsRevision(int $pageId, int $revisionId, ValidatedDocument $document): bool
    {
        $stored = $this->revisionDocument($pageId, $revisionId);
        return $stored !== null && CanonicalJson::encode($stored) === CanonicalJson::encode($document->toArray());
    }

    /**
     * @param array<string, mixed> $template @param array<string, mixed> $options @param array<string, mixed> $out
     */
    private function restoreTemplate(mixed $template, int $actorId, array $options, callable $mapMedia, array &$out): void
    {
        $key = is_array($template) ? (string) ($template['template_key'] ?? '') : '';
        try {
            if ($key === '' || !is_array($template['document'] ?? null)) {
                throw self::invalid('The template record is not valid.');
            }
            $existing = $this->templateRepo->findByKey($key);
            if ($existing !== null && (bool) $existing['is_system']) {
                return; // comes with the plugin; never overwritten
            }
            $this->templates->saveTemplate($key, DocumentUpgrader::typeName((string) ($template['template_type'] ?? '')), (string) ($template['category'] ?? 'general'), (string) ($template['name'] ?? ''),
                isset($template['description']) ? (string) $template['description'] : null, $mapMedia($template['document']), $actorId, $options, null, true);
            $out['templates']++;
        } catch (StudioValidationException $e) {
            $out['failed'][] = ['title' => (string) ($template['name'] ?? $key), 'reason' => self::firstError($e)];
        }
    }

    /** @param array<string, mixed> $out */
    private function restoreTokens(\ZipArchive $zip, int $actorId, array &$out): void
    {
        $tokens = self::entry($zip, StudioBackupFormat::ENTRY_TOKENS);
        if ($this->themes === null || !is_array($tokens)) {
            return;
        }
        foreach ($tokens as $group => $values) {
            try {
                $clean = $this->themes->validateTokens((string) $group, (array) $values);
                $this->themes->saveTokens((string) $group, $clean, $actorId);
                $out['token_groups'][] = (string) $group;
            } catch (StudioValidationException $e) {
                $out['warnings'][] = ['title' => (string) $group, 'code' => 'tokens_skipped', 'detail' => self::firstError($e)];
            }
        }
    }

    /** @param array<int, int> $mediaMap @param array<string, mixed> $out */
    private function restoreSettings(\ZipArchive $zip, int $tenantId, array $mediaMap, array &$out): void
    {
        $settings = self::entry($zip, StudioBackupFormat::ENTRY_SETTINGS);
        if (!is_array($settings)) {
            return;
        }
        foreach (StudioBackupFormat::SETTING_KEYS as $key) {
            if (!is_string($settings[$key] ?? null)) {
                continue;
            }
            try {
                $value = self::cleanSetting($key, $settings[$key], $mediaMap);
            } catch (\InvalidArgumentException $e) {
                $out['warnings'][] = ['title' => $key, 'code' => 'setting_skipped', 'detail' => $e->getMessage()];
                continue;
            }
            Database::setSetting($key, $value, $tenantId);
            $out['settings']++;
        }
        StudioMenus::forget();
    }

    /**
     * A setting as the screen that owns it would store it.
     *
     * @param array<int, int> $mediaMap
     * @throws \InvalidArgumentException when that screen would refuse the value
     */
    private static function cleanSetting(string $key, string $value, array $mediaMap): string
    {
        switch ($key) {
            case StudioCodePolicy::SETTING_CUSTOM_CSS:
                return StudioCodePolicy::sanitizeCustomCss($value);
            case StudioCodePolicy::SETTING_HEAD_SNIPPET:
                return StudioCodePolicy::prepareSnippet($value, 'head');
            case StudioCodePolicy::SETTING_FOOTER_SNIPPET:
                return StudioCodePolicy::prepareSnippet($value, 'footer');
            case StudioCodePolicy::SETTING_GA4:
                return StudioCodePolicy::ga4Id($value) ?? throw new \InvalidArgumentException('Not a GA4 id.');
            case StudioCodePolicy::SETTING_GTM:
                return StudioCodePolicy::gtmId($value) ?? throw new \InvalidArgumentException('Not a GTM id.');
            case StudioBackupFormat::SETTING_OG_MEDIA:
                return (string) ($mediaMap[(int) $value] ?? '');
        }
        if (in_array($key, self::JSON_SETTINGS, true) && !json_validate($value)) {
            throw new \InvalidArgumentException('Not valid JSON.');
        }
        return $value;
    }

    // ── Media ───────────────────────────────────────────────────────────────

    /**
     * Bring the library up to the backup's. @param array<string, mixed> $out
     *
     * @return array<int, int> source media id => this site's media id
     */
    private function restoreMedia(\ZipArchive $zip, int $tenantId, array &$out): array
    {
        Media::ensureSchema(); // a site that has not opened its library yet may lack the alt text columns
        $map = [];
        foreach ($this->mediaIndex($zip) as $row) {
            $plan = $this->mediaAction($zip, $row);
            $path = (string) $row['path'];
            $id = $plan['id'];
            if ($plan['action'] === 'new' && !$plan['on_disk']) {
                try {
                    $this->extractFile($zip, $row);
                } catch (\RuntimeException $e) {
                    $out['media']['conflict']++;
                    $out['warnings'][] = ['title' => $path, 'code' => 'media_skipped', 'detail' => $e->getMessage()];
                    continue;
                }
            }
            if ($plan['action'] === 'new') {
                $abs = SLATE_ROOT . $path;
                $size = @getimagesize($abs);
                $id = Media::register($path, [
                    'mime' => (string) (mime_content_type($abs) ?: $row['mime']), 'size_bytes' => (int) filesize($abs),
                    'width' => is_array($size) ? (int) $size[0] : null, 'height' => is_array($size) ? (int) $size[1] : null,
                    'original_name' => (string) ($row['original_name'] ?? basename($path)),
                ]);
                if ($id > 0) {
                    Media::updateMeta($id, ['alt_text' => (string) ($row['alt_text'] ?? ''), 'title' => (string) ($row['title'] ?? ''), 'description' => (string) ($row['description'] ?? '')]);
                }
            }
            $out['media'][$plan['action']]++;
            if ($id > 0) {
                $map[(int) $row['id']] = $id;
            }
        }
        return $map;
    }

    /**
     * What happens to one media row: `existing` (this site has it), `new` (add it), `missing` (no file anywhere) or
     * `conflict` (another file already holds that path).
     *
     * @param array<string, mixed> $row
     * @return array{action: string, id: int, on_disk: bool}
     */
    private function mediaAction(\ZipArchive $zip, array $row): array
    {
        $path = (string) $row['path'];
        $existing = (int) Database::value('SELECT id FROM media_files WHERE tenant_id = ? AND path = ?', [current_tenant_id(), $path]);
        if ($existing > 0) {
            return ['action' => 'existing', 'id' => $existing, 'on_disk' => true];
        }
        $abs = SLATE_ROOT . $path;
        if (is_file($abs)) {
            $sha = (string) ($row['sha256'] ?? '');
            return ['action' => $sha !== '' && hash_equals($sha, (string) hash_file('sha256', $abs)) ? 'new' : 'conflict', 'id' => 0, 'on_disk' => true];
        }
        return ['action' => $zip->statName(StudioBackupFormat::fileEntry($path)) !== false ? 'new' : 'missing', 'id' => 0, 'on_disk' => false];
    }

    /**
     * Copy one file out of the zip to its managed path, checked like an upload: the hash the backup recorded, a type the
     * library allows judged from the bytes, an allowed extension, and the SVG sanitizer.
     *
     * @param array<string, mixed> $row
     * @throws \RuntimeException when the file is refused
     */
    private function extractFile(\ZipArchive $zip, array $row): void
    {
        $path = (string) $row['path'];
        $entry = StudioBackupFormat::fileEntry($path);
        $stat = $zip->statName($entry);
        if ($stat === false || (int) $stat['size'] > StudioBackupFormat::MAX_FILE_BYTES) {
            throw new \RuntimeException('The file is missing or too large.');
        }
        $abs = SLATE_ROOT . $path;
        $dir = dirname($abs);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('The media folder could not be created.');
        }
        $in = $zip->getStream($entry);
        $tmp = $abs . '.restore';
        $outFile = $in !== false ? fopen($tmp, 'wb') : false;
        if ($in === false || $outFile === false) {
            throw new \RuntimeException('The file could not be read.');
        }
        stream_copy_to_stream($in, $outFile);
        fclose($in);
        fclose($outFile);
        try {
            $sha = (string) ($row['sha256'] ?? '');
            if ($sha === '' || !hash_equals($sha, (string) hash_file('sha256', $tmp))) {
                throw new \RuntimeException('The file does not match the backup.');
            }
            $mime = (string) mime_content_type($tmp);
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($ext, Media::allowedExts(), true) || !in_array($mime, Media::allowedMimes(), true)) {
                throw new \RuntimeException('This file type is not allowed.');
            }
            if ($ext === 'svg' && !Media::sanitizeSvgFile($tmp)) {
                throw new \RuntimeException('This SVG is not safe.');
            }
            if (!rename($tmp, $abs)) {
                throw new \RuntimeException('The file could not be saved.');
            }
            @chmod($abs, 0644);
        } finally {
            @unlink($tmp);
        }
    }

    // ── Shared ──────────────────────────────────────────────────────────────

    /** @return array{pages: list<array<string, mixed>>, blocked: list<array{title: string, reason: string}>} */
    private function pageRecords(\ZipArchive $zip): array
    {
        $pages = [];
        $blocked = [];
        $total = (int) ($this->manifest($zip)['counts']['pages'] ?? 0);
        for ($n = 1; $n <= $total; $n++) {
            $name = StudioBackupFormat::pageEntry($n);
            $page = self::entry($zip, $name);
            if (is_array($page) && is_string($page['page_type'] ?? null)) {
                $page['page_type'] = DocumentUpgrader::typeName($page['page_type']);
            }
            $problem = StudioBackupFormat::pageProblem($page);
            if ($problem !== null) {
                $blocked[] = ['title' => $name, 'reason' => $problem];
                continue;
            }
            $pages[] = $page;
        }
        return ['pages' => $pages, 'blocked' => $blocked];
    }

    /** @return list<array<string, mixed>> the usable rows: a managed path, and an extension the library allows */
    private function mediaIndex(\ZipArchive $zip): array
    {
        $index = self::entry($zip, StudioBackupFormat::ENTRY_MEDIA);
        if (!is_array($index) || count($index) > StudioBackupFormat::MAX_MEDIA) {
            throw self::invalid('The media list of this backup is not valid.');
        }
        return array_values(array_filter($index, static fn(mixed $row): bool => StudioBackupFormat::mediaProblem($row) === null
            && Media::isManagedPath((string) $row['path'])
            && in_array(strtolower(pathinfo((string) $row['path'], PATHINFO_EXTENSION)), Media::allowedExts(), true)));
    }

    /**
     * References between items of the same backup pass validation, whether or not this site has them yet.
     *
     * @param array<string, mixed> $options
     * @param array{pages: list<array<string, mixed>>} $records
     * @param array<int|string, mixed> $templates
     * @return array<string, mixed>
     */
    private function withBackupReferences(array $options, array $records, array $templates): array
    {
        $keys = [];
        foreach ($templates as $template) {
            if (is_array($template) && is_string($template['template_key'] ?? null)) {
                $keys[$template['template_key']] = true;
            }
        }
        $uuids = [];
        foreach ($records['pages'] as $page) {
            $uuids[(string) $page['uuid']] = true;
        }
        $baseTemplate = $options['template_exists'] ?? null;
        $basePartial = $options['partial_exists'] ?? null;
        $options['template_exists'] = static fn(string $k): bool => isset($keys[$k]) || (is_callable($baseTemplate) && $baseTemplate($k));
        $options['partial_exists'] = static fn(string $r): bool => isset($uuids[$r]) || (is_callable($basePartial) && $basePartial($r));
        return $options;
    }

    /** @param array<string, mixed> $document @param array<string, mixed> $options */
    private function validated(array $document, string $pageType, array $options): ValidatedDocument
    {
        if ((DocumentUpgrader::structure($document)['document_type'] ?? null) !== $pageType) {
            throw self::invalid("The document is not a '{$pageType}' document.");
        }
        return ValidatedDocument::from($this->upgraded($document), $this->registry, $options);
    }

    /** @return list<string> */
    private function tokenGroups(): array
    {
        return array_values(array_unique(array_merge(['default'], $this->themes?->storedGroups() ?? [])));
    }

    /** @return mixed the decoded entry; null when it is absent */
    private static function entry(\ZipArchive $zip, string $name): mixed
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            return null;
        }
        if ((int) $stat['size'] > StudioBackupFormat::MAX_JSON_BYTES) {
            throw self::invalid("'{$name}' is too large.");
        }
        $raw = $zip->getFromName($name);
        try {
            return is_string($raw) ? json_decode($raw, true, 128, JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException) {
            throw self::invalid("'{$name}' is not readable.");
        }
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function invalid(string $message): StudioValidationException
    {
        return new StudioValidationException([['path' => '$', 'code' => 'invalid_backup', 'message' => $message]]);
    }

    private static function firstError(StudioValidationException $e): string
    {
        return (string) ($e->errors()[0]['message'] ?? $e->getMessage());
    }
}
