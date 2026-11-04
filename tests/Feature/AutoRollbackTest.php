<?php

namespace FahmiAzizHakim\LaravelAutoRollback\Tests\Feature;

use FahmiAzizHakim\LaravelAutoRollback\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

class AutoRollbackTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::post('v1/sites/articles', function () {
            DB::table('articles')->insert(['title' => 'Hello']);

            return ['count' => DB::table('articles')->count()];
        });

        Route::put('v1/sites/articles/{id?}', function () {
            DB::transaction(fn () => DB::table('articles')->insert(['title' => 'Nested']));

            return ['count' => DB::table('articles')->count()];
        });

        Route::get('/', fn () => ['count' => DB::table('articles')->count()]);

        Route::delete('v1/sites/articles', function () {
            DB::table('articles')->insert(['title' => 'Committed']);
            DB::commit();

            return ['count' => DB::table('articles')->count()];
        });
    }

    public function test_writes_are_visible_during_the_request_and_rolled_back_after_it(): void
    {
        $this->postJson('v1/sites/articles')->assertOk()->assertJson(['count' => 1]);

        $this->assertSame(0, DB::table('articles')->count());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_the_apps_own_transactions_are_rolled_back_too(): void
    {
        $this->putJson('v1/sites/articles/5')->assertOk()->assertJson(['count' => 1]);

        $this->assertSame(0, DB::table('articles')->count());
    }

    public function test_queries_are_logged_by_date_method_and_route(): void
    {
        $this->postJson('v1/sites/articles')->assertOk();

        $file = $this->logFile('post', 'v1/sites/articles.txt');

        $this->assertFileExists($file);
        $log = file_get_contents($file);
        $this->assertStringContainsString("insert into \"articles\" (\"title\") values ('Hello')", $log);
        $this->assertStringContainsString('[testing] rolled back', $log);
        $this->assertStringContainsString('Status : 200', $log);
    }

    public function test_the_log_uses_the_route_pattern_not_the_request_path(): void
    {
        $this->putJson('v1/sites/articles/5')->assertOk();

        $this->assertFileExists($this->logFile('put', 'v1/sites/articles/{id_}.txt'));
    }

    public function test_requests_append_to_the_same_log_file(): void
    {
        $this->postJson('v1/sites/articles')->assertOk();
        $this->postJson('v1/sites/articles')->assertOk();

        $log = file_get_contents($this->logFile('post', 'v1/sites/articles.txt'));
        $this->assertSame(2, substr_count($log, 'insert into'));
    }

    public function test_the_root_route_logs_to_index(): void
    {
        $this->getJson('/')->assertOk();

        $this->assertFileExists($this->logFile('get', 'index.txt'));
    }

    public function test_a_transaction_committed_mid_request_is_reported(): void
    {
        $this->deleteJson('v1/sites/articles')->assertOk();

        $this->assertSame(1, DB::table('articles')->count());
        $this->assertStringContainsString(
            '[testing] NOT ROLLED BACK',
            file_get_contents($this->logFile('delete', 'v1/sites/articles.txt'))
        );
    }

    public function test_nothing_is_logged_when_logging_is_off(): void
    {
        config(['auto-rollback.log.enabled' => false]);

        $this->postJson('v1/sites/articles')->assertOk();

        $this->assertSame(0, DB::table('articles')->count());
        $this->assertDirectoryDoesNotExist($this->logPath);
    }

    public function test_it_is_forced_off_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->postJson('v1/sites/articles')->assertOk();

        $this->assertSame(1, DB::table('articles')->count());
        $this->assertDirectoryDoesNotExist($this->logPath);
    }

    public function test_it_does_nothing_when_disabled(): void
    {
        config(['auto-rollback.enabled' => false]);

        $this->postJson('v1/sites/articles')->assertOk();

        $this->assertSame(1, DB::table('articles')->count());
    }

    protected function logFile(string $method, string $route): string
    {
        return implode(DIRECTORY_SEPARATOR, [$this->logPath, now()->format('Ymd'), $method, str_replace('/', DIRECTORY_SEPARATOR, $route)]);
    }
}
