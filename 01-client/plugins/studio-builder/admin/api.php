<?php
/**
 * Kohevo Studio — builder command/query endpoint (JSON).
 *
 *   GET  /plugins/studio-builder/admin/api.php?action=<query>&...
 *   POST /plugins/studio-builder/admin/api.php?action=<command>   (JSON body)
 *
 * A thin adapter: it only turns the HTTP request into a StudioApiRequest
 * (with the platform's own csrf_verify() verdict — the X-CSRF-Token header
 * carries the session token) and the session into a StudioActor, then hands
 * both to StudioAuthoringApi, which enforces everything else through
 * StudioApplicationService. Responses are JSON, private and no-store; an
 * unauthenticated call gets a 401 JSON error instead of a login redirect.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Http\StudioApiRateLimiter;
use Slate\Module\StudioBuilder\Http\StudioApiRequest;
use Slate\Module\StudioBuilder\Http\StudioAuthoringApi;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;

$studioApiLimiter = StudioApiRateLimiter::system();
$api = new StudioAuthoringApi(
    StudioRuntimeFactory::build()->app,
    static function (string $method, string $action = '') use ($studioApiLimiter): bool {
        if (!isset($_SESSION['studio_api_rate'])) {
            $_SESSION['studio_api_rate'] = null;
        }
        return $studioApiLimiter->hit($_SESSION['studio_api_rate'], $method, $action);
    },
    static function (string $message): void {
        if (function_exists('slate_log')) {
            slate_log($message, 'error');
        }
    },
);

$request = StudioApiRequest::fromGlobals(
    strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' && csrf_verify(),
    StudioAuthoringApi::MAX_BODY_BYTES,
);

$api->handle($request, StudioActor::fromCurrentSession())->send();
