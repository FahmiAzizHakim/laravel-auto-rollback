<?php

namespace FahmiAzizHakim\LaravelAutoRollback\Tests\Feature;

use FahmiAzizHakim\LaravelAutoRollback\AutoRollback;
use FahmiAzizHakim\LaravelAutoRollback\Mode;
use FahmiAzizHakim\LaravelAutoRollback\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\TestWith;

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
        $this->assertStringContainsString('Mode   : ON', $log);
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

    public function test_in_production_on_runs_as_on_error_so_a_successful_request_is_committed(): void
    {
        $this->app['env'] = 'production';

        $this->postJson('v1/sites/articles')->assertOk();

        $this->assertSame(1, DB::table('articles')->count());
        $this->assertDirectoryDoesNotExist($this->logPath);
    }

    public function test_in_production_on_runs_as_on_error_so_a_failed_request_is_rolled_back(): void
    {
        Route::post('v1/sites/articles/fail', function () {
            DB::table('articles')->insert(['title' => 'Lost']);

            throw new \RuntimeException('Something broke');
        });

        $this->app['env'] = 'production';

        $this->postJson('v1/sites/articles/fail')->assertStatus(500);

        $this->assertSame(0, DB::table('articles')->count());
        $this->assertStringContainsString(
            'Mode   : ON_ERROR',
            file_get_contents($this->logFile('post', 'v1/sites/articles/fail.txt'))
        );
    }

    public function test_off_stays_off_in_production(): void
    {
        config(['auto-rollback.mode' => 'OFF']);
        $this->app['env'] = 'production';

        $this->postJson('v1/sites/articles')->assertOk();

        $this->assertSame(1, DB::table('articles')->count());
        $this->assertDirectoryDoesNotExist($this->logPath);
    }

    #[TestWith(['local'])]
    #[TestWith(['production'])]
    public function test_it_is_off_when_auto_rollback_is_not_set(string $env): void
    {
        putenv('AUTO_ROLLBACK');
        unset($_ENV['AUTO_ROLLBACK'], $_SERVER['AUTO_ROLLBACK']);

        // The package's own defaults, read fresh with the variable missing.
        config(['auto-rollback' => require __DIR__.'/../../config/auto-rollback.php']);
        $this->app['env'] = $env;

        $this->assertSame(Mode::Off, $this->app->make(AutoRollback::class)->mode());

        $this->postJson('v1/sites/articles')->assertOk();

        $this->assertSame(1, DB::table('articles')->count());
        $this->assertDirectoryDoesNotExist($this->logPath);
    }

    public function test_it_does_nothing_when_off(): void
    {
        config(['auto-rollback.mode' => 'OFF']);

        $this->postJson('v1/sites/articles')->assertOk();

        $this->assertSame(1, DB::table('articles')->count());
    }
}
