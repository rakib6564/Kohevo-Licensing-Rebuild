<?php
/**
 * Kohevo Studio — a Google call that failed, as a reason the Site Insights screen can explain. The message is for the log
 * and never carries a token, a key or a full address.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Insights;

final class GoogleApiException extends \RuntimeException
{
    public const RECONNECT = 'reconnect';      // the grant is gone: connect again
    public const NOT_ENABLED = 'not_enabled';  // the API is off in the Google Cloud project
    public const FORBIDDEN = 'forbidden';      // this Google account has no access to the property
    public const QUOTA = 'quota';              // Google's limit for now
    public const NOT_FOUND = 'not_found';
    public const UNAVAILABLE = 'unavailable';  // network, or Google answered with a server error
    public const CENTRAL = 'central';          // Kohevo Central refused this site, or has no Google client yet
    public const NOT_SECURE = 'not_secure';    // APP_SECRET is missing, so nothing can be stored

    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }
}
