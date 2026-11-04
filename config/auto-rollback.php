<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Auto Rollback
    |--------------------------------------------------------------------------
    |
    | When enabled, every HTTP request runs inside a database transaction on
    | each connection it touches, and that transaction is rolled back once the
    | response has been sent. Nothing a request writes is ever kept.
    |
    | Forced off when APP_ENV=production, whatever this value says.
    |
    */

    'enabled' => env('AUTO_ROLLBACK', false),

    /*
    |--------------------------------------------------------------------------
    | Query Log
    |--------------------------------------------------------------------------
    |
    | Every query a request ran, with its bindings substituted, is appended to
    |
    |   {path}/{Ymd}/{method}/{route uri}.txt
    |
    | e.g. storage/logs/autorollback/20261104/post/v1/sites/articles.txt
    |
    | The route's URI pattern is used rather than the request path, so
    | /articles/1 and /articles/2 share articles/{id}.txt.
    |
    */

    'log' => [
        'enabled' => env('AUTO_ROLLBACK_LOG', true),

        'path' => storage_path('logs/autorollback'),
    ],

];
