<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_and_get_token(): void
    {
        $this->seed(DatabaseSeeder::class);

        $response = $this->loginAndGetResponse('dispatcher', 'password', 'pc');

        $response->assertOk()
            ->assertJsonStructure([
                'token',
                'token_type',
                'user' => ['id', 'role', 'account'],
            ]);
    }

    public function test_same_client_type_login_will_kick_previous_session(): void
    {
        $this->seed(DatabaseSeeder::class);

        $firstLogin = $this->loginAndGetResponse('dispatcher', 'password', 'pc')->assertOk();
        $secondLogin = $this->loginAndGetResponse('dispatcher', 'password', 'pc')->assertOk();

        $oldToken = $firstLogin->json('token');
        $newToken = $secondLogin->json('token');

        $this->withHeader('Authorization', 'Bearer '.$oldToken)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();

        $this->withHeader('Authorization', 'Bearer '.$newToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    public function test_pc_and_mobile_can_stay_logged_in_at_the_same_time(): void
    {
        $this->seed(DatabaseSeeder::class);

        $pcLogin = $this->loginAndGetResponse('dispatcher', 'password', 'pc')->assertOk();
        $mobileLogin = $this->loginAndGetResponse('dispatcher', 'password', 'mobile')->assertOk();

        $pcToken = $pcLogin->json('token');
        $mobileToken = $mobileLogin->json('token');

        $this->withHeader('Authorization', 'Bearer '.$pcToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$mobileToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    private function loginAndGetResponse(string $account, string $password, string $clientType)
    {
        $captchaResponse = $this->getJson('/api/v1/auth/captcha');
        $captchaResponse->assertOk();

        return $this->postJson('/api/v1/auth/login', [
            'account' => $account,
            'password' => $password,
            'captcha_key' => $captchaResponse->json('key'),
            'captcha_code' => $this->readCaptchaAnswerFromKey($captchaResponse->json('key')),
            'client_type' => $clientType,
        ]);
    }

    private function readCaptchaAnswerFromKey(string $key): string
    {
        return (string) Cache::get($key);
    }
}
