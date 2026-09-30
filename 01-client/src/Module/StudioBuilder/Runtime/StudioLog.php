<?php
/**
 * Kohevo Studio (studio-builder) — Safe server-side failure diagnostics.
 *
 * Builds ONE log line per failure and hands it to the platform's existing
 * `slate_log()` (no new logging framework, no new sink). The line carries
 * enough to tell where a failure happened without ever carrying what the
 * request contained:
 *
 *   req=<id> studio.<component>.<action> failed: <Class> at <file>:<line>
 *            [code=<STUDIO_CODE>] [reason=<key>] [msg="<authored text>"]
 *            [<< <PreviousClass> at <file>:<line> ...]
 *
 * - `req` is StudioRequestId (opaque, random; also the `X-Request-Id` header
 *   of a Studio error response), so a support ticket maps to this line.
 * - `file:line` is the BASENAME and line of where the throwable was raised.
 * - The previous-exception chain is walked (bounded) so a wrapper such as
 *   StudioRenderException does not hide the real cause.
 * - The message is included ONLY for Studio's own exceptions (authored fixed
 *   strings). A PDOException / library exception message may contain SQL,
 *   paths, tenant values or data, so for every other class only the class,
 *   location and numeric/SQLSTATE code are logged.
 * - Never logged: request bodies, headers, cookies, tokens, passwords,
 *   document contents, full paths, stack traces.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Runtime;

use Slate\Module\StudioBuilder\Exception\StudioException;

final class StudioLog
{
    private const MAX_MESSAGE = 160;
    private const MAX_CHAIN = 3;

    /** @var (\Closure(string, string): void)|null */
    private static ?\Closure $sink = null;

    /** Test seam: capture lines instead of writing to the platform log; null restores slate_log(). */
    public static function useSink(?\Closure $sink): void
    {
        self::$sink = $sink;
    }

    /** Log a failure (level `error` by default) and return the line that was written. */
    public static function failure(string $component, string $action, \Throwable $e, string $level = 'error'): string
    {
        $line = self::describe($component, $action, $e);
        if (self::$sink !== null) {
            (self::$sink)($line, $level);
        } elseif (\function_exists('slate_log')) {
            \slate_log($line, $level);
        }
        return $line;
    }

    /** The diagnostic line for a failure — pure apart from the request id. */
    public static function describe(string $component, string $action, \Throwable $e): string
    {
        $line = 'req=' . StudioRequestId::current()
            . ' studio.' . self::token($component) . '.' . self::token($action) . ' failed: ' . self::summary($e);

        $previous = $e->getPrevious();
        for ($i = 0; $previous !== null && $i < self::MAX_CHAIN; $i++, $previous = $previous->getPrevious()) {
            $line .= ' << ' . self::summary($previous);
        }
        return $line;
    }

    private static function summary(\Throwable $e): string
    {
        $out = get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine();

        if ($e instanceof StudioException) {
            $out .= ' code=' . self::token($e->errorCode());
            $reason = $e->details()['reason'] ?? null;
            if (is_string($reason) && $reason !== '') {
                $out .= ' reason=' . self::token($reason);
            }
            $out .= ' msg="' . self::clean($e->getMessage()) . '"';
        } elseif ($e instanceof \PDOException) {
            $state = is_string($e->errorInfo[0] ?? null) ? $e->errorInfo[0] : (string) $e->getCode();
            $out .= ' sqlstate=' . self::token($state);
        }
        return $out;
    }

    /** A short identifier-like token: lowercase-safe charset only, bounded. */
    private static function token(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9_.:-]/', '_', $value) ?? '';
        return $value === '' ? '-' : substr($value, 0, 64);
    }

    /** A bounded single-line text: control characters and quotes removed. */
    private static function clean(string $text): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F"]+/u', ' ', $text) ?? '';
        $text = trim($text);
        return mb_strlen($text) > self::MAX_MESSAGE ? mb_substr($text, 0, self::MAX_MESSAGE) . '…' : $text;
    }
}
