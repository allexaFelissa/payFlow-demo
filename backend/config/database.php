<?php

use Illuminate\Support\Str;

$databaseUrl = env('DB_URL');
$databaseHost = $databaseUrl ? parse_url($databaseUrl, PHP_URL_HOST) : env('DB_HOST');
$neonEndpoint = is_string($databaseHost) ? explode('.', $databaseHost, 2)[0] : null;
$testingUrl = env('TEST_DB_URL');
$testingHost = env('TEST_DB_HOST');
$testingDatabase = env('TEST_DB_DATABASE');
$testingUsername = env('TEST_DB_USERNAME');
$testingIsIsolated = filter_var(env('TEST_DB_ISOLATED', false), FILTER_VALIDATE_BOOL);

if (env('APP_ENV') === 'testing') {
    if (env('DB_CONNECTION') !== 'pgsql_testing') {
        throw new RuntimeException('Automated tests must use the isolated pgsql_testing connection.');
    }

    if (! $testingIsIsolated) {
        throw new RuntimeException('Set TEST_DB_ISOLATED=true only after selecting a disposable Neon testing branch.');
    }

    if (! $testingUrl && (! $testingHost || ! $testingDatabase || ! $testingUsername)) {
        throw new RuntimeException('The isolated Neon testing branch connection is not configured.');
    }

    foreach (array_filter([$databaseUrl, env('DB_MIGRATION_URL')]) as $protectedUrl) {
        if (hash_equals(trim((string) $protectedUrl), trim((string) $testingUrl))) {
            throw new RuntimeException('TEST_DB_URL must not match a development or production database URL.');
        }
    }
}

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'pgsql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => $databaseUrl,
            'host' => env('DB_HOST'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE'),
            'username' => env('DB_USERNAME'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'sslmode' => env('DB_SSLMODE', 'require'),
            'neon_endpoint' => str_starts_with((string) $neonEndpoint, 'ep-') ? $neonEndpoint : null,
        ],

        'pgsql_testing' => [
            'driver' => 'pgsql',
            'url' => $testingUrl,
            'host' => $testingHost,
            'port' => env('TEST_DB_PORT', '5432'),
            'database' => $testingDatabase,
            'username' => $testingUsername,
            'password' => env('TEST_DB_PASSWORD', ''),
            'charset' => env('TEST_DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'sslmode' => env('TEST_DB_SSLMODE', 'require'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
