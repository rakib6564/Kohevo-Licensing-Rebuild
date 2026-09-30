<?php
/**
 * Kohevo Studio (studio-builder) — The only place Studio writes HTTP headers/bodies.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Runtime;

use Slate\Module\StudioBuilder\Render\Cache\StudioCachePolicy;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Render\RenderMode;
use Slate\Module\StudioBuilder\Render\RenderResult;

final class StudioHttpResponder
{
    /**
     * Output handlers that pass the body through unchanged in content (plain
     * buffering, transport compression). Any other active handler — e.g.
     * multilang-translate's `ob_start` closure, which swaps in translations and
     * injects its language switcher — may rewrite the page AFTER Studio hashed
     * it, so the Studio ETag would no longer describe the client-visible body.
     */
    public const BODY_PRESERVING_HANDLERS = ['default output handler', 'zlib output compression', 'ob_gzhandler'];

    /**
     * Whether what Studio echoes now is exactly what the client receives, given
     * the active output handlers (`ob_list_handlers()`). Only then may the
     * public response carry an ETag and answer 304 (Phase 9B).
     *
     * @param list<string> $handlers
     */
    public static function bodyIsFinal(array $handlers): bool
    {
        foreach ($handlers as $handler) {
            if (!in_array((string) $handler, self::BODY_PRESERVING_HANDLERS, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * The `Set-Cookie` lines that may stay on a public Studio response: every
     * one EXCEPT the PHP session cookie. Pure — takes `headers_list()` style
     * lines and the session cookie name.
     *
     * The platform starts a session on every web request (config.php), so an
     * anonymous visitor to a public page would otherwise be handed a
     * `Set-Cookie: SLATE_SID` on a `Cache-Control: public, no-cache` response:
     * a cacheable response carrying a session cookie. Studio's public output is
     * anonymous by contract (no session-dependent content — StudioCachePolicy),
     * so it never needs that cookie; the next page that does (login, forms)
     * sets it itself.
     *
     * @param list<string> $headerLines
     * @return list<string> the Set-Cookie lines to keep
     */
    public static function keptCookieLines(array $headerLines, string $sessionName): array
    {
        $kept = [];
        foreach ($headerLines as $line) {
            if (!is_string($line) || stripos($line, 'Set-Cookie:') !== 0) {
                continue;
            }
            $cookie = ltrim(substr($line, strlen('Set-Cookie:')));
            if ($sessionName !== '' && str_starts_with($cookie, $sessionName . '=')) {
                continue;
            }
            $kept[] = $line;
        }
        return $kept;
    }

    private static function dropSessionCookie(): void
    {
        if (headers_sent() || !function_exists('headers_list')) {
            return;
        }
        $lines = headers_list();
        $sessionName = function_exists('session_name') ? session_name() : '';
        $kept = self::keptCookieLines($lines, $sessionName);
        $all = array_filter($lines, static fn($l): bool => is_string($l) && stripos($l, 'Set-Cookie:') === 0);
        if (count($kept) === count($all)) {
            return; // no session cookie on this response
        }
        header_remove('Set-Cookie');
        foreach ($kept as $line) {
            header($line, false);
        }
    }

    public static function sendPublic(PublicResponse $response): void
    {
        self::dropSessionCookie();
        if ($response->status === 500) {
            if (!headers_sent()) {
                header('Cache-Control: no-store');
                header('X-Request-Id: ' . StudioRequestId::current());
            }
            require_once \SLATE_ROOT . '/includes/error_page.php';
            \slate_render_error(500, 'Something went wrong', "This page couldn't be displayed right now. Please try again later.");
            return;
        }
        self::emit($response->status, $response->headers, $response->body);
    }

    public static function sendRender(RenderResult $result): void
    {
        self::emit(200, $result->headers, $result->html);
    }

    /** A minimal, no-store, noindex error for the authoring (preview/editor) endpoints — no internal detail. */
    public static function sendAuthoringError(int $status): void
    {
        $status = in_array($status, [400, 401, 403, 404, 409, 500], true) ? $status : 500;
        $message = match ($status) {
            401 => 'Please sign in to preview this page.',
            403 => 'You do not have access to preview this page.',
            404 => 'This page or revision was not found.',
            default => 'This preview could not be rendered.',
        };
        self::emit(
            $status,
            StudioCachePolicy::headersFor(RenderMode::Preview) + ['X-Request-Id' => StudioRequestId::current()],
            '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>'
                . Html::e(Html::t('studio_preview_error_title', 'Preview unavailable')) . '</title></head><body><p>'
                . Html::e(Html::t('studio_preview_error_' . $status, $message)) . '</p></body></html>',
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private static function emit(int $status, array $headers, string $body): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            foreach ($headers as $name => $value) {
                if (preg_match('/^[A-Za-z0-9-]+$/', $name) === 1 && !preg_match('/[\r\n]/', $value)) {
                    header($name . ': ' . $value);
                }
            }
        }
        if ($status !== 304) {
            echo $body;
        }
    }
}
