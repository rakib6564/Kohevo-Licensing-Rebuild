<?php
/**
 * Kohevo Studio (studio-builder) — a small per-visitor limit on starting payments.
 *
 * The pay endpoint is anonymous and calls Stripe, so an unthrottled loop could burn the account's API allowance. This is a
 * best-effort limit kept in one small file per visitor in a writable directory (no table, no session, nothing to migrate):
 * at most `$max` starts per `$window` seconds.
 *
 * - The visitor is the client address from `visitor()`: REMOTE_ADDR, or — only when REMOTE_ADDR is one of the operator's
 *   trusted proxies — the address that proxy reports. A forwarding header from anyone else is ignored (it is spoofable).
 *   An IPv6 client is counted by its /64, so rotating addresses inside one allocation does not reset the limit.
 * - Fail safe: when no directory can be written (the first one, then each fallback) the request is refused, so a broken
 *   temp directory cannot silently switch the limit off.
 * - Old visitor files are pruned, at most once per window, so the directory does not grow with every address ever seen.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Payment;

final class PayThrottle
{
    /** @var list<string> */
    private readonly array $directories;

    public function __construct(string $directory, string ...$fallbacks)
    {
        $this->directories = array_values(array_merge([$directory], $fallbacks));
    }

    public function allow(string $visitor, int $max = 10, int $window = 60, ?int $now = null): bool
    {
        $now ??= time();
        if ($max < 1) {
            return true; // the limit is switched off on purpose
        }
        foreach ($this->directories as $directory) {
            $result = $this->allowIn($directory, $visitor, $max, $window, $now);
            if ($result !== null) {
                return $result;
            }
        }
        return false; // nowhere to count: refuse rather than run unthrottled
    }

    /**
     * The client address to count. Forwarding headers are believed only when the direct peer is a trusted proxy
     * (`$trustedProxies`: comma-separated addresses or CIDR ranges, e.g. the STUDIO_TRUSTED_PROXIES setting).
     *
     * @param array<string, mixed> $server $_SERVER
     */
    public static function visitor(array $server, string $trustedProxies = ''): string
    {
        $remote = trim((string) ($server['REMOTE_ADDR'] ?? ''));
        $trusted = array_values(array_filter(array_map('trim', explode(',', $trustedProxies)), static fn(string $p): bool => $p !== ''));
        $client = $remote;
        if ($trusted !== [] && self::inAny($remote, $trusted)) {
            $cf = trim((string) ($server['HTTP_CF_CONNECTING_IP'] ?? ''));
            if (filter_var($cf, FILTER_VALIDATE_IP) !== false) {
                $client = $cf;
            } else {
                // The right-most address that is not one of our proxies is the one the nearest trusted proxy saw.
                $hops = array_reverse(array_map('trim', explode(',', (string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''))));
                foreach ($hops as $hop) {
                    if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                        break;
                    }
                    $client = $hop;
                    if (!self::inAny($hop, $trusted)) {
                        break;
                    }
                }
            }
        }
        if (filter_var($client, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($client);
            if ($packed !== false) {
                return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
            }
        }
        return $client;
    }

    /** @return bool|null null when this directory cannot be used */
    private function allowIn(string $directory, string $visitor, int $max, int $window, int $now): ?bool
    {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return null;
        }
        $dir = rtrim($directory, '/');
        $file = $dir . '/pay-' . substr(hash('sha256', $visitor), 0, 32);
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return null;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return null;
            }
            $raw = stream_get_contents($handle);
            $times = array_values(array_filter(array_map('intval', $raw === false || $raw === '' ? [] : explode(',', $raw)), static fn(int $t): bool => $t > $now - $window));
            $allowed = count($times) < $max;
            if ($allowed) {
                $times[] = $now;
            }
            ftruncate($handle, 0);
            rewind($handle);
            $written = fwrite($handle, implode(',', array_slice($times, -$max)));
            flock($handle, LOCK_UN);
            if ($written === false) {
                return null;
            }
        } finally {
            fclose($handle);
        }
        @touch($file, $now);
        $this->pruneOccasionally($dir, $window, $now);
        return $allowed;
    }

    /** Prune at most once per window (a marker file's time says when it last ran). */
    private function pruneOccasionally(string $dir, int $window, int $now): void
    {
        $marker = $dir . '/.pruned';
        $last = @filemtime($marker);
        if ($last !== false && $last > $now - $window) {
            return;
        }
        @touch($marker, $now);
        foreach (glob($dir . '/pay-*') ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $now - $window) {
                @unlink($file);
            }
        }
    }

    /** @param list<string> $ranges */
    private static function inAny(string $ip, array $ranges): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        foreach ($ranges as $range) {
            [$net, $bits] = array_pad(explode('/', $range, 2), 2, null);
            $netPacked = @inet_pton((string) $net);
            if ($netPacked === false || strlen($netPacked) !== strlen($packed)) {
                continue;
            }
            $bits = $bits === null ? strlen($packed) * 8 : (int) $bits;
            if ($bits < 0 || $bits > strlen($packed) * 8) {
                continue;
            }
            $bytes = intdiv($bits, 8);
            if (substr($packed, 0, $bytes) !== substr($netPacked, 0, $bytes)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($packed[$bytes]) & $mask) === (ord($netPacked[$bytes]) & $mask)) {
                return true;
            }
        }
        return false;
    }
}
