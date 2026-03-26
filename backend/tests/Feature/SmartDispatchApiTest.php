<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SmartDispatchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatcher_can_preview_dispatch_result(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $response = $this->postJson('/api/v1/dispatch/preview');

        $response->assertOk()
            ->assertJsonStructure([
                'assignments',
                'unassigned',
            ]);
    }
}

