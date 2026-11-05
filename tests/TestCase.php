<?php

namespace FahmiAzizHakim\LaravelAutoRollback\Tests;

use FahmiAzizHakim\LaravelAutoRollback\AutoRollbackServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected string $logPath;

    protected function getPackageProviders($app): array
    {
        return [AutoRollbackServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->logPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'autorollback-tests-'.getmypid();

        $app['config']->set('app.env', 'local');
        // For the web middleware group's cookie encryption.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['env'] = 'local';

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('auto-rollback.mode', 'ON');
        $app['config']->set('auto-rollback.log.path', $this->logPath);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->logPath);

        parent::tearDown();
    }

    /**
     * @param  string  $folder  the route's folder, e.g. v1~sites~articles
     */
    protected function logFile(string $method, string $folder): string
    {
        return implode(DIRECTORY_SEPARATOR, [$this->logPath, now()->format('Ymd'), $folder, $method.'.txt']);
    }
}
