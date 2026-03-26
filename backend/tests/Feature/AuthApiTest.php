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

        $captchaResponse = $this->getJson('/api/v1/auth/captcha');
        $captchaResponse->assertOk();

        $response = $this->postJson('/api/v1/auth/login', [
            'account' => 'dispatcher',
            'password' => 'TaskRoute@123',
            'captcha_key' => $captchaResponse->json('key'),
            'captcha_code' => $this->readCaptchaAnswerFromKey($captchaResponse->json('key')),
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'token',
                'token_type',
                'user' => ['id', 'role', 'account'],
            ]);
    }

    private function readCaptchaAnswerFromKey(string $key): string
    {
        return (string) Cache::get($key);
    }
}
