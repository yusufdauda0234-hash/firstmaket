<?php

use Illuminate\Support\Str;

return [

    'default' => env('DB_CONNECTION', 'mariadb'),

    'connections' => [

        // Local development (XAMPP ships MariaDB; Laravel's dedicated
        // mariadb driver handles its syntax differences, e.g. column
        // renames on MariaDB < 10.5).
        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'firstmarket'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
        ],

        // Production MySQL 8 (set DB_CONNECTION=mysql there).
        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'firstmarket'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
        ],

        // Finance/Admin reporting and reconciliation queries should read
        // from this connection so they never contend with live wallet/order
        // writes on the primary. Points at the same database until a
        // physical read replica is provisioned (docs/firstmarket-Database_Schema.md).
        'reporting' => [
            'driver' => env('DB_REPORTING_DRIVER', env('DB_CONNECTION', 'mariadb')),
            'url' => env('DB_REPORTING_URL'),
            'host' => env('DB_REPORTING_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('DB_REPORTING_PORT', env('DB_PORT', '3306')),
            'database' => env('DB_DATABASE', 'firstmarket'),
            'username' => env('DB_REPORTING_USERNAME', env('DB_USERNAME', 'root')),
            'password' => env('DB_REPORTING_PASSWORD', env('DB_PASSWORD', '')),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
        ],

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    // Retained for optional future scale-out only — nothing in the MVP uses
    // Redis; cache, sessions, queues, and rate limiting all run on the
    // database driver (see docs/firstmarket_Implementation_Plan.md).
    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'firstmarket'), '_').'_database_'),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

    ],

];
