<?php

namespace FahmiAzizHakim\LaravelAutoRollback\Tests\Feature;

use FahmiAzizHakim\LaravelAutoRollback\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

class RequestInputTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $insert = function () {
            DB::table('articles')->insert(['title' => 'Hello']);

            return ['ok' => true];
        };

        Route::get('articles', $insert);
        Route::post('articles', $insert);

        Route::post('articles/changed', function () {
            request()->merge(['title' => 'Changed by the app']);
            DB::table('articles')->insert(['title' => request('title')]);

            return ['ok' => true];
        });
    }

    public function test_get_parameters_are_logged(): void
    {
        $this->getJson('articles?page=2&q=resi+baru&filter[status]=open')->assertOk();

        $this->assertStringContainsString(
            'Query  : {"page":"2","q":"resi baru","filter":{"status":"open"}}',
            $this->log('get')
        );
    }

    public function test_a_json_body_is_logged(): void
    {
        $this->postJson('articles', ['title' => 'Résumé', 'tags' => ['a', 'b']])->assertOk();

        $log = $this->log('post');
        $this->assertStringContainsString('Body   : {"title":"Résumé","tags":["a","b"]}', $log);
        $this->assertStringNotContainsString('Query  :', $log);
    }

    public function test_a_form_body_is_logged(): void
    {
        $this->post('articles', ['title' => 'Hello'])->assertOk();

        $this->assertStringContainsString('Body   : {"title":"Hello"}', $this->log('post'));
    }

    public function test_query_and_body_are_logged_together(): void
    {
        $this->postJson('articles?source=app', ['title' => 'Hello'])->assertOk();

        $log = $this->log('post');
        $this->assertStringContainsString('Query  : {"source":"app"}', $log);
        $this->assertStringContainsString('Body   : {"title":"Hello"}', $log);
    }

    public function test_sensitive_fields_are_masked_at_any_depth_and_in_any_case(): void
    {
        $this->postJson('articles?token=abc', [
            'email' => 'budi@example.com',
            'Password' => 'secret123',
            'user' => ['pin' => '1234', 'name' => 'Budi'],
        ])->assertOk();

        $log = $this->log('post');
        $this->assertStringContainsString('Query  : {"token":"********"}', $log);
        $this->assertStringContainsString(
            'Body   : {"email":"budi@example.com","Password":"********","user":{"pin":"********","name":"Budi"}}',
            $log
        );
        $this->assertStringNotContainsString('secret123', $log);
        $this->assertStringNotContainsString('1234', $log);
    }

    public function test_the_hidden_list_is_configurable(): void
    {
        config(['auto-rollback.log.hidden' => ['nik']]);

        $this->postJson('articles', ['nik' => '3201010101010001', 'password' => 'visible'])->assertOk();

        $this->assertStringContainsString('Body   : {"nik":"********","password":"visible"}', $this->log('post'));
    }

    public function test_input_is_logged_as_it_arrived_not_as_the_app_changed_it(): void
    {
        $this->postJson('articles/changed', ['title' => 'Sent by the client'])->assertOk();

        $log = $this->log('post', 'articles~changed');
        $this->assertStringContainsString('Body   : {"title":"Sent by the client"}', $log);
        $this->assertStringContainsString("values ('Changed by the app')", $log);
    }

    public function test_uploaded_files_are_described_not_dumped(): void
    {
        $this->post('articles', [
            'title' => 'Hello',
            'attachment' => UploadedFile::fake()->create('manifest.pdf', 12, 'application/pdf'),
        ])->assertOk();

        $log = $this->log('post');
        $this->assertStringContainsString('Body   : {"title":"Hello"}', $log);
        $this->assertStringContainsString('Files  : {"attachment":"manifest.pdf (12.0 KB, application/pdf)"}', $log);
    }

    public function test_a_raw_body_is_logged_as_text(): void
    {
        $this->call('POST', 'articles', [], [], [], ['CONTENT_TYPE' => 'application/xml'], '<resi><no>JX123</no></resi>')
            ->assertOk();

        $this->assertStringContainsString('Body   : (raw) <resi><no>JX123</no></resi>', $this->log('post'));
    }

    public function test_broken_json_is_logged_raw(): void
    {
        $this->call('POST', 'articles', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"title": "Hello",')
            ->assertOk();

        $this->assertStringContainsString('Body   : (raw) {"title": "Hello",', $this->log('post'));
    }

    public function test_long_input_is_truncated(): void
    {
        config(['auto-rollback.log.max_length' => 20]);

        $this->postJson('articles', ['title' => str_repeat('x', 100)])->assertOk();

        $this->assertStringContainsString(
            'Body   : {"title":"xxxxxxxxxx ... (truncated, 112 characters in all)',
            $this->log('post')
        );
    }

    public function test_a_request_without_input_has_no_input_lines(): void
    {
        $this->getJson('articles')->assertOk();

        $log = $this->log('get');
        $this->assertStringNotContainsString('Query  :', $log);
        $this->assertStringNotContainsString('Body   :', $log);
        $this->assertStringNotContainsString('Files  :', $log);
    }

    protected function log(string $method, string $route = 'articles'): string
    {
        return file_get_contents($this->logFile($method, $route));
    }
}
