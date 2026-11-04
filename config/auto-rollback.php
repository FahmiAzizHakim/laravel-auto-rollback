<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Auto Rollback Mode
    |--------------------------------------------------------------------------
    |
    | Every HTTP request runs inside a database transaction on each connection
    | it touches. What happens to that transaction once the response has been
    | sent depends on the mode:
    |
    |   ON        always rolled back, and the request's queries are logged
    |   OFF       no transaction; requests run normally
    |   ON_ERROR  committed, unless the request failed -- then rolled back
    |             and logged
    |
    | true and false still mean ON and OFF. When APP_ENV=production, ON
    | runs as ON_ERROR, so a successful request is never rolled back there.
    |
    */

    'mode' => env('AUTO_ROLLBACK', 'off'),

    /*
    |--------------------------------------------------------------------------
    | What Counts As An Error (ON_ERROR)
    |--------------------------------------------------------------------------
    |
    | A request failed if an exception was thrown during it -- including
    | validation, authorization and not-found exceptions -- or if its response
    | status is at least this value without one.
    |
    */

    'error_status' => 500,

    /*
    |--------------------------------------------------------------------------
    | Query Log
    |--------------------------------------------------------------------------
    |
    | Every query a rolled-back request ran, with its bindings substituted, is
    | appended to
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
