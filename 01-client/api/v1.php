<?php
/**
 * Slate — /api/v1 public route endpoint.
 */

declare(strict_types=1);

if (!defined('SLATE_ROOT')) {
    require_once dirname(__DIR__) . '/config.php';
}

require_once SLATE_ROOT . '/src/Kernel/Http/ApiRouter.php';

\Slate\Kernel\Http\ApiRouter::handle();
