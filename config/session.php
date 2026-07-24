<?php

use Illuminate\Support\Str;

return [

    'driver' => env('SESSION_DRIVER', 'redis'),

    'lifetime' => (int) env('SESSION_LIFETIME', 120),

    'expire_on_close' => env('SESSION_EXPIRE_ON_CLOSE', false),

    'encrypt' => env('SESSION_ENCRYPT', true),

    'files' => storage_path('framework/sessions'),

    'connection' => env('SESSION_CONNECTION'),

    'table' => env('SESSION_TABLE', 'sessions'),

    'store' => env('SESSION_STORE'),

    'lottery' => [2, 100],

    // Base cookie name/domain for the customer app. The admin subdomain
    // overrides both at runtime via App\Shared\Middleware\ScopeAdminSessionCookie
    // so the two surfaces never share a session cookie.
    'cookie' => env(
        'SESSION_COOKIE',
        Str::slug(env('APP_NAME', 'FirstMaket'), '_').'_session'
    ),

    'path' => '/',

    'domain' => env('SESSION_DOMAIN'),

    'secure' => env('SESSION_SECURE_COOKIE', true),

    'http_only' => true,

    'same_site' => 'lax',

    'partitioned' => false,

];
