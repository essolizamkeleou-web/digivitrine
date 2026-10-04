<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Support\ApiMessages;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Concerns\MakesApiRequests;
use Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    use MakesApiRequests;
    use RefreshDatabase;

    public function test_health_endpoint_is_public(): void
    {
        $this->get('/api/v1/health')
            ->assertOk()
            ->assertExactJson(['data' => ['status' => 'ok']]);
    }

    public function test_unknown_api_routes_return_a_clean_json_404(): void
    {
        $this->get('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertExactJson(['message' => ApiMessages::NOT_FOUND]);
    }

    public function test_a_wrong_http_method_returns_a_clean_json_405(): void
    {
        $this->get('/api/v1/auth/login')
            ->assertStatus(405)
            ->assertExactJson(['message' => ApiMessages::METHOD_NOT_ALLOWED]);
    }

    public function test_a_missing_model_is_a_clean_404(): void
    {
        Route::get('api/v1/_test/missing', fn () => User::findOrFail(999999999));

        $this->get('/api/v1/_test/missing')
            ->assertNotFound()
            ->assertExactJson(['message' => ApiMessages::NOT_FOUND]);
    }

    public function test_an_authorization_failure_is_a_clean_403(): void
    {
        Route::get('api/v1/_test/denied', fn () => throw new AuthorizationException);

        $this->get('/api/v1/_test/denied')
            ->assertForbidden()
            ->assertExactJson(['message' => ApiMessages::FORBIDDEN]);
    }

    public function test_an_unexpected_exception_never_leaks_its_details(): void
    {
        Route::get('api/v1/_test/boom', fn () => throw new RuntimeException('SQLSTATE[23505] secret-detail'));

        $response = $this->get('/api/v1/_test/boom')
            ->assertStatus(500)
            ->assertExactJson(['message' => ApiMessages::SERVER_ERROR]);

        foreach (['SQLSTATE', 'secret-detail', 'trace', 'RuntimeException', 'vendor'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent());
        }
    }

    public function test_a_raw_sql_error_never_reaches_the_client(): void
    {
        Route::get('api/v1/_test/sql', function () {
            DB::select('select * from table_that_does_not_exist');
        });

        $response = $this->get('/api/v1/_test/sql')
            ->assertStatus(500)
            ->assertExactJson(['message' => ApiMessages::SERVER_ERROR]);

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('table_that_does_not_exist', $response->getContent());
    }

    public function test_role_middleware_allows_only_the_listed_roles(): void
    {
        Route::middleware(['auth:sanctum', 'active', 'role:admin,moderator'])
            ->get('api/v1/_test/staff', fn () => response()->json(['data' => 'ok']));

        $this->asGuest()->get('/api/v1/_test/staff')->assertUnauthorized();

        foreach (['artisan' => 403, 'fieldAgent' => 403, 'moderator' => 200, 'admin' => 200] as $state => $status) {
            $user = User::factory()->{$state}()->create();

            $this->withApiToken($this->tokenFor($user))
                ->get('/api/v1/_test/staff')
                ->assertStatus($status);
        }
    }

    public function test_a_forbidden_role_gets_the_standard_403_body(): void
    {
        Route::middleware(['auth:sanctum', 'active', 'role:admin'])
            ->get('api/v1/_test/admin-only', fn () => response()->json(['data' => 'ok']));

        $this->withApiToken($this->tokenFor(User::factory()->create()))
            ->get('/api/v1/_test/admin-only')
            ->assertForbidden()
            ->assertExactJson(['message' => ApiMessages::FORBIDDEN]);
    }

    public function test_an_unknown_role_name_allows_nobody(): void
    {
        Route::middleware(['auth:sanctum', 'active', 'role:superuser'])
            ->get('api/v1/_test/nobody', fn () => response()->json(['data' => 'ok']));

        $this->withApiToken($this->tokenFor(User::factory()->admin()->create()))
            ->get('/api/v1/_test/nobody')
            ->assertForbidden();
    }

    public function test_active_middleware_blocks_a_deactivated_admin(): void
    {
        Route::middleware(['auth:sanctum', 'active', 'role:admin'])
            ->get('api/v1/_test/admin-only', fn () => response()->json(['data' => 'ok']));

        $admin = User::factory()->admin()->inactive()->create();

        $this->withApiToken($this->tokenFor($admin))
            ->get('/api/v1/_test/admin-only')
            ->assertForbidden()
            ->assertExactJson(['message' => ApiMessages::ACCOUNT_DISABLED]);
    }

    public function test_rate_limiters_are_defined_with_the_expected_limits(): void
    {
        $request = Request::create('/api/v1/auth/login', 'POST', ['login' => '90 12 34 56']);

        $limits = fn (string $name) => collect(Arr::wrap(RateLimiter::limiter($name)($request)));

        $this->assertSame([5, 60], $limits('auth-login')->pluck('maxAttempts')->all());
        $this->assertSame([10, 50], $limits('auth-register')->pluck('maxAttempts')->all());
        $this->assertSame([120], $limits('api')->pluck('maxAttempts')->all());
        $this->assertSame([240], $limits('public-api')->pluck('maxAttempts')->all());
    }

    public function test_the_login_limit_key_ignores_the_phone_format_and_the_email_case(): void
    {
        $key = function (string $login): string {
            $request = Request::create('/api/v1/auth/login', 'POST', ['login' => $login]);

            return collect(Arr::wrap(RateLimiter::limiter('auth-login')($request)))->first()->key;
        };

        $this->assertSame($key('90 12 34 56'), $key('+22890123456'));
        $this->assertSame($key('00228 90123456'), $key('+228 90 12 34 56'));
        $this->assertSame($key('Kofi@Example.COM'), $key('kofi@example.com'));
        $this->assertNotSame($key('90 12 34 56'), $key('91 12 34 56'));
    }

    public function test_tokens_expire_after_ninety_days_by_default(): void
    {
        $this->assertSame(129600, config('sanctum.expiration'));
    }
}
