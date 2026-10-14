<?php
/**
 * Kohevo Studio (studio-builder) — a small per-visitor limit on starting payments.
 *
 * The pay endpoint is anonymous and calls Stripe, so an unthrottled loop could burn the account's API allowance. This is a
 * best-effort limit kept in one small file per visitor in a writable directory (no table, no session, nothing to migrate):
 * at most `$max` starts per `$window` seconds. If the directory cannot be written it lets the request through rather than
 * blocking a real customer.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Payment;

final class PayThrottle
{
    public function __construct(private readonly string $directory) {}

    public function allow(string $visitor, int $max = 10, int $window = 60, ?int $now = null): bool
    {
        $now ??= time();
        if ($max < 1 || (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory))) {
            return true;
        }
        $file = rtrim($this->directory, '/') . '/pay-' . substr(hash('sha256', $visitor), 0, 32);
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return true;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return true;
            }
            $raw = stream_get_contents($handle);
            $times = array_values(array_filter(array_map('intval', $raw === false || $raw === '' ? [] : explode(',', $raw)), static fn(int $t): bool => $t > $now - $window));
            $allowed = count($times) < $max;
            if ($allowed) {
                $times[] = $now;
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, implode(',', array_slice($times, -$max)));
            flock($handle, LOCK_UN);
            return $allowed;
        } finally {
            fclose($handle);
        }
    }
}
