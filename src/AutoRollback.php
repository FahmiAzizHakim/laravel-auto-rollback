<?php

namespace FahmiAzizHakim\LaravelAutoRollback;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AutoRollback
{
    /**
     * Masked in the logged request input when log.hidden is missing, as in a
     * config file published from v1.2 or earlier.
     */
    public const HIDDEN_INPUT = [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'token', 'access_token', 'refresh_token', 'api_key', 'secret', 'client_secret',
        'pin', 'otp', 'cvv', 'card_number',
    ];

    /**
     * Headers that carry credentials, masked whenever RB_SESSION_USER names one.
     */
    public const SENSITIVE_HEADERS = [
        'authorization', 'proxy-authorization', 'cookie', 'x-api-key', 'x-csrf-token', 'x-xsrf-token',
    ];

    /**
     * The mode the current request started in, or null outside one.
     */
    protected ?Mode $mode = null;

    /**
     * Which of the current request's outcomes get its queries logged.
     */
    protected QueryLog $queryLog = QueryLog::Rollback;

    /**
     * The request the transaction was started for.
     */
    protected ?Request $request = null;

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

    /**
     * The request's query string and parsed body as they arrived, before
     * middleware such as TrimStrings or the app itself changed them.
     *
     * @var array{query?: array<mixed>, body?: array<mixed>}
     */
    protected array $input = [];

    public function __construct(protected Application $app) {}

    public function mode(): Mode
    {
        $mode = Mode::fromConfig($this->app['config']->get('auto-rollback.mode'));

        // Production never throws away a successful request's writes.
        if ($mode === Mode::On && $this->app->isProduction()) {
            return Mode::OnError;
        }

        return $mode;
    }

    public function enabled(): bool
    {
        return $this->mode() !== Mode::Off;
    }

    /**
     * Whether requests go through the package at all: to run in a
     * transaction, or under OFF + ALWAYS only to have their queries logged.
     */
    public function handles(): bool
    {
        return $this->enabled() || $this->queryLog() === QueryLog::Always;
    }

    public function queryLog(): QueryLog
    {
        $config = $this->app['config'];

        // A config file published from v1.1 replaces the whole log array, so
        // it has log.enabled and no log.query.
        if ($config->get('auto-rollback.log.enabled') === false) {
            return QueryLog::Never;
        }

        return QueryLog::fromConfig($config->get('auto-rollback.log.query'));
    }

    public function active(): bool
    {
        return $this->mode !== null;
    }

    /**
     * Open a transaction on every connection already established, and on every
     * one established from now on (see wrap()) until finish() is called. Under
     * OFF no transaction is opened and queries are only recorded.
     */
    public function start(Request $request): void
    {
        // A long-running server (Octane) whose last request never terminated.
        if ($this->active()) {
            $this->rollBackAll();
        }

        $this->reset();
        $this->mode = $this->mode();
        $this->queryLog = $this->queryLog();
        $this->request = $request;

        if ($this->queryLog !== QueryLog::Never) {
            // JSON bodies are already in $request->request; Laravel puts them there.
            $this->input = ['query' => $request->query->all(), 'body' => $request->request->all()];
        }

        foreach ($this->app['db']->getConnections() as $connection) {
            $this->wrap($connection);
        }
    }

    public function wrap(Connection $connection): void
    {
        if (! $this->active() || $this->mode === Mode::Off || isset($this->connections[spl_object_id($connection)])) {
            return;
        }

        // Left to throw: a query must never run outside the transaction.
        $connection->beginTransaction();

        $this->connections[spl_object_id($connection)] = $connection;
    }

    public function record(QueryExecuted $query): void
    {
        if (! $this->active() || $this->queryLog === QueryLog::Never) {
            return;
        }

        $this->queries[] = sprintf('[%s] (%.2f ms) %s', $query->connectionName, $query->time, $this->rawSql($query));
    }

    public function finish(Request $request, ?Response $response = null): void
    {
        // Laravel never clears its terminating callbacks, so in a process that
        // serves several requests (feature tests, long-running servers) the
        // callbacks of earlier requests run again at the end of this one.
        if (! $this->active() || $request !== $this->request) {
            return;
        }

        $mode = $this->mode;
        $exception = $this->exceptionFrom($response);
        $failed = $exception !== null
            || ($response?->getStatusCode() ?? 500) >= $this->app['config']->get('auto-rollback.error_status', 500);

        if ($mode === Mode::Off) {
            // Here only to log: the queries ran, and were kept, as they would
            // have been without the package.
            $rolledBack = false;
            $results = [];
        } else {
            $rolledBack = $mode === Mode::On || $failed;
            $results = $rolledBack ? $this->rollBackAll() : $this->commitAll();
        }

        // A failed commit loses the request's writes just as a rollback does.
        $commitFailed = (bool) preg_grep('/^COMMIT FAILED/', $results);
        $outcome = match (true) {
            $mode === Mode::Off => 'NO TRANSACTION',
            $rolledBack => 'ROLLED BACK',
            $commitFailed => 'COMMIT FAILED',
            default => 'COMMITTED',
        };

        $logged = match ($this->queryLog) {
            QueryLog::Always => true,
            QueryLog::Rollback => $rolledBack || $commitFailed,
            QueryLog::Never => false,
        };

        if ($logged) {
            $this->writeLog($request, $response, $mode, $outcome, $exception, $results);
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

        $this->mode = null;

        return $results;
    }

    /**
     * @return array<string, string> the outcome on each connection, by name
     */
    protected function commitAll(): array
    {
        $results = [];

        foreach ($this->connections as $connection) {
            $results[$connection->getName()] = $this->commit($connection);
        }

        $this->mode = null;

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

    protected function commit(Connection $connection): string
    {
        try {
            // Already committed mid-request, as in rollBack(). Without this
            // package those writes would have been kept too.
            if (! $connection->getPdo()->inTransaction()) {
                $connection->rollBack(0);

                return 'committed (mid-request, by a DDL statement or an unmatched DB::commit())';
            }

            // A transaction the app opened and never closed. Without this
            // package it would have been discarded with the connection, so it
            // is here too.
            $note = '';

            if ($connection->transactionLevel() > 1) {
                $connection->rollBack(1);
                $note = ' (a transaction the app left open was rolled back first)';
            }

            $connection->commit();

            return 'committed'.$note;
        } catch (Throwable $e) {
            // The response has already gone out as a success, so this is the
            // only place the failure shows up.
            report($e);

            try {
                $connection->rollBack(0);
            } catch (Throwable) {
                // Nothing left to undo.
            }

            return 'COMMIT FAILED - '.$e->getMessage();
        }
    }

    /**
     * The exception the request failed with, as attached to the response by
     * Laravel when it rendered it.
     */
    protected function exceptionFrom(?Response $response): ?Throwable
    {
        $exception = $response->exception ?? null;

        return $exception instanceof Throwable ? $exception : null;
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
    protected function writeLog(Request $request, ?Response $response, Mode $mode, string $outcome, ?Throwable $exception, array $results): void
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
            'Mode   : '.strtoupper($mode->value),
            'Result : '.$outcome,
            'Status : '.($response?->getStatusCode() ?? '-'),
            'IP     : '.$request->ip(),
            ...$this->userLines($request),
            ...$this->inputLines($request),
            ...($exception ? ['Error  : '.get_class($exception).': '.$exception->getMessage()] : []),
            str_repeat('-', 80),
            ...$this->queries,
            ...($results === [] ? [] : [
                str_repeat('-', 80),
                ...array_map(fn ($name, $result) => "[{$name}] {$result}", array_keys($results), $results),
            ]),
            '',
        ];

        $file = $this->logFile($request->method(), $uri);

        File::ensureDirectoryExists(dirname($file));
        File::append($file, implode(PHP_EOL, $lines).PHP_EOL);
    }

    /**
     * {path}/{Ymd}/{route uri, its slashes replaced by log.separator}/{method}.txt
     *
     * e.g. api/v1/website/header -> 20261105/api~v1~website~header/get.txt,
     * one folder per route rather than one per segment.
     */
    protected function logFile(string $method, string $uri): string
    {
        $separator = $this->separator();

        $segments = array_filter(
            explode('/', $uri),
            fn ($segment) => ! in_array($segment, ['', '.', '..'], true)
        );

        // Characters Windows refuses in a file name, e.g. the ? of {id?}.
        $segments = array_map(
            fn ($segment) => preg_replace('/[<>:"\\\\|?*\x00-\x1F]/', '_', $segment),
            $segments
        );

        $folder = $segments === [] ? 'index' : implode($separator, $segments);

        // Most file systems cap a single name at 255 bytes.
        if (strlen($folder) > 200) {
            $folder = substr($folder, 0, 191).$separator.substr(md5($folder), 0, 8);
        }

        return implode(DIRECTORY_SEPARATOR, [
            rtrim($this->app['config']->get('auto-rollback.log.path'), '\\/'),
            now()->format('Ymd'),
            $folder,
            strtolower($method).'.txt',
        ]);
    }

    /**
     * log.separator, or ~ when it is missing or holds a character that would
     * make a folder or an invalid name.
     */
    protected function separator(): string
    {
        $separator = (string) $this->app['config']->get('auto-rollback.log.separator', '~');

        if ($separator === '' || preg_match('/[\/\\\\<>:"|?*\x00-\x1F]/', $separator)) {
            return '~';
        }

        return $separator;
    }

    /**
     * The User line: the log.user (RB_SESSION_USER) field of whoever made the
     * request, or "-" when there was nobody. Left out when log.user is empty.
     *
     * "header:<name>" reads that request header instead, for services that are
     * told who the user is by a gateway rather than authenticating them.
     *
     * @return list<string>
     */
    protected function userLines(Request $request): array
    {
        $field = trim((string) $this->app['config']->get('auto-rollback.log.user'));

        if (str_starts_with(strtolower($field), 'header:')) {
            $header = trim(substr($field, strlen('header:')));

            if ($header === '') {
                return [];
            }

            $value = $request->header($header);
            $value = $value === '' ? null : $value;
            $hidden = in_array(strtolower($header), static::SENSITIVE_HEADERS, true)
                || in_array(strtolower($header), $this->hiddenFields(), true);
        } elseif ($field !== '') {
            $value = $this->userValue($request, $field);
            $hidden = in_array(strtolower($field), $this->hiddenFields(), true);
        } else {
            return [];
        }

        if ($value !== null && $hidden) {
            $value = '********';
        }

        return ['User   : '.match (true) {
            $value === null => '-',
            is_string($value), is_int($value), is_float($value) => $this->truncate((string) $value),
            default => $this->truncate((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)),
        }];
    }

    /**
     * The field on the authenticated user, from any guard, or else the session
     * value of that name. Dot notation reaches nested values.
     */
    protected function userValue(Request $request, string $field): mixed
    {
        try {
            foreach (array_keys((array) $this->app['config']->get('auth.guards', [])) as $name) {
                $guard = $this->app['auth']->guard($name);

                // Only a guard that already has its user: asking one that has
                // not would run its lookup, and its queries, now.
                if (method_exists($guard, 'hasUser') && $guard->hasUser()
                    && ($value = data_get($guard->user(), $field)) !== null) {
                    return $value;
                }
            }
        } catch (Throwable) {
            // No auth, or a guard that cannot be built outside its middleware.
        }

        try {
            if ($request->hasSession()) {
                return $request->session()->get($field);
            }
        } catch (Throwable) {
            // A session that cannot be read any more.
        }

        return null;
    }

    /**
     * @return list<string>
     */
    protected function hiddenFields(): array
    {
        return array_map('strtolower', $this->app['config']->get('auto-rollback.log.hidden', static::HIDDEN_INPUT));
    }

    /**
     * The Query, Body and Files lines, each left out when empty.
     *
     * @return list<string>
     */
    protected function inputLines(Request $request): array
    {
        $lines = [];

        if (! empty($this->input['query'])) {
            $lines[] = 'Query  : '.$this->encode($this->mask($this->input['query']));
        }

        if (! empty($this->input['body'])) {
            $lines[] = 'Body   : '.$this->encode($this->mask($this->input['body']));
        } elseif (($raw = $this->rawBody($request)) !== '') {
            $lines[] = 'Body   : (raw) '.$this->truncate($raw);
        }

        if ($files = $request->allFiles()) {
            $lines[] = 'Files  : '.$this->encode($this->describeFiles($files));
        }

        return $lines;
    }

    /**
     * A body Laravel did not parse, such as XML, plain text or broken JSON.
     * Multipart bodies are left to Body and Files, since PHP consumes them
     * itself.
     */
    protected function rawBody(Request $request): string
    {
        if (str_contains((string) $request->header('Content-Type'), 'multipart/')) {
            return '';
        }

        try {
            $raw = trim((string) $request->getContent());
        } catch (Throwable) {
            return '';
        }

        // Valid JSON that parsed to nothing, such as the [] of an empty body.
        if ($request->isJson()) {
            json_decode($raw);

            if (json_last_error() === JSON_ERROR_NONE) {
                return '';
            }
        }

        return $raw;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    protected function mask(array $data): array
    {
        $hidden = $this->hiddenFields();

        $mask = function (array $data) use (&$mask, $hidden): array {
            foreach ($data as $key => $value) {
                if (is_string($key) && in_array(strtolower($key), $hidden, true)) {
                    $data[$key] = '********';
                } elseif (is_array($value)) {
                    $data[$key] = $mask($value);
                }
            }

            return $data;
        };

        return $mask($data);
    }

    /**
     * @param  array<mixed>  $files
     * @return array<mixed>
     */
    protected function describeFiles(array $files): array
    {
        return array_map(fn ($file) => match (true) {
            is_array($file) => $this->describeFiles($file),
            $file instanceof UploadedFile => sprintf(
                '%s (%s, %s)',
                $file->getClientOriginalName(),
                $this->humanSize((int) $file->getSize()),
                $file->getClientMimeType()
            ),
            default => get_debug_type($file),
        }, $files);
    }

    protected function humanSize(int $bytes): string
    {
        return match (true) {
            $bytes >= 1048576 => sprintf('%.1f MB', $bytes / 1048576),
            $bytes >= 1024 => sprintf('%.1f KB', $bytes / 1024),
            default => $bytes.' B',
        };
    }

    /**
     * @param  array<mixed>  $data
     */
    protected function encode(array $data): string
    {
        return $this->truncate((string) json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        ));
    }

    protected function truncate(string $value): string
    {
        $max = (int) $this->app['config']->get('auto-rollback.log.max_length', 10000);

        if ($max <= 0 || mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max).sprintf(' ... (truncated, %d characters in all)', mb_strlen($value));
    }

    protected function reset(): void
    {
        $this->input = [];
        $this->mode = null;
        $this->request = null;
        $this->connections = [];
        $this->queries = [];
    }
}
