<?php
/**
 * Kohevo Studio (studio-builder) — Phase 8B: constrained HTML/CSS import converter.
 *
 * PURE: no database, no tenant lookup, no network, no filesystem, no audit.
 * It turns untrusted HTML + CSS into a SYNTHESIZED single-page Kohevo Studio
 * package (the Phase 8A format), which the application layer then hands to
 * the existing package planner/committer — the one import pipeline (media
 * resolution, id re-minting, validation, route checks, draft commit, audit).
 *
 *   capability check (DOM + libxml, else `html_import_unavailable`)
 *     -> raw guards (sizes, UTF-8, controls, `<` count, DOCTYPE subset)
 *     -> parse (loadHTML, LIBXML_NONET) + bounded security-filtering walk
 *     -> bounded CSS subset (<style> text + the `css` field, ≤ 128 KiB total)
 *     -> computed style evidence -> import IR (bounded) -> canonical document
 *     -> package { items: [ page 'html' ] }
 *
 * The tenant's RESOLVED theme (read-only) is passed in by the caller for exact
 * token matching. The result is deterministic for identical input and theme.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Package\Html;

use Slate\Module\StudioBuilder\Package\StudioPackageFormat;
use Slate\Module\StudioBuilder\Schema\FieldSchema;

final class HtmlImportConverter
{
    public const VERSION   = 1;
    public const ITEM_KEY  = 'html';
    /** HTML import creates ordinary pages only — never chrome partials or system pages. */
    public const PAGE_TYPES = ['page', 'landing'];
    public const DEFAULT_TITLE = 'Imported page';
    public const DEFAULT_SLUG  = 'imported-page';
    /** Fixed so the synthesized package (and its hash) is deterministic. */
    public const EXPORTED_AT = '1970-01-01T00:00:00Z';

    private \Closure $capable;

    public function __construct(?\Closure $capable = null)
    {
        $this->capable = $capable ?? static fn(): bool => self::runtimeSupported();
    }

    /** DOM + libxml present in THIS runtime (the web SAPI may differ from the CLI). */
    public static function runtimeSupported(): bool
    {
        return extension_loaded('dom') && extension_loaded('libxml') && class_exists(\DOMDocument::class)
            && defined('LIBXML_NONET') && function_exists('libxml_use_internal_errors');
    }

    /**
     * @param array{title?: ?string, slug?: ?string, page_type?: ?string} $meta
     * @param array<string, string> $themeTokens the tenant's resolved theme (ref => value), read-only
     * @return array{package: ?array<string, mixed>, package_hash: string, source_hash: string, issues: list<array<string, string>>, conversion: array<string, int>}
     */
    public function convert(string $html, string $css, array $meta, array $themeTokens): array
    {
        $issues = new HtmlImportIssues(self::ITEM_KEY);
        $sourceHash = hash('sha256', 'kohevo-html-import/' . self::VERSION . "\0" . $html . "\0" . $css);
        $conversion = ['converter_version' => self::VERSION];
        $result = static function (?array $package) use ($issues, $sourceHash, &$conversion): array {
            ksort($conversion, SORT_STRING);
            return [
                'package'      => $package,
                'package_hash' => $package !== null ? StudioPackageFormat::packageHash($package) : hash('sha256', ''),
                'source_hash'  => $sourceHash,
                'issues'       => $issues->toList(),
                'conversion'   => $conversion,
            ];
        };

        if (!($this->capable)()) {
            $issues->error('html_import_unavailable', 'html:', 'HTML import is not available on this server: the PHP dom and libxml extensions are required.');
            return $result(null);
        }
        if (!HtmlSourceReader::guard($html, $css, $issues)) {
            return $result(null);
        }
        $reader = new HtmlSourceReader($issues);
        $source = $reader->read($html);
        if ($source === null) {
            return $result(null);
        }
        $conversion += $source['stats'];

        $cssBytes = strlen($css) + array_sum(array_map('strlen', $source['styles']));
        if ($cssBytes > HtmlSourceReader::MAX_CSS_BYTES) {
            $issues->error('source_too_large', 'css:', 'The CSS (including <style> elements) is larger than 128 KiB.');
            return $result(null);
        }
        $parser = new HtmlCssParser($issues);
        foreach ([...$source['styles'], $css] as $sheet) {
            if (!$parser->parseSheet($sheet)) {
                $conversion += $parser->stats();
                return $result(null);
            }
        }

        $mapper = new HtmlStructureMapper($source, $parser, new HtmlCssValues($themeTokens), $issues, $source['body']);
        if (!$mapper->computeStyles()) {
            return $result(null);
        }
        $sections = $mapper->sections();
        $conversion += $parser->stats();
        $problem = HtmlImportIr::check($sections);
        if ($problem !== null) {
            $issues->error('source_limit_exceeded', 'html:', $problem, 'structure');
            return $result(null);
        }

        $pageType = in_array($meta['page_type'] ?? 'page', self::PAGE_TYPES, true) ? (string) ($meta['page_type'] ?? 'page') : 'page';
        $title = $this->title($meta['title'] ?? null, $source['title'], $issues);
        $out = $mapper->document($sections, $pageType, $title);
        $conversion += $mapper->stats() + ['sections_out' => count($sections)];
        if ($out === null) {
            return $result(null);
        }

        $slug = is_string($meta['slug'] ?? null) && $meta['slug'] !== '' ? (string) $meta['slug'] : self::DEFAULT_SLUG;
        $item = [
            'kind'       => StudioPackageFormat::KIND_PAGE,
            'key'        => self::ITEM_KEY,
            'title'      => $title,
            'slug'       => $slug,
            'page_type'  => $pageType,
            'route_mode' => 'standalone',
            'document'   => $out['document'],
            'media'      => $out['media'],
        ];
        $item['content_hash'] = StudioPackageFormat::itemHash($item);
        $package = [
            'package_format'  => StudioPackageFormat::FORMAT,
            'package_version' => StudioPackageFormat::VERSION,
            'exported_at'     => self::EXPORTED_AT,
            'items'           => [$item],
        ];
        return $result($package);
    }

    /** A plain, safe page title: the caller's, else the document's <title>, else a default. */
    private function title(?string $given, ?string $fromDocument, HtmlImportIssues $issues): string
    {
        foreach ([$given, $fromDocument] as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $clean = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[<>\x00-\x1F\x7F]/', '', $candidate)));
            $clean = mb_substr($clean, 0, 255, 'UTF-8');
            if ($clean === '') {
                continue;
            }
            if (FieldSchema::containsExecutableOrSqlFragment($clean)) {
                $issues->warning('unsafe_text_dropped', 'html:', 'The page title was rejected by the content safety check; a default title was used.', 'title');
                continue;
            }
            return $clean;
        }
        return self::DEFAULT_TITLE;
    }
}
