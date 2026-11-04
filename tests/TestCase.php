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
        $app['env'] = 'local';

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('auto-rollback.enabled', true);
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
}
