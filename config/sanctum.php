<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

return [

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', implode(',', array_filter([
        'localhost',
        'localhost:3000',
        '127.0.0.1',
        '127.0.0.1:8000',
        parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST),
        parse_url(env('ADMIN_DOMAIN', 'admin.localhost'), PHP_URL_HOST) ?? env('ADMIN_DOMAIN'),
    ])))),

    'guard' => ['web'],

    'expiration' => null,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
