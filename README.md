# Laravel Auto Rollback

Run every HTTP request inside a database transaction and roll it back once the response has been sent. Pages and API calls behave normally, and the database is left exactly as it was.

It is built for **demo, staging, development and dry-run** environments, and it is **always off when `APP_ENV=production`**, whatever `.env` says.

Supports MySQL, MariaDB, PostgreSQL and SQLite, on Laravel 11 and 12.

## Installation

```bash
composer require fahmiazizhakim/laravel-auto-rollback
```

The service provider is auto-discovered. Then turn the package on in `.env`:

```env
AUTO_ROLLBACK=true
```

If you want to change the defaults, publish the config:

```bash
php artisan vendor:publish --tag=auto-rollback-config
```

| Variable            | Default | Meaning                                      |
|---------------------|---------|----------------------------------------------|
| `AUTO_ROLLBACK`     | `false` | Roll back every HTTP request's writes        |
| `AUTO_ROLLBACK_LOG` | `true`  | Write each request's queries to a log file   |

## How it works

- A global middleware, placed first in the stack, starts a transaction on every database connection the request opens. That covers connections opened before the request and connections opened lazily during it.
- Transactions in your own code (`DB::transaction()`, `beginTransaction()`/`commit()`) become savepoints inside the outer transaction, so their commits never reach the database.
- The rollback runs after all terminating callbacks. Writes from `dispatch()->afterResponse()` are therefore rolled back too.
- Only HTTP requests are affected. Artisan commands, queue workers and the scheduler are left alone.

## Query log

Every query a request ran is appended, with its bindings substituted, to:

```
storage/logs/autorollback/{Ymd}/{method}/{route uri}.txt
```

For example, a `POST` to `Route::post('v1/sites/articles', ...)` is logged to `storage/logs/autorollback/20261104/post/v1/sites/articles.txt`:

```
================================================================================
[2026-11-04 10:15:32] POST /v1/sites/articles
Route  : v1/sites/articles
Status : 201
IP     : 127.0.0.1
--------------------------------------------------------------------------------
[mysql] (0.84 ms) select * from `users` where `id` = 1 limit 1
[mysql] (1.23 ms) insert into `articles` (`title`) values ('Hello')
--------------------------------------------------------------------------------
[mysql] rolled back
```

- The file name comes from the route's **URI pattern**, not the request path. `/articles/1` and `/articles/2` both go to `articles/{id}.txt`.
- The URI includes any route prefix. Routes in `routes/api.php` therefore log under `api/...`.
- A request that ran no queries writes nothing.

## Limits

These are properties of database transactions. The package cannot work around them.

- **DDL on MySQL/MariaDB** (`CREATE`, `ALTER`, `DROP`, `TRUNCATE`) commits implicitly. Changes made up to that point, and every write after it in the same request, are kept. The same happens if your code calls `DB::commit()` without a matching `beginTransaction()`. Either case is reported in the log as `NOT ROLLED BACK`. PostgreSQL rolls back DDL normally.
- **MyISAM tables** do not support transactions. Use InnoDB.
- **Auto-increment counters and sequences** are not reset.
- **Nothing persists between requests.** Anything stored in the database is gone by the next request, including a registration followed by a login, or a session or token kept in a table.
- **Only the database is rolled back.** Cache, Redis, uploaded files, sent mail and calls to other services are not.
- **Queued jobs** that are pushed to a real queue run in a worker, outside the request. They will not find the rows the request created. Jobs and events marked `afterCommit` never run, because the transaction never commits.
- **PostgreSQL aborts the whole transaction on a failed query.** If your code catches a query exception and carries on outside `DB::transaction()`, the next query fails with `current transaction is aborted`.
- **Every service needs the package.** In a microservice setup, each service rolls back only its own database.

## Testing

```bash
composer install
composer test
```

## License

MIT
