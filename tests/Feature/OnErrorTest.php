<?php

namespace FahmiAzizHakim\LaravelAutoRollback\Tests\Feature;

use FahmiAzizHakim\LaravelAutoRollback\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class OnErrorTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auto-rollback.mode', 'ON_ERROR');
    }

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

        Route::post('articles/validation', function () {
            DB::table('articles')->insert(['title' => 'Lost']);

            throw ValidationException::withMessages(['title' => 'Invalid']);
        });

        Route::post('articles/server-error', function () {
            DB::table('articles')->insert(['title' => 'Lost']);

            return response()->json(['ok' => false], 503);
        });

        Route::post('articles/client-error', function () {
            DB::table('articles')->insert(['title' => 'Kept']);

            return response()->json(['ok' => false], 404);
        });

        Route::post('articles/left-open', function () {
            DB::table('articles')->insert(['title' => 'Kept']);
            DB::beginTransaction();
            DB::table('articles')->insert(['title' => 'Never committed']);

            return ['ok' => true];
        });
    }

    public function test_a_successful_request_is_committed_and_not_logged(): void
    {
        $this->postJson('articles')->assertOk();

        $this->assertSame(['Kept'], DB::table('articles')->pluck('title')->all());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertDirectoryDoesNotExist($this->logPath);
    }

    public function test_an_exception_rolls_back_and_is_logged(): void
    {
        $this->postJson('articles/exception')->assertStatus(500);

        $this->assertSame(0, DB::table('articles')->count());

        $log = file_get_contents($this->logFile('post', 'articles~exception'));
        $this->assertStringContainsString('Mode   : ON_ERROR', $log);
        $this->assertStringContainsString('Error  : RuntimeException: Something broke', $log);
        $this->assertStringContainsString('[testing] rolled back', $log);
    }

    public function test_a_validation_exception_rolls_back(): void
    {
        $this->postJson('articles/validation')->assertStatus(422);

        $this->assertSame(0, DB::table('articles')->count());
        $this->assertStringContainsString(
            'Error  : Illuminate\Validation\ValidationException',
            file_get_contents($this->logFile('post', 'articles~validation'))
        );
    }

    public function test_a_server_error_response_without_an_exception_rolls_back(): void
    {
        $this->postJson('articles/server-error')->assertStatus(503);

        $this->assertSame(0, DB::table('articles')->count());
        $this->assertFileExists($this->logFile('post', 'articles~server-error'));
    }

    public function test_a_client_error_response_without_an_exception_is_committed(): void
    {
        $this->postJson('articles/client-error')->assertNotFound();

        $this->assertSame(1, DB::table('articles')->count());
    }

    public function test_the_error_status_threshold_is_configurable(): void
    {
        config(['auto-rollback.error_status' => 400]);

        $this->postJson('articles/client-error')->assertNotFound();

        $this->assertSame(0, DB::table('articles')->count());
    }

    public function test_a_transaction_the_app_left_open_is_discarded_as_it_would_be_without_the_package(): void
    {
        $this->postJson('articles/left-open')->assertOk();

        $this->assertSame(['Kept'], DB::table('articles')->pluck('title')->all());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_each_request_is_judged_on_its_own_response(): void
    {
        $this->postJson('articles/exception')->assertStatus(500);
        $this->postJson('articles')->assertOk();
        $this->postJson('articles/exception')->assertStatus(500);

        $this->assertSame(['Kept'], DB::table('articles')->pluck('title')->all());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_it_still_runs_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->postJson('articles/exception')->assertStatus(500);
        $this->postJson('articles')->assertOk();

        $this->assertSame(['Kept'], DB::table('articles')->pluck('title')->all());
    }
}
