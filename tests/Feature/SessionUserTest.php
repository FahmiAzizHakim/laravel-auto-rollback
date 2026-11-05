<?php

namespace FahmiAzizHakim\LaravelAutoRollback\Tests\Feature;

use FahmiAzizHakim\LaravelAutoRollback\Tests\TestCase;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\TestWith;

class SessionUserTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::post('articles', function () {
            DB::table('articles')->insert(['title' => 'Hello']);

            return ['ok' => true];
        });

        // Stands in for a login that keeps the user in the session.
        Route::middleware('web')->get('session/articles', function (Request $request) {
            $request->session()->put('username', 'budi');
            $request->session()->put('email', 'budi@session.test');
            $request->session()->put('profile', ['city' => 'Bandung']);

            DB::table('articles')->insert(['title' => 'Hello']);

            return ['ok' => true];
        });
    }

    protected function tearDown(): void
    {
        putenv('RB_SESSION_USER');
        unset($_ENV['RB_SESSION_USER'], $_SERVER['RB_SESSION_USER']);

        parent::tearDown();
    }

    public function test_a_session_value_is_logged(): void
    {
        config(['auto-rollback.log.user' => 'username']);

        $this->getJson('session/articles')->assertOk();

        $this->assertContains('User   : budi', $this->logLines('session~articles', 'get'));
    }

    public function test_dot_notation_reaches_nested_session_values(): void
    {
        config(['auto-rollback.log.user' => 'profile.city']);

        $this->getJson('session/articles')->assertOk();

        $this->assertStringContainsString('User   : Bandung', $this->log('session~articles', 'get'));
    }

    public function test_the_authenticated_users_field_is_logged(): void
    {
        config(['auto-rollback.log.user' => 'email']);

        $this->actingAs(new GenericUser(['id' => 7, 'email' => 'budi@auth.test']));

        $this->postJson('articles')->assertOk();

        $this->assertStringContainsString('User   : budi@auth.test', $this->log());
    }

    public function test_the_authenticated_user_wins_over_the_session(): void
    {
        config(['auto-rollback.log.user' => 'email']);

        $this->actingAs(new GenericUser(['id' => 7, 'email' => 'budi@auth.test']));

        $this->getJson('session/articles')->assertOk();

        $log = $this->log('session~articles', 'get');
        $this->assertStringContainsString('User   : budi@auth.test', $log);
        $this->assertStringNotContainsString('budi@session.test', $log);
    }

    public function test_a_field_the_user_lacks_falls_back_to_the_session(): void
    {
        config(['auto-rollback.log.user' => 'username']);

        $this->actingAs(new GenericUser(['id' => 7, 'email' => 'budi@auth.test']));

        $this->getJson('session/articles')->assertOk();

        $this->assertContains('User   : budi', $this->logLines('session~articles', 'get'));
    }

    public function test_a_guest_is_logged_as_a_dash(): void
    {
        config(['auto-rollback.log.user' => 'email']);

        $this->postJson('articles')->assertOk();

        $this->assertStringContainsString('User   : -', $this->log());
    }

    public function test_a_hidden_field_is_masked(): void
    {
        config(['auto-rollback.log.user' => 'token']);

        $this->actingAs(new GenericUser(['id' => 7, 'token' => 'abc123']));

        $this->postJson('articles')->assertOk();

        $log = $this->log();
        $this->assertStringContainsString('User   : ********', $log);
        $this->assertStringNotContainsString('abc123', $log);
    }

    #[TestWith([null])]
    #[TestWith([''])]
    #[TestWith(['   '])]
    public function test_no_user_line_when_not_set(?string $value): void
    {
        config(['auto-rollback.log.user' => $value]);

        $this->actingAs(new GenericUser(['id' => 7, 'email' => 'budi@auth.test']));

        $this->postJson('articles')->assertOk();

        $log = $this->log();
        $this->assertStringNotContainsString('User   :', $log);
        $this->assertStringNotContainsString('budi@auth.test', $log);
    }

    #[TestWith(['header:X-User-Email'])]
    #[TestWith(['header:x-user-email'])]
    #[TestWith(['HEADER: X-User-Email'])]
    public function test_a_header_is_logged(string $setting): void
    {
        config(['auto-rollback.log.user' => $setting]);

        $this->postJson('articles', [], ['X-User-Email' => 'budi@gateway.test'])->assertOk();

        $this->assertContains('User   : budi@gateway.test', $this->logLines());
    }

    public function test_a_header_is_read_alone_without_falling_back_to_the_user(): void
    {
        config(['auto-rollback.log.user' => 'header:X-User-Email']);

        $this->actingAs(new GenericUser(['id' => 7, 'email' => 'budi@auth.test']));

        $this->postJson('articles')->assertOk();

        $this->assertContains('User   : -', $this->logLines());
        $this->assertStringNotContainsString('budi@auth.test', $this->log());
    }

    public function test_an_empty_header_is_logged_as_a_dash(): void
    {
        config(['auto-rollback.log.user' => 'header:X-User-Email']);

        $this->postJson('articles', [], ['X-User-Email' => ''])->assertOk();

        $this->assertContains('User   : -', $this->logLines());
    }

    #[TestWith(['header:Authorization', 'Authorization', 'Bearer eyJhbGciOi.secret'])]
    #[TestWith(['header:Cookie', 'Cookie', 'laravel_session=secret'])]
    #[TestWith(['header:Token', 'Token', 'secret'])]
    public function test_a_credential_header_is_masked(string $setting, string $header, string $value): void
    {
        config(['auto-rollback.log.user' => $setting]);

        $this->postJson('articles', [], [$header => $value])->assertOk();

        $this->assertContains('User   : ********', $this->logLines());
        $this->assertStringNotContainsString('secret', $this->log());
    }

    public function test_header_without_a_name_leaves_the_line_out(): void
    {
        config(['auto-rollback.log.user' => 'header:']);

        $this->postJson('articles')->assertOk();

        $this->assertStringNotContainsString('User   :', $this->log());
    }

    public function test_the_env_variable_is_read(): void
    {
        putenv('RB_SESSION_USER=email');
        $_ENV['RB_SESSION_USER'] = $_SERVER['RB_SESSION_USER'] = 'email';
        config(['auto-rollback' => require __DIR__.'/../../config/auto-rollback.php']);
        config(['auto-rollback.mode' => 'ON', 'auto-rollback.log.path' => $this->logPath]);

        $this->actingAs(new GenericUser(['id' => 7, 'email' => 'budi@auth.test']));

        $this->postJson('articles')->assertOk();

        $this->assertStringContainsString('User   : budi@auth.test', $this->log());
    }

    public function test_a_missing_env_variable_leaves_the_line_out(): void
    {
        putenv('RB_SESSION_USER');
        unset($_ENV['RB_SESSION_USER'], $_SERVER['RB_SESSION_USER']);
        config(['auto-rollback' => require __DIR__.'/../../config/auto-rollback.php']);
        config(['auto-rollback.mode' => 'ON', 'auto-rollback.log.path' => $this->logPath]);

        $this->postJson('articles')->assertOk();

        $this->assertStringNotContainsString('User   :', $this->log());
    }

    protected function log(string $route = 'articles', string $method = 'post'): string
    {
        return file_get_contents($this->logFile($method, $route));
    }

    /**
     * @return list<string>
     */
    protected function logLines(string $route = 'articles', string $method = 'post'): array
    {
        return preg_split('/\R/', $this->log($route, $method));
    }
}
