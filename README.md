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

| `AUTO_ROLLBACK` | What happens                                                                  | Logged                 |
|-----------------|-------------------------------------------------------------------------------|------------------------|
| `ON`            | Every request is rolled back                                                  | Every request          |
| `OFF` (default) | Requests run normally; no transaction is opened                               | Nothing                |
| `ON_ERROR`      | Successful requests are committed; requests that failed are rolled back       | Rolled-back requests   |

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

| Variable            | Default | Meaning                                         |
|---------------------|---------|-------------------------------------------------|
| `AUTO_ROLLBACK`     | `OFF`   | `ON`, `OFF` or `ON_ERROR`                       |
| `AUTO_ROLLBACK_LOG` | `true`  | Write rolled-back requests' queries to a file   |

To change `error_status` or the log path, publish the config:

```bash
php artisan vendor:publish --tag=auto-rollback-config
```

## How it works

- A global middleware, placed first in the stack, starts a transaction on every database connection the request opens. That covers connections opened before the request and connections opened lazily during it.
- Transactions in your own code (`DB::transaction()`, `beginTransaction()`/`commit()`) become savepoints inside the outer transaction. Nothing reaches the database until the package commits the outer transaction, which `ON` never does.
- The outer transaction is closed after all terminating callbacks. Writes from `dispatch()->afterResponse()` are therefore covered too.
- If your code leaves a transaction open, `ON_ERROR` discards it before committing, the same as Laravel would without the package.
- If the final commit fails, the response has already been sent as a success. The failure is therefore reported to your app's normal log through `report()`, and the request is written to the query log.
- Only HTTP requests are affected. Artisan commands, queue workers and the scheduler are left alone.

## Query log

Every query a rolled-back request ran is appended, with its bindings substituted, to:

```
storage/logs/autorollback/{Ymd}/{method}/{route uri}.txt
```

For example, a `POST` to `Route::post('v1/sites/articles', ...)` that failed in `ON_ERROR` mode is logged to `storage/logs/autorollback/20261104/post/v1/sites/articles.txt`:

```
================================================================================
[2026-11-04 10:15:32] POST /v1/sites/articles
Route  : v1/sites/articles
Mode   : ON_ERROR
Status : 422
IP     : 127.0.0.1
Error  : Illuminate\Validation\ValidationException: The title field is required.
--------------------------------------------------------------------------------
[mysql] (0.84 ms) select * from `users` where `id` = 1 limit 1
[mysql] (1.23 ms) insert into `articles` (`title`) values ('Hello')
--------------------------------------------------------------------------------
[mysql] rolled back
```

- The `Error` line appears only when the request threw an exception.
- The file name comes from the route's **URI pattern**, not the request path. `/articles/1` and `/articles/2` both go to `articles/{id}.txt`.
- The URI includes any route prefix. Routes in `routes/api.php` therefore log under `api/...`.
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
