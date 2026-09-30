<?php
/**
 * Kohevo Studio (studio-builder) — Phase 8B HTML/CSS import: issue collector.
 *
 * The converter's findings, in the exact issue shape of the Phase 8A import
 * report (`StudioImportReport::addIssue()`), so an HTML import is reported
 * through the ONE report contract. Bounded: at most MAX_PER_CODE issues per
 * code are listed; the rest are summarized by one `issues_truncated` warning
 * per code. Paths are source locations (`html:L12 body>section[2]>p[1]`,
 * `css:rule[4]`) — bounded, deterministic, and never a filesystem path.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Package\Html;

final class HtmlImportIssues
{
    public const MAX_PER_CODE = 100;

    /** @var list<array{severity: string, code: string, item: string, path: string, message: string, detail?: string}> */
    private array $issues = [];

    /** @var array<string, int> */
    private array $perCode = [];

    /** @var array<string, int> */
    private array $truncated = [];

    private bool $errors = false;

    public function __construct(private readonly string $item) {}

    public function error(string $code, string $path, string $message, ?string $detail = null): void
    {
        $this->errors = true;
        $this->add('error', $code, $path, $message, $detail);
    }

    public function warning(string $code, string $path, string $message, ?string $detail = null): void
    {
        $this->add('warning', $code, $path, $message, $detail);
    }

    public function hasErrors(): bool
    {
        return $this->errors;
    }

    /** @return list<array<string, string>> */
    public function toList(): array
    {
        $out = $this->issues;
        ksort($this->truncated, SORT_STRING);
        foreach ($this->truncated as $code => $n) {
            $out[] = ['severity' => 'warning', 'code' => 'issues_truncated', 'item' => $this->item, 'path' => 'html:', 'message' => "{$n} more '{$code}' finding(s) were not listed.", 'detail' => $code];
        }
        return $out;
    }

    private function add(string $severity, string $code, string $path, string $message, ?string $detail): void
    {
        $count = $this->perCode[$code] ?? 0;
        $this->perCode[$code] = $count + 1;
        if ($count >= self::MAX_PER_CODE && $severity !== 'error') {
            $this->truncated[$code] = ($this->truncated[$code] ?? 0) + 1;
            return;
        }
        $issue = ['severity' => $severity, 'code' => $code, 'item' => $this->item, 'path' => mb_substr($path, 0, 200, 'UTF-8'), 'message' => $message];
        if ($detail !== null && $detail !== '') {
            $issue['detail'] = mb_substr($detail, 0, 64, 'UTF-8');
        }
        $this->issues[] = $issue;
    }
}
