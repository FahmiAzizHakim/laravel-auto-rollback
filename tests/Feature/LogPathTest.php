<?php

namespace FahmiAzizHakim\LaravelAutoRollback\Tests\Feature;

use FahmiAzizHakim\LaravelAutoRollback\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\TestWith;

class LogPathTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $insert = function () {
            DB::table('articles')->insert(['title' => 'Hello']);

            return ['ok' => true];
        };

        Route::get('api/v1/website/header', $insert);
        Route::post('api/v1/website/header', $insert);
        Route::get('api/v1/sitemap.xml', $insert);
        Route::get('api/v1/{company}/orders', $insert);
        Route::get('api/v1/{company}/orders/{order}', $insert);
        Route::get('api/v1/{company}/reports/{period?}', $insert);
        Route::get('api/'.str_repeat('segment/', 40).'end', $insert);
    }

    public function test_a_route_is_one_folder_and_the_method_is_the_file(): void
    {
        $this->getJson('api/v1/website/header')->assertOk();
        $this->postJson('api/v1/website/header')->assertOk();

        $folder = $this->logPath.'/'.now()->format('Ymd').'/api~v1~website~header';

        $this->assertFileExists($folder.'/get.txt');
        $this->assertFileExists($folder.'/post.txt');
        $this->assertSame(['get.txt', 'post.txt'], $this->filesIn($folder));
        $this->assertSame(['api~v1~website~header'], $this->filesIn($this->logPath.'/'.now()->format('Ymd')));
    }

    public function test_the_query_string_never_becomes_part_of_the_folder(): void
    {
        $this->getJson('api/v1/website/header?page=2&sort=desc&filter[status]=open')->assertOk();
        $this->getJson('api/v1/website/header?q=a/b/c')->assertOk();

        $today = $this->logPath.'/'.now()->format('Ymd');

        $this->assertSame(['api~v1~website~header'], $this->filesIn($today));
        $this->assertSame(['get.txt'], $this->filesIn($today.'/api~v1~website~header'));
    }

    public function test_a_route_parameter_stays_a_folder_segment_under_its_placeholder(): void
    {
        $this->getJson('api/v1/acme/orders?page=2')->assertOk();
        $this->getJson('api/v1/globex/orders?page=3')->assertOk();
        $this->getJson('api/v1/acme/orders/1001')->assertOk();
        $this->getJson('api/v1/acme/orders/1002?expand=items')->assertOk();

        // One folder per route, whichever company or order was asked for.
        $this->assertSame(
            ['api~v1~{company}~orders', 'api~v1~{company}~orders~{order}'],
            $this->filesIn($this->logPath.'/'.now()->format('Ymd'))
        );
    }

    public function test_an_optional_route_parameter_is_kept_with_a_safe_name(): void
    {
        $this->getJson('api/v1/acme/reports/2026-11?format=csv')->assertOk();
        $this->getJson('api/v1/acme/reports')->assertOk();

        // The ? of {period?} is not allowed in a Windows file name.
        $this->assertSame(
            ['api~v1~{company}~reports~{period_}'],
            $this->filesIn($this->logPath.'/'.now()->format('Ymd'))
        );
    }

    public function test_dots_in_a_route_are_kept(): void
    {
        $this->getJson('api/v1/sitemap.xml')->assertOk();

        $this->assertFileExists($this->logPath.'/'.now()->format('Ymd').'/api~v1~sitemap.xml/get.txt');
    }

    #[TestWith(['#', 'api#v1#website#header'])]
    #[TestWith(['__', 'api__v1__website__header'])]
    public function test_the_separator_is_configurable(string $separator, string $folder): void
    {
        config(['auto-rollback.log.separator' => $separator]);

        $this->getJson('api/v1/website/header')->assertOk();

        $this->assertFileExists($this->logPath.'/'.now()->format('Ymd').'/'.$folder.'/get.txt');
    }

    #[TestWith(['/'])]
    #[TestWith(['\\'])]
    #[TestWith([':'])]
    #[TestWith(['?'])]
    #[TestWith([''])]
    public function test_a_separator_that_would_make_a_folder_or_a_bad_name_falls_back_to_tilde(string $separator): void
    {
        config(['auto-rollback.log.separator' => $separator]);

        $this->getJson('api/v1/website/header')->assertOk();

        $this->assertFileExists($this->logPath.'/'.now()->format('Ymd').'/api~v1~website~header/get.txt');
    }

    public function test_a_very_long_route_is_shortened_to_a_safe_folder_name(): void
    {
        $this->getJson('api/'.str_repeat('segment/', 40).'end')->assertOk();

        $folders = $this->filesIn($this->logPath.'/'.now()->format('Ymd'));

        $this->assertCount(1, $folders);
        $this->assertSame(200, strlen($folders[0]));
        $this->assertStringStartsWith('api~segment~segment~', $folders[0]);
        $this->assertFileExists($this->logPath.'/'.now()->format('Ymd').'/'.$folders[0].'/get.txt');
    }

    /**
     * @return list<string>
     */
    protected function filesIn(string $directory): array
    {
        $files = array_values(array_diff(scandir($directory), ['.', '..']));
        sort($files);

        return $files;
    }
}
