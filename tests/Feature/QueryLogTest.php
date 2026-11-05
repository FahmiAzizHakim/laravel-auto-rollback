<?php

namespace FahmiAzizHakim\LaravelAutoRollback\Tests\Feature;

use FahmiAzizHakim\LaravelAutoRollback\AutoRollback;
use FahmiAzizHakim\LaravelAutoRollback\Http\Middleware\RollBackRequest;
use FahmiAzizHakim\LaravelAutoRollback\QueryLog;
use FahmiAzizHakim\LaravelAutoRollback\Tests\TestCase;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

class QueryLogTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::post('articles', function () {
            DB::table('articles')->insert(['title' => 'Kept']);

            return ['ok' => true];
        });

        Route::post('articles/exception', function () {
            DB::table('articles')->insert(['title' => 'Lost']);

            throw new RuntimeException('Something broke');
        });
    }

    protected function tearDown(): void
    {
        foreach (['RBQUERY_LOG', 'AUTO_ROLLBACK_LOG'] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }

        parent::tearDown();
    }

    public function test_rollback_is_the_default(): void
    {
        $this->reloadConfig();

        $this->assertSame(QueryLog::Rollback, $this->app->make(AutoRollback::class)->queryLog());
    }

    public function test_the_env_variable_is_read(): void
    {
        $this->reloadConfig(['RBQUERY_LOG' => 'ALWAYS']);

        $this->assertSame(QueryLog::Always, $this->app->make(AutoRollback::class)->queryLog());
    }

    public function test_auto_rollback_log_false_from_v1_1_still_means_never(): void
    {
        $this->reloadConfig(['AUTO_ROLLBACK_LOG' => 'false']);

        $this->assertSame(QueryLog::Never, $this->app->make(AutoRollback::class)->queryLog());
    }

    public function test_rbquery_log_wins_over_auto_rollback_log(): void
    {
        $this->reloadConfig(['RBQUERY_LOG' => 'ALWAYS', 'AUTO_ROLLBACK_LOG' => 'false']);

        $this->assertSame(QueryLog::Always, $this->app->make(AutoRollback::class)->queryLog());
    }

    public function test_a_config_published_from_v1_1_with_logging_off_still_means_never(): void
    {
        config(['auto-rollback.log' => ['enabled' => false, 'path' => $this->logPath]]);

        $this->assertSame(QueryLog::Never, $this->app->make(AutoRollback::class)->queryLog());
    }

    public function test_rollback_logs_a_failed_request_but_not_a_committed_one(): void
    {
        config(['auto-rollback.mode' => 'ON_ERROR', 'auto-rollback.log.query' => 'ROLLBACK']);

        $this->postJson('articles')->assertOk();
        $this->postJson('articles/exception')->assertStatus(500);

        $this->assertFileDoesNotExist($this->logFile('post', 'articles'));
        $this->assertStringContainsString(
            'Result : ROLLED BACK',
            file_get_contents($this->logFile('post', 'articles~exception'))
        );
    }

    public function test_always_logs_a_committed_request_too(): void
    {
        config(['auto-rollback.mode' => 'ON_ERROR', 'auto-rollback.log.query' => 'ALWAYS']);

        $this->postJson('articles')->assertOk();
        $this->postJson('articles/exception')->assertStatus(500);

        $this->assertSame(['Kept'], DB::table('articles')->pluck('title')->all());

        $committed = file_get_contents($this->logFile('post', 'articles'));
        $this->assertStringContainsString('Result : COMMITTED', $committed);
        $this->assertStringContainsString("values ('Kept')", $committed);
        $this->assertStringContainsString('[testing] committed', $committed);

        $this->assertStringContainsString(
            'Result : ROLLED BACK',
            file_get_contents($this->logFile('post', 'articles~exception'))
        );
    }

    public function test_always_logs_every_request_in_on_mode(): void
    {
        config(['auto-rollback.mode' => 'ON', 'auto-rollback.log.query' => 'ALWAYS']);

        $this->postJson('articles')->assertOk();

        $this->assertStringContainsString(
            'Result : ROLLED BACK',
            file_get_contents($this->logFile('post', 'articles'))
        );
    }

    public function test_never_logs_nothing_even_when_a_request_is_rolled_back(): void
    {
        config(['auto-rollback.mode' => 'ON_ERROR', 'auto-rollback.log.query' => 'NEVER']);

        $this->postJson('articles/exception')->assertStatus(500);

        $this->assertSame(0, DB::table('articles')->count());
        $this->assertDirectoryDoesNotExist($this->logPath);
    }

    public function test_always_logs_every_request_under_off_without_touching_its_writes(): void
    {
        config(['auto-rollback.mode' => 'OFF', 'auto-rollback.log.query' => 'ALWAYS']);

        $this->postJson('articles')->assertOk();
        $this->postJson('articles/exception')->assertStatus(500);

        // No transaction, so both inserts were kept, the failed request's too.
        $this->assertSame(['Kept', 'Lost'], DB::table('articles')->pluck('title')->all());

        $committed = file_get_contents($this->logFile('post', 'articles'));
        $this->assertStringContainsString('Mode   : OFF', $committed);
        $this->assertStringContainsString('Result : NO TRANSACTION', $committed);
        $this->assertStringContainsString("values ('Kept')", $committed);

        $failed = file_get_contents($this->logFile('post', 'articles~exception'));
        $this->assertStringContainsString('Result : NO TRANSACTION', $failed);
        $this->assertStringContainsString('Error  : RuntimeException: Something broke', $failed);
    }

    #[TestWith(['ROLLBACK'])]
    #[TestWith(['NEVER'])]
    public function test_off_logs_nothing_unless_always(string $queryLog): void
    {
        config(['auto-rollback.mode' => 'OFF', 'auto-rollback.log.query' => $queryLog]);

        $this->postJson('articles/exception')->assertStatus(500);

        $this->assertSame(1, DB::table('articles')->count());
        $this->assertDirectoryDoesNotExist($this->logPath);
    }

    public function test_off_and_always_at_boot_installs_the_middleware(): void
    {
        $this->assertTrue($this->app->make(HttpKernel::class)->hasMiddleware(RollBackRequest::class));

        $this->postJson('articles')->assertOk();

        $this->assertSame(1, DB::table('articles')->count());
        $this->assertFileExists($this->logFile('post', 'articles'));
    }

    public function test_off_without_always_at_boot_leaves_the_app_alone(): void
    {
        $this->assertFalse($this->app->make(HttpKernel::class)->hasMiddleware(RollBackRequest::class));
    }

    /**
     * The *_at_boot_* tests need their config in place before the provider
     * boots. Testbench runs #[DefineEnvironment] before defineEnvironment(),
     * so the parent's ON would win; hence set here, by test name.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $queryLog = [
            'test_off_and_always_at_boot_installs_the_middleware' => 'ALWAYS',
            'test_off_without_always_at_boot_leaves_the_app_alone' => 'ROLLBACK',
        ][$this->name()] ?? null;

        if ($queryLog !== null) {
            $app['config']->set('auto-rollback.mode', 'OFF');
            $app['config']->set('auto-rollback.log.query', $queryLog);
        }
    }

    /**
     * Load the package's own config file afresh with these variables set.
     *
     * @param  array<string, string>  $env
     */
    protected function reloadConfig(array $env = []): void
    {
        foreach (['RBQUERY_LOG', 'AUTO_ROLLBACK_LOG'] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }

        foreach ($env as $name => $value) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $_SERVER[$name] = $value;
        }

        config(['auto-rollback' => require __DIR__.'/../../config/auto-rollback.php']);
    }
}
