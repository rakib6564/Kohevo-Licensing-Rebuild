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
    public static function sendPublic(PublicResponse $response): void
    {
        if ($response->status === 500) {
            if (!headers_sent()) {
                header('Cache-Control: no-store');
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
            StudioCachePolicy::headersFor(RenderMode::Preview),
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
