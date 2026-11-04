<?php

namespace FahmiAzizHakim\LaravelAutoRollback;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AutoRollback
{
    /**
     * Whether a request is currently running inside the rollback transaction.
     */
    protected bool $active = false;

    /**
     * The connections a transaction was opened on, keyed by object id so a
     * connection that is purged and re-created mid-request is wrapped again.
     *
     * @var array<int, \Illuminate\Database\Connection>
     */
    protected array $connections = [];

    /**
     * @var list<string>
     */
    protected array $queries = [];

    public function __construct(protected Application $app) {}

    public function enabled(): bool
    {
        return (bool) $this->app['config']->get('auto-rollback.enabled')
            && ! $this->app->isProduction();
    }

    public function active(): bool
    {
        return $this->active;
    }

    /**
     * Open a transaction on every connection already established, and on every
     * one established from now on (see wrap()) until finish() is called.
     */
    public function start(): void
    {
        // A long-running server (Octane) whose last request never terminated.
        if ($this->active) {
            $this->rollBackAll();
        }

        $this->reset();
        $this->active = true;

        foreach ($this->app['db']->getConnections() as $connection) {
            $this->wrap($connection);
        }
    }

    public function wrap(Connection $connection): void
    {
        if (! $this->active || isset($this->connections[spl_object_id($connection)])) {
            return;
        }

        // Left to throw: a query must never run outside the transaction.
        $connection->beginTransaction();

        $this->connections[spl_object_id($connection)] = $connection;
    }

    public function record(QueryExecuted $query): void
    {
        if (! $this->active) {
            return;
        }

        $this->queries[] = sprintf('[%s] (%.2f ms) %s', $query->connectionName, $query->time, $this->rawSql($query));
    }

    public function finish(Request $request, ?Response $response = null): void
    {
        if (! $this->active) {
            return;
        }

        $results = $this->rollBackAll();

        if ($this->app['config']->get('auto-rollback.log.enabled')) {
            $this->writeLog($request, $response, $results);
        }

        $this->reset();
    }

    /**
     * @return array<string, string> the outcome on each connection, by name
     */
    protected function rollBackAll(): array
    {
        $results = [];

        foreach ($this->connections as $connection) {
            $results[$connection->getName()] = $this->rollBack($connection);
        }

        $this->active = false;

        return $results;
    }

    protected function rollBack(Connection $connection): string
    {
        try {
            // MySQL and MariaDB commit implicitly on DDL (CREATE, ALTER, DROP,
            // TRUNCATE ...), and a DB::commit() with no matching
            // beginTransaction() commits ours. PDO reports the server's real
            // state since PHP 8.0.
            $committed = ! $connection->getPdo()->inTransaction();

            // Back to level 0 either way, so Laravel's own count is right again.
            $connection->rollBack(0);

            return $committed
                ? 'NOT ROLLED BACK - the transaction was committed before the request ended (DDL statement, or an unmatched DB::commit()); changes up to that point were kept'
                : 'rolled back';
        } catch (Throwable $e) {
            return 'ROLLBACK FAILED - '.$e->getMessage();
        }
    }

    protected function rawSql(QueryExecuted $query): string
    {
        try {
            return $query->toRawSql();
        } catch (Throwable) {
            return $query->sql.' -- bindings: '.json_encode($query->bindings);
        }
    }

    /**
     * @param  array<string, string>  $results
     */
    protected function writeLog(Request $request, ?Response $response, array $results): void
    {
        if ($this->queries === []) {
            return;
        }

        $route = $request->route();
        $uri = $route instanceof Route ? $route->uri() : $request->path();

        $lines = [
            str_repeat('=', 80),
            sprintf('[%s] %s /%s', now()->format('Y-m-d H:i:s'), $request->method(), ltrim($request->path(), '/')),
            'Route  : '.$uri,
            'Status : '.($response?->getStatusCode() ?? '-'),
            'IP     : '.$request->ip(),
            str_repeat('-', 80),
            ...$this->queries,
            str_repeat('-', 80),
            ...array_map(fn ($name, $result) => "[{$name}] {$result}", array_keys($results), $results),
            '',
        ];

        $file = $this->logFile($request->method(), $uri);

        File::ensureDirectoryExists(dirname($file));
        File::append($file, implode(PHP_EOL, $lines).PHP_EOL);
    }

    /**
     * {path}/{Ymd}/{method}/{route uri}.txt
     */
    protected function logFile(string $method, string $uri): string
    {
        $segments = array_filter(
            explode('/', $uri),
            fn ($segment) => ! in_array($segment, ['', '.', '..'], true)
        );

        // Characters Windows refuses in a file name, e.g. the ? of {id?}.
        $segments = array_map(
            fn ($segment) => preg_replace('/[<>:"\\\\|?*\x00-\x1F]/', '_', $segment),
            $segments
        );

        return implode(DIRECTORY_SEPARATOR, [
            rtrim($this->app['config']->get('auto-rollback.log.path'), '\\/'),
            now()->format('Ymd'),
            strtolower($method),
            ($segments === [] ? 'index' : implode(DIRECTORY_SEPARATOR, $segments)).'.txt',
        ]);
    }

    protected function reset(): void
    {
        $this->connections = [];
        $this->queries = [];
    }
}
