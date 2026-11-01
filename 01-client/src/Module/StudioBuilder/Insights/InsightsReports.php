<?php
/**
 * Kohevo Studio — the figures on the Site Insights screen. Each method asks Google once per cache period and answers in a
 * plain shape the panels draw, so nothing above this class knows Google's response formats.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Insights;

final class InsightsReports
{
    private const TTL = 3600;
    private const SPEED_TTL = 21600; // PageSpeed is slow and its figures move slowly
    private const TOP_ROWS = 10;

    /**
     * @param string $site     the Search Console property, '' when none is chosen
     * @param string $property the Analytics property (`properties/123`), '' when none is chosen
     * @param string $path     one page's path when the screen is filtered to it, '' for the whole site
     */
    public function __construct(
        private readonly GoogleClient $google,
        private readonly InsightsCache $cache,
        private readonly string $site,
        private readonly string $property,
        private readonly InsightsRange $range,
        private readonly string $path = '',
        private readonly string $apiKey = '',
    ) {
    }

    /**
     * The address behind a Search Console property; the site's own address for what it cannot tell.
     */
    public static function publicBase(string $site, string $fallback): string
    {
        if (str_starts_with($site, 'sc-domain:')) {
            return 'https://' . substr($site, strlen('sc-domain:'));
        }
        return rtrim($site !== '' ? $site : $fallback, '/');
    }

    /**
     * What a person typed into URL search as a path on this site: a full address of the site or a path, nothing else.
     * '' means the whole site, null an address of another site.
     */
    public static function normalizePath(string $input, string $base): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $input) === 1) {
            $host = static fn(string $url): string => (string) preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
            if ($host($input) !== $host($base)) {
                return null;
            }
            $input = (string) parse_url($input, PHP_URL_PATH);
        }
        $path = '/' . ltrim((string) strtok($input, '?#'), '/');
        return preg_match('/[\x00-\x1f\x7f]/', $path) === 1 ? null : mb_substr($path, 0, 500);
    }

    /** @return array{current: array<string, float>, previous: array<string, float>} */
    public function analyticsSummary(): array
    {
        return $this->remember('ga-summary', self::TTL, function (): array {
            $w = $this->range->analytics();
            $answer = $this->analytics([
                'dateRanges' => [['startDate' => $w['start'], 'endDate' => $w['end'], 'name' => 'current'], ['startDate' => $w['previous_start'], 'endDate' => $w['previous_end'], 'name' => 'previous']],
                'metrics' => [['name' => 'sessions'], ['name' => 'totalUsers'], ['name' => 'screenPageViews'], ['name' => 'engagementRate']],
            ]);
            $out = ['current' => [], 'previous' => []];
            foreach ($answer['rows'] ?? [] as $row) {
                $name = (string) ($row['dimensionValues'][0]['value'] ?? 'current');
                if (isset($out[$name])) {
                    $out[$name] = ['sessions' => self::metric($row, 0), 'users' => self::metric($row, 1), 'views' => self::metric($row, 2), 'engagement' => self::metric($row, 3)];
                }
            }
            return $out;
        });
    }

    /** @return list<array{date: string, value: float}> sessions per day, days without a visit included */
    public function analyticsTrend(): array
    {
        return $this->remember('ga-trend', self::TTL, function (): array {
            $w = $this->range->analytics();
            $answer = $this->analytics([
                'dateRanges' => [['startDate' => $w['start'], 'endDate' => $w['end']]],
                'dimensions' => [['name' => 'date']],
                'metrics' => [['name' => 'sessions']],
                'orderBys' => [['dimension' => ['dimensionName' => 'date']]],
            ]);
            $seen = [];
            foreach ($answer['rows'] ?? [] as $row) {
                $raw = (string) ($row['dimensionValues'][0]['value'] ?? '');
                if (strlen($raw) === 8) {
                    $seen[substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2)] = self::metric($row, 0);
                }
            }
            return self::filledDays($w['start'], $w['end'], $seen);
        });
    }

    /**
     * One dimension against one metric, largest first.
     *
     * @return list<array{label: string, value: float}>
     */
    public function analyticsTop(string $dimension, string $metric, bool $returningOnly = false): array
    {
        return $this->remember('ga-top-' . $dimension . '-' . $metric . ($returningOnly ? '-returning' : ''), self::TTL, function () use ($dimension, $metric, $returningOnly): array {
            $w = $this->range->analytics();
            $answer = $this->analytics([
                'dateRanges' => [['startDate' => $w['start'], 'endDate' => $w['end']]],
                'dimensions' => [['name' => $dimension]],
                'metrics' => [['name' => $metric]],
                'orderBys' => [['metric' => ['metricName' => $metric], 'desc' => true]],
                'limit' => self::TOP_ROWS,
            ], $returningOnly ? ['newVsReturning', 'returning'] : null);
            $rows = [];
            foreach ($answer['rows'] ?? [] as $row) {
                $rows[] = ['label' => (string) ($row['dimensionValues'][0]['value'] ?? ''), 'value' => self::metric($row, 0)];
            }
            return $rows;
        });
    }

    /** @return list<array{label: string, value: float, sessions: float}> traffic sources by engagement rate; a source needs 10 sessions to count */
    public function engagedSources(): array
    {
        return $this->remember('ga-engaged', self::TTL, function (): array {
            $w = $this->range->analytics();
            $answer = $this->analytics([
                'dateRanges' => [['startDate' => $w['start'], 'endDate' => $w['end']]],
                'dimensions' => [['name' => 'sessionDefaultChannelGroup']],
                'metrics' => [['name' => 'engagementRate'], ['name' => 'sessions']],
                'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
                'limit' => 20,
            ]);
            $rows = [];
            foreach ($answer['rows'] ?? [] as $row) {
                if (self::metric($row, 1) >= 10) {
                    $rows[] = ['label' => (string) ($row['dimensionValues'][0]['value'] ?? ''), 'value' => self::metric($row, 0), 'sessions' => self::metric($row, 1)];
                }
            }
            usort($rows, static fn(array $a, array $b): int => $b['value'] <=> $a['value']);
            return array_slice($rows, 0, self::TOP_ROWS);
        });
    }

    /**
     * Clicks and impressions for both periods, read from one query by day.
     *
     * @return array{current: array<string, float>, previous: array<string, float>, days: list<array{date: string, value: float}>}
     */
    public function searchSummary(): array
    {
        return $this->remember('gsc-summary', self::TTL, function (): array {
            $w = $this->range->search();
            $answer = $this->search(['startDate' => $w['previous_start'], 'endDate' => $w['end'], 'dimensions' => ['date'], 'rowLimit' => 500]);
            $current = $previous = ['clicks' => 0.0, 'impressions' => 0.0, 'weighted' => 0.0];
            $clicks = [];
            foreach ($answer['rows'] ?? [] as $row) {
                $date = (string) ($row['keys'][0] ?? '');
                $add = static function (array $sum) use ($row): array {
                    $sum['clicks'] += (float) ($row['clicks'] ?? 0);
                    $sum['impressions'] += (float) ($row['impressions'] ?? 0);
                    $sum['weighted'] += (float) ($row['position'] ?? 0) * (float) ($row['impressions'] ?? 0);
                    return $sum;
                };
                if ($date >= $w['start']) {
                    $current = $add($current);
                    $clicks[$date] = (float) ($row['clicks'] ?? 0);
                } else {
                    $previous = $add($previous);
                }
            }
            return ['current' => self::searchTotals($current), 'previous' => self::searchTotals($previous), 'days' => self::filledDays($w['start'], $w['end'], $clicks)];
        });
    }

    /**
     * Top rows of one Search Console dimension (`query` or `page`).
     *
     * @return list<array{label: string, clicks: float, impressions: float, ctr: float, position: float}>
     */
    public function searchTop(string $dimension): array
    {
        return $this->remember('gsc-top-' . $dimension, self::TTL, function () use ($dimension): array {
            $w = $this->range->search();
            $answer = $this->search(['startDate' => $w['start'], 'endDate' => $w['end'], 'dimensions' => [$dimension], 'rowLimit' => self::TOP_ROWS]);
            $rows = [];
            foreach ($answer['rows'] ?? [] as $row) {
                $rows[] = ['label' => (string) ($row['keys'][0] ?? ''), 'clicks' => (float) ($row['clicks'] ?? 0), 'impressions' => (float) ($row['impressions'] ?? 0), 'ctr' => (float) ($row['ctr'] ?? 0), 'position' => (float) ($row['position'] ?? 0)];
            }
            return $rows;
        });
    }

    /**
     * @return array{score: ?int, field: array<string, array{value: float, rating: string}>, lab: array<string, string>, url: string}
     */
    public function pageSpeed(string $url, string $strategy): array
    {
        return $this->remember('psi-' . $strategy . '-' . $url, self::SPEED_TTL, function () use ($url, $strategy): array {
            return self::parseSpeed($this->google->pageSpeed($url, $strategy, $this->apiKey), $url);
        }, false);
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{score: ?int, field: array<string, array{value: float, rating: string}>, lab: array<string, string>, url: string}
     */
    public static function parseSpeed(array $raw, string $url): array
    {
        $score = $raw['lighthouseResult']['categories']['performance']['score'] ?? null;
        $field = [];
        foreach (['lcp' => 'LARGEST_CONTENTFUL_PAINT_MS', 'inp' => 'INTERACTION_TO_NEXT_PAINT', 'cls' => 'CUMULATIVE_LAYOUT_SHIFT_SCORE'] as $key => $name) {
            $metric = $raw['loadingExperience']['metrics'][$name] ?? null;
            if (is_array($metric)) {
                $value = (float) ($metric['percentile'] ?? 0);
                $field[$key] = ['value' => $key === 'cls' ? $value / 100 : $value, 'rating' => strtolower((string) ($metric['category'] ?? ''))];
            }
        }
        $lab = [];
        foreach (['fcp' => 'first-contentful-paint', 'lcp' => 'largest-contentful-paint', 'tbt' => 'total-blocking-time', 'cls' => 'cumulative-layout-shift', 'si' => 'speed-index'] as $key => $audit) {
            $text = $raw['lighthouseResult']['audits'][$audit]['displayValue'] ?? null;
            if (is_string($text)) {
                $lab[$key] = $text;
            }
        }
        return ['score' => is_numeric($score) ? (int) round((float) $score * 100) : null, 'field' => $field, 'lab' => $lab, 'url' => $url];
    }

    /**
     * @param array<string, mixed>              $report
     * @param array{0: string, 1: string}|null  $only   a dimension that must equal a value
     * @return array<string, mixed>
     */
    private function analytics(array $report, ?array $only = null): array
    {
        $filters = $only !== null ? [$only] : [];
        if ($this->path !== '') {
            $filters[] = ['pagePath', $this->path];
        }
        $expressions = array_map(static fn(array $f): array => ['filter' => ['fieldName' => $f[0], 'stringFilter' => ['matchType' => 'EXACT', 'value' => $f[1]]]], $filters);
        if (count($expressions) === 1) {
            $report['dimensionFilter'] = $expressions[0];
        } elseif ($expressions !== []) {
            $report['dimensionFilter'] = ['andGroup' => ['expressions' => $expressions]];
        }
        return $this->google->analyticsReport($this->property, $report);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function search(array $query): array
    {
        if ($this->path !== '') {
            $query['dimensionFilterGroups'] = [['filters' => [self::pageFilter($this->site, $this->path)]]];
        }
        return $this->google->searchAnalytics($this->site, $query);
    }

    /**
     * A domain property holds every host and scheme of the domain, so one page there is matched with and without www.
     *
     * @return array{dimension: string, operator: string, expression: string}
     */
    public static function pageFilter(string $site, string $path): array
    {
        if (str_starts_with($site, 'sc-domain:')) {
            $host = preg_quote(substr($site, strlen('sc-domain:')), '/');
            return ['dimension' => 'page', 'operator' => 'includingRegex', 'expression' => '^https?://(www\\.)?' . $host . preg_quote($path, '/') . '$'];
        }
        return ['dimension' => 'page', 'operator' => 'equals', 'expression' => self::publicBase($site, '') . $path];
    }

    /**
     * @template T of array
     * @param \Closure(): T $load
     * @return T
     */
    private function remember(string $name, int $ttl, \Closure $load, bool $scoped = true): array
    {
        $key = $scoped ? implode('|', [$name, $this->site, $this->property, $this->range->days, $this->path]) : $name;
        $hit = $this->cache->get($key);
        if ($hit !== null) {
            return $hit;
        }
        $value = $load();
        $this->cache->set($key, $value, $ttl);
        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function metric(array $row, int $index): float
    {
        return (float) ($row['metricValues'][$index]['value'] ?? 0);
    }

    /**
     * @param array{clicks: float, impressions: float, weighted: float} $sum
     * @return array{clicks: float, impressions: float, ctr: float, position: float}
     */
    private static function searchTotals(array $sum): array
    {
        $shown = $sum['impressions'];
        return ['clicks' => $sum['clicks'], 'impressions' => $shown, 'ctr' => $shown > 0 ? $sum['clicks'] / $shown : 0.0, 'position' => $shown > 0 ? $sum['weighted'] / $shown : 0.0];
    }

    /**
     * @param array<string, float> $seen
     * @return list<array{date: string, value: float}>
     */
    private static function filledDays(string $start, string $end, array $seen): array
    {
        $days = [];
        for ($day = new \DateTimeImmutable($start), $last = new \DateTimeImmutable($end); $day <= $last; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $days[] = ['date' => $date, 'value' => $seen[$date] ?? 0.0];
        }
        return $days;
    }
}
