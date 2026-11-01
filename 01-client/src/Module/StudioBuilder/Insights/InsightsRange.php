<?php
/**
 * Kohevo Studio — a reporting window and the same length before it. Analytics reports up to yesterday; Search Console lags,
 * so its window ends two days back (as in Site Kit) and the screen never shows a half-filled last day.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Insights;

final class InsightsRange
{
    public const CHOICES = [7, 14, 28, 90];
    public const DEFAULT = 28;

    public function __construct(public readonly int $days, private readonly \DateTimeImmutable $today)
    {
    }

    public static function of(mixed $days, ?\DateTimeImmutable $today = null): self
    {
        $days = (int) (is_scalar($days) ? $days : 0);
        return new self(in_array($days, self::CHOICES, true) ? $days : self::DEFAULT, $today ?? new \DateTimeImmutable('today'));
    }

    /** @return array{start: string, end: string, previous_start: string, previous_end: string} */
    public function analytics(): array
    {
        return $this->window(1);
    }

    /** @return array{start: string, end: string, previous_start: string, previous_end: string} */
    public function search(): array
    {
        return $this->window(2);
    }

    /** @return array{start: string, end: string, previous_start: string, previous_end: string} */
    private function window(int $lag): array
    {
        $back = fn(int $n): string => $this->today->modify('-' . $n . ' days')->format('Y-m-d');
        return [
            'start' => $back($lag + $this->days - 1),
            'end' => $back($lag),
            'previous_start' => $back($lag + 2 * $this->days - 1),
            'previous_end' => $back($lag + $this->days),
        ];
    }
}
