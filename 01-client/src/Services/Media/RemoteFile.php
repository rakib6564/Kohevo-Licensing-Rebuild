<?php
/**
 * Slate — download a file from a public web address for the media library ("Import from URL").
 *
 * The address is checked before anything is sent: http or https only, the standard ports only, no user:pass@, and
 * every address the host resolves to must be a public one (no localhost, private network, cloud metadata address,
 * or an IPv6 prefix that wraps one). curl is then pinned to the checked address so a second DNS answer cannot point
 * it somewhere else. Redirects are followed by hand, a few at most, and each new address is checked the same way.
 * The body is written to a temporary file and the download stops as soon as it passes the size limit.
 *
 * The same checks as AiProviderClient::vetBaseUrl() (mcp-gateway plugin), kept in core so the media library does not
 * depend on a plugin.
 */

declare(strict_types=1);

namespace Slate\Services\Media;

final class RemoteFile
{
    public const MAX_REDIRECTS = 3;
    public const TIMEOUT = 60;

    /** Ranges PHP's NO_PRIV/NO_RES flags let through but that are not public internet hosts. */
    private const BLOCKED_RANGES = [
        '100.64.0.0/10', '198.18.0.0/15', '224.0.0.0/4',
        '64:ff9b::/96', '64:ff9b:1::/48', '2002::/16', '2001::/32', 'ff00::/8', '100::/64',
    ];

    /**
     * Is this a public http(s) address we may fetch? $resolve maps a host to its IPs; it is injectable for tests.
     *
     * @return array{ok: bool, error: ?string, host?: string, port?: int, ip?: string, scheme?: string}
     */
    public static function vet(string $url, ?callable $resolve = null): array
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2000 || preg_match('/[\s\x00-\x1f]/', $url)) {
            return ['ok' => false, 'error' => 'invalid_url'];
        }
        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        if (!in_array($scheme, ['http', 'https'], true)) {
            return ['ok' => false, 'error' => 'http_only'];
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return ['ok' => false, 'error' => 'credentials_in_url'];
        }
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if ($host === '') {
            return ['ok' => false, 'error' => 'host_missing'];
        }
        $default = $scheme === 'https' ? 443 : 80;
        $port = (int) ($parts['port'] ?? $default);
        if ($port !== $default) {
            return ['ok' => false, 'error' => 'port_not_allowed'];
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $resolve ??= static function (string $h): array {
                $ips = @gethostbynamel($h) ?: [];
                foreach (@dns_get_record($h, DNS_AAAA) ?: [] as $rec) {
                    if (!empty($rec['ipv6'])) {
                        $ips[] = $rec['ipv6'];
                    }
                }
                return $ips;
            };
            $ips = $resolve($host);
        }
        if (!$ips) {
            return ['ok' => false, 'error' => 'host_unresolvable'];
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp((string) $ip)) {
                return ['ok' => false, 'error' => 'private_address'];
            }
        }
        return ['ok' => true, 'error' => null, 'host' => $host, 'port' => $port, 'ip' => (string) $ips[0], 'scheme' => $scheme];
    }

    public static function isPublicIp(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            && !self::inBlockedRange($ip);
    }

    /**
     * Download $url into a new temporary file.
     *
     * @return array{ok: true, file: string, url: string, name: string, size: int}|array{ok: false, error: string}
     *         On success the caller owns (and must delete) 'file'. 'name' is the file name the server gave, or the
     *         last part of the address.
     */
    public static function download(string $url, int $maxBytes, ?callable $resolve = null): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'no_curl'];
        }
        $tmp = tempnam(sys_get_temp_dir(), 'slatefetch');
        if ($tmp === false) {
            return ['ok' => false, 'error' => 'tmp_failed'];
        }
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $vet = self::vet($url, $resolve);
            if (!$vet['ok']) {
                @unlink($tmp);
                return ['ok' => false, 'error' => (string) $vet['error']];
            }
            $res = self::fetchOnce($url, $vet, $tmp, $maxBytes);
            if (!$res['ok']) {
                @unlink($tmp);
                return $res;
            }
            if ($res['location'] !== null) {
                $url = self::absolute($url, $res['location']);
                continue;
            }
            clearstatcache(true, $tmp);
            $size = (int) @filesize($tmp);
            if ($size <= 0) {
                @unlink($tmp);
                return ['ok' => false, 'error' => 'empty_file'];
            }
            $name = $res['filename'] !== '' ? $res['filename'] : rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)));
            return ['ok' => true, 'file' => $tmp, 'url' => $url, 'name' => $name, 'size' => $size];
        }
        @unlink($tmp);
        return ['ok' => false, 'error' => 'too_many_redirects'];
    }

    /**
     * @param array{host: string, port: int, ip: string, scheme: string} $vet
     * @return array{ok: true, location: ?string, filename: string}|array{ok: false, error: string}
     */
    private static function fetchOnce(string $url, array $vet, string $tmp, int $maxBytes): array
    {
        $fh = @fopen($tmp, 'wb');
        if ($fh === false) {
            return ['ok' => false, 'error' => 'tmp_failed'];
        }
        $headers = [];
        $tooBig = false;
        $ch = curl_init($url);
        // An IP-literal host is never looked up, so it needs no pin.
        if (!filter_var($vet['host'], FILTER_VALIDATE_IP)) {
            $pinIp = str_contains($vet['ip'], ':') ? '[' . $vet['ip'] . ']' : $vet['ip'];
            curl_setopt($ch, CURLOPT_RESOLVE, [$vet['host'] . ':' . $vet['port'] . ':' . $pinIp]);
        }
        curl_setopt_array($ch, [
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'Slate media importer',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $p = strpos($line, ':');
                if ($p !== false) {
                    $headers[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                } elseif (str_starts_with($line, 'HTTP/')) {
                    $headers = []; // a new response (after a 100 Continue): keep only its headers
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use ($fh, $maxBytes, &$tooBig): int {
                if (ftell($fh) + strlen($chunk) > $maxBytes) {
                    $tooBig = true;
                    return 0; // abort the transfer
                }
                return (int) fwrite($fh, $chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fh);

        if ($tooBig) {
            return ['ok' => false, 'error' => 'too_large'];
        }
        if ($ok === false) {
            return ['ok' => false, 'error' => 'fetch_failed'];
        }
        if (in_array($code, [301, 302, 303, 307, 308], true) && !empty($headers['location'])) {
            // The redirect's own body is thrown away: the next request reopens $tmp with 'wb'.
            return ['ok' => true, 'location' => (string) $headers['location'], 'filename' => ''];
        }
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'error' => 'http_' . $code];
        }
        return ['ok' => true, 'location' => null, 'filename' => self::dispositionName((string) ($headers['content-disposition'] ?? ''))];
    }

    /** The file name in a Content-Disposition header, or ''. */
    public static function dispositionName(string $header): string
    {
        if (preg_match("/filename\*\s*=\s*(?:UTF-8|utf-8)''([^;]+)/", $header, $m)) {
            return basename(rawurldecode(trim($m[1], " \"'")));
        }
        if (preg_match('/filename\s*=\s*"?([^";]+)"?/i', $header, $m)) {
            return basename(trim($m[1]));
        }
        return '';
    }

    /** Resolve a redirect target against the address that sent it. */
    public static function absolute(string $base, string $location): string
    {
        if (preg_match('~^https?://~i', $location)) {
            return $location;
        }
        $b = parse_url($base);
        $origin = strtolower((string) ($b['scheme'] ?? 'https')) . '://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($location, '//')) {
            return strtolower((string) ($b['scheme'] ?? 'https')) . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = rtrim(str_replace('\\', '/', dirname((string) ($b['path'] ?? '/'))), '/');
        return $origin . $dir . '/' . $location;
    }

    private static function inBlockedRange(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return true;
        }
        foreach (self::BLOCKED_RANGES as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $netBin = inet_pton($net);
            if (strlen($netBin) !== strlen($bin)) {
                continue;
            }
            $bytes = intdiv((int) $bits, 8);
            $rest  = (int) $bits % 8;
            if (substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
                continue;
            }
            if ($rest === 0) {
                return true;
            }
            $mask = chr((0xFF << (8 - $rest)) & 0xFF);
            if ((($bin[$bytes] ^ $netBin[$bytes]) & $mask) === "\0") {
                return true;
            }
        }
        return false;
    }
}
