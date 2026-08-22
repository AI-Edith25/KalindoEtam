<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_change_own_password_with_correct_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password')]);
        $token = $user->createToken('frontend')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/auth/change-password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertOk();
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_change_password_rejects_wrong_current_password_and_makes_no_change(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password')]);
        $token = $user->createToken('frontend')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/auth/change-password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertStatus(422);
        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_change_password_requires_confirmation_and_minimum_length(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password')]);
        $token = $user->createToken('frontend')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/auth/change-password', [
            'current_password' => 'old-password',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422);

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/auth/change-password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'mismatched',
        ])->assertStatus(422);
    }

    public function test_forgot_password_resets_existing_account_and_revokes_tokens(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password')]);
        $user->createToken('frontend');

        $response = $this->postJson('/api/v1/auth/reset-password-unverified', [
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $response->assertOk();
        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_forgot_password_returns_identical_generic_response_for_unknown_email(): void
    {
        User::factory()->create(['email' => 'known@example.com']);

        $knownResponse = $this->postJson('/api/v1/auth/reset-password-unverified', [
            'email' => 'known@example.com',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $unknownResponse = $this->postJson('/api/v1/auth/reset-password-unverified', [
            'email' => 'nobody@example.com',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $knownResponse->assertOk();
        $unknownResponse->assertOk();
        $this->assertSame($knownResponse->json(), $unknownResponse->json());
    }
}
