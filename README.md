# Laravel Auto Rollback

Run every HTTP request inside a database transaction, then decide once the response has been sent whether to keep it. You can roll back every request, so the database stays exactly as it was, or roll back only the requests that failed.

It is built for **demo, staging, development and dry-run** environments. **In production, `ON` runs as `ON_ERROR`**, so a successful request is never rolled back there.

Supports MySQL, MariaDB, PostgreSQL and SQLite, on Laravel 11 and 12.

## Installation

```bash
composer require fahmiazizhakim/laravel-auto-rollback
```

The service provider is auto-discovered. Then choose a mode in `.env`:

```env
AUTO_ROLLBACK=ON
```

## Modes

| `AUTO_ROLLBACK` | What happens                                                              |
|-----------------|---------------------------------------------------------------------------|
| `ON`            | Every request is rolled back                                              |
| `OFF` (default) | Requests run normally; no transaction is opened (they can still be logged, see `ALWAYS`) |
| `ON_ERROR`      | Successful requests are committed; requests that failed are rolled back   |

Which of these requests get logged is set separately by `RBQUERY_LOG`. See [Query log](#query-log).

Case does not matter. `true` and `false` from v1.0 still mean `ON` and `OFF`, and any value the package does not recognise is treated as `OFF`.

### In production

When `APP_ENV=production`, only `OFF` and `ON_ERROR` are available:

| `AUTO_ROLLBACK` | Runs as    |
|-----------------|------------|
| `ON`            | `ON_ERROR` |
| `OFF`           | `OFF`      |
| `ON_ERROR`      | `ON_ERROR` |

Before using `ON_ERROR` in production, read the notes on queued jobs and longer transactions under [Limits](#limits).

In `ON_ERROR` mode a request **failed** if either of these is true:

- An exception was thrown during it, including `ValidationException` (422), `AuthenticationException` (401), `AuthorizationException` (403) and `ModelNotFoundException` (404).
- It returned a status of **500 or above** without throwing. You can change this threshold with `error_status` in the config, e.g. `400` to treat every 4xx as a failure.

## Configuration

| Variable          | Default    | Meaning                                                     |
|-------------------|------------|-------------------------------------------------------------|
| `AUTO_ROLLBACK`   | `OFF`      | `ON`, `OFF` or `ON_ERROR`                                   |
| `RBQUERY_LOG`     | `ROLLBACK` | `ROLLBACK`, `ALWAYS` or `NEVER`                             |
| `RB_SESSION_USER` | (empty)    | The user field to log, e.g. `email` or `id`, or `header:<name>` |

`AUTO_ROLLBACK_LOG=false` from v1.1 still works and means `RBQUERY_LOG=NEVER`. If both are set, `RBQUERY_LOG` wins.

To change `error_status`, the log path, the masked input fields (`log.hidden`) or the input length limit (`log.max_length`), publish the config:

```bash
php artisan vendor:publish --tag=auto-rollback-config
```

## How it works

- A global middleware, placed first in the stack, starts a transaction on every database connection the request opens. That covers connections opened before the request and connections opened lazily during it.
- Transactions in your own code (`DB::transaction()`, `beginTransaction()`/`commit()`) become savepoints inside the outer transaction. Nothing reaches the database until the package commits the outer transaction, which `ON` never does.
- The outer transaction is closed after all terminating callbacks. Writes from `dispatch()->afterResponse()` are therefore covered too.
- If your code leaves a transaction open, `ON_ERROR` discards it before committing, the same as Laravel would without the package.
- If the final commit fails, the response has already been sent as a success. The failure is therefore reported to your app's normal log through `report()`. Unless `RBQUERY_LOG=NEVER`, the request is also written to the query log.
- Only HTTP requests are affected. Artisan commands, queue workers and the scheduler are left alone.

## Query log

`RBQUERY_LOG` decides which requests have their queries logged:

| `RBQUERY_LOG`        | Logged requests                                                        |
|----------------------|------------------------------------------------------------------------|
| `ROLLBACK` (default) | Those whose writes were lost: rolled back, or a commit that failed     |
| `ALWAYS`             | Every request, whatever happened to it, including under `OFF`          |
| `NEVER`              | None                                                                   |

How this combines with each mode:

| `AUTO_ROLLBACK` | `ROLLBACK`             | `ALWAYS`       | `NEVER` |
|-----------------|------------------------|----------------|---------|
| `ON`            | Every request          | Every request  | Nothing |
| `ON_ERROR`      | Failed requests only   | Every request  | Nothing |
| `OFF`           | Nothing                | Every request  | Nothing |

Under `OFF` + `ALWAYS` the package only records queries. No transaction is opened, so every write is kept as it would be without the package, including the writes of a request that failed. Under `OFF` with `ROLLBACK` or `NEVER`, the package does not install its middleware at all.

Each logged request's input and queries are appended, with the queries' bindings substituted, to:

```
storage/logs/autorollback/{Ymd}/{route uri, with / replaced by ~}/{method}.txt
```

Each route gets one folder, and each method gets one file in it:

```
storage/logs/autorollback/
└── 20261105/
    ├── api~v1~website~header/
    │   ├── get.txt
    │   └── post.txt
    ├── api~v1~sites~articles/
    │   └── post.txt
    └── api~v1~sites~articles~{id}/
        ├── put.txt
        └── delete.txt
```

For example, a `POST` to `Route::post('v1/sites/articles', ...)` that failed in `ON_ERROR` mode is logged to `storage/logs/autorollback/20261104/v1~sites~articles/post.txt`:

```
================================================================================
[2026-11-04 10:15:32] POST /v1/sites/articles
Route  : v1/sites/articles
Mode   : ON_ERROR
Result : ROLLED BACK
Status : 422
IP     : 127.0.0.1
User   : budi@example.com
Query  : {"draft":"1"}
Body   : {"title":"","body":"Hello","api_key":"********"}
Files  : {"cover":"cover.jpg (84.2 KB, image/jpeg)"}
Error  : Illuminate\Validation\ValidationException: The title field is required.
--------------------------------------------------------------------------------
[mysql] (0.84 ms) select * from `users` where `id` = 1 limit 1
[mysql] (1.23 ms) insert into `articles` (`title`) values ('Hello')
--------------------------------------------------------------------------------
[mysql] rolled back
```

- `Result` is one of:
  - `ROLLED BACK`
  - `COMMIT FAILED`
  - `COMMITTED` (only logged under `ALWAYS`)
  - `NO TRANSACTION` (`OFF` + `ALWAYS`)
- The section listing each connection's outcome is left out under `OFF`, since no transaction was opened.
- The `Error` line appears only when the request threw an exception.
- `User` shows who made the request, and appears only when `RB_SESSION_USER` is set. For `RB_SESSION_USER=email`, the package uses the first of these that has a value:
  1. The `email` of the authenticated user, from any guard: session, JWT, Sanctum and so on. Only guards that already loaded their user during the request are asked, so no extra query runs.
  2. The session value `email`.

  Dot notation reaches nested values, e.g. `profile.name`. The line reads `User : -` when neither has a value, such as for a guest. A field named in `log.hidden` is masked.

  `RB_SESSION_USER=header:X-User-Email` reads that request header instead, and nothing else. Use it for a service behind a gateway, where the gateway passes the user along in a header rather than the service authenticating them. Header names are case-insensitive. Credential headers (`Authorization`, `Proxy-Authorization`, `Cookie`, `X-Api-Key`, `X-CSRF-Token`, `X-XSRF-Token`) and headers named in `log.hidden` are masked.

  A header is whatever the caller sent. If clients can reach the service directly, bypassing the gateway, they can put any value there.
- `Query`, `Body` and `Files` record the request **as it arrived**, before middleware such as `TrimStrings` or your own code changed it. Each line is left out when empty.
  - `Query` is the URL's query string. It is present for any method, GET included.
  - `Body` is the parsed JSON or form body. A body Laravel cannot parse, such as XML, plain text or broken JSON, is written as `Body : (raw) ...`.
  - `Files` gives each upload's name, size and type, never its contents.
  - Fields named in `log.hidden` are written as `********`, at any depth and in any case. By default this covers `password`, `token`, `api_key`, `secret`, `pin`, `otp`, `cvv` and similar fields. A raw body cannot be masked.
  - `Query` and `Body` longer than `log.max_length` characters (default 10,000) are cut short.
- The log contains every value your app received, wrote or looked up, including password hashes, tokens and personal data, except the masked fields above. Be careful with `ALWAYS` in production, and keep `storage/logs` private.
- The folder name comes from the route's **URI pattern**, not the request path. Route parameters therefore stay folder segments under their placeholder. `/api/v1/acme/orders` and `/api/v1/globex/orders` both go to `api~v1~{company}~orders/`. An optional `{period?}` becomes `{period_}`, because `?` is not allowed in a Windows file name.
- The **query string is never part of the folder**. `/api/v1/website/header?page=2&sort=desc` goes to `api~v1~website~header/get.txt`, and its parameters are recorded on the `Query` line instead.
- Requests that reach a `Route::fallback()` share one folder, named after Laravel's `{fallbackPlaceholder}`.
- The URI includes any route prefix. Routes in `routes/api.php` therefore log under `api~...`.
- The separator is `~` by default and can be changed with `log.separator` in the config. `~` is valid on every file system, needs no escaping in URLs, and means nothing to a shell in the middle of a word.
  - `#` works, but it starts a URL fragment, so links to the file (in an editor or a browser) break at the first `#`.
  - `%` works, but it starts URL encoding and Windows CMD variables.
  - `/ \ < > : " | ? *` are refused, and the separator falls back to `~`.
- A folder name longer than 200 characters is shortened and ends in a short hash, so it stays within file-system limits.
- A request that ran no queries writes nothing.

## Limits

These are properties of database transactions. The package cannot work around them.

- **DDL on MySQL/MariaDB** (`CREATE`, `ALTER`, `DROP`, `TRUNCATE`) commits implicitly. Changes made up to that point, and every write after it in the same request, are kept. The same happens if your code calls `DB::commit()` without a matching `beginTransaction()`. Either case is reported in the log as `NOT ROLLED BACK`. PostgreSQL rolls back DDL normally.
- **MyISAM tables** do not support transactions. Use InnoDB.
- **Auto-increment counters and sequences** are not reset.
- **In `ON` mode, nothing persists between requests.** Anything stored in the database is gone by the next request, including a registration followed by a login, or a session or token kept in a table.
- **Only the database is rolled back.** Cache, Redis, uploaded files, sent mail and calls to other services are not.
- **Queued jobs** that are pushed to a real queue run in a worker, outside the request.
  - In `ON` mode they will not find the rows the request created. Jobs and events marked `afterCommit` never run, because the transaction never commits.
  - In `ON_ERROR` mode, a job dispatched mid-request can reach a worker before the request commits. Mark such jobs `afterCommit` so they are pushed only once the request's writes are committed.
- **Longer transactions.** In `ON_ERROR` mode every write stays uncommitted until the request ends, so row locks are held for the whole request rather than just your own transaction. Expect more lock waits under concurrent load.
- **PostgreSQL aborts the whole transaction on a failed query.** If your code catches a query exception and carries on outside `DB::transaction()`, the next query fails with `current transaction is aborted`.
- **Every service needs the package.** In a microservice setup, each service rolls back only its own database.

## Testing

```bash
composer install
composer test
```

## License

MIT
