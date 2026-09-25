<?php
declare(strict_types=1);

use Slate\Services\Auth\Auth;

$original = [
    'HTTPS' => $_SERVER['HTTPS'] ?? null,
    'HTTP_X_FORWARDED_PROTO' => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null,
];

unit('session cookie policy is secure for direct HTTPS', function (): void {
    $_SERVER['HTTPS'] = 'on';
    unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
    $params = Auth::sessionCookieParams();
    assert_true($params['secure'] === true);
    assert_true($params['httponly'] === true);
    assert_eq('Lax', $params['samesite']);
});

unit('session cookie policy honors reverse-proxy HTTPS', function (): void {
    $_SERVER['HTTPS'] = 'off';
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
    assert_true(Auth::sessionCookieParams()['secure'] === true);
});

unit('session cookie policy stays non-secure on plain HTTP', function (): void {
    $_SERVER['HTTPS'] = 'off';
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
    assert_true(Auth::sessionCookieParams()['secure'] === false);
});

foreach ($original as $key => $value) {
    if ($value === null) unset($_SERVER[$key]);
    else $_SERVER[$key] = $value;
}
