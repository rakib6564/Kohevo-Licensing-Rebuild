<?php
/**
 * Router for `php -S` when a test drives a public endpoint over real HTTP (developer tool; never shipped).
 *
 * Marks the request as a test request (SLATE_TESTING) — the same Global License Guard bypass every other
 * integration test runs under — then lets the built-in server run the requested file as usual.
 */
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
define('SLATE_TESTING', true);
return false;
