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
    | Which requests have their queries logged:
    |
    |   ROLLBACK  the ones whose writes were lost: rolled back, or a commit
    |             that failed (the default)
    |   ALWAYS    every request, committed or not -- including under
    |             AUTO_ROLLBACK=OFF, where requests are logged but otherwise
    |             left alone
    |   NEVER     none
    |
    | AUTO_ROLLBACK_LOG=false from v1.1 still means NEVER.
    |
    | Each request's queries, with their bindings substituted, are appended to
    |
    |   {path}/{Ymd}/{route uri, / replaced by the separator}/{method}.txt
    |
    | e.g. GET /api/v1/website/header is logged to
    |
    |   storage/logs/autorollback/20261105/api~v1~website~header/get.txt
    |
    | The route's URI pattern is used rather than the request path, so
    | /articles/1 and /articles/2 share articles~{id}/.
    |
    */

    'log' => [
        'query' => env('RBQUERY_LOG',env('AUTO_ROLLBACK_LOG', true) === false ? 'never' : 'rollback'),

        'path' => storage_path('logs/autorollback'),

        /*
        | Joins a route's segments into one folder name. ~ is valid on every
        | file system, needs no escaping in URLs, and means nothing to a shell
        | mid-word. # and % are poorer choices: # starts a URL fragment, so
        | links to the file break, and % starts URL encoding and CMD variables.
        | / \ < > : " | ? * are refused and fall back to ~.
        */

        'separator' => '~',

        /*
        | Who made the request, written as "User : ...": this field of the
        | authenticated user (any guard -- session, JWT, Sanctum ...), or
        | failing that the session value of that name, e.g. email, username or
        | id. Dot notation reaches nested values. "-" when there is neither.
        |
        | "header:<name>", e.g. header:X-User-Email, reads that request header
        | instead -- for a service that learns who the user is from a gateway.
        | Credential headers such as Authorization and Cookie are masked.
        |
        | Empty or missing leaves the line out.
        */

        'user' => env('RB_SESSION_USER'),

        /*
        | Each logged request also records its query string, its body (JSON,
        | form, or raw text such as XML) and any uploaded files' names, sizes
        | and types -- all as they arrived, before middleware or the app
        | changed them.
        |
        | Fields with these names, at any depth and in any case, are written
        | as ********. A raw body cannot be masked.
        */

        'hidden' => [
            'password', 'password_confirmation', 'current_password', 'new_password',
            'token', 'access_token', 'refresh_token', 'api_key', 'secret', 'client_secret',
            'pin', 'otp', 'cvv', 'card_number',
        ],

        // Longer query strings and bodies are cut short in the log. 0 never cuts.
        'max_length' => 10000,
    ],

];
