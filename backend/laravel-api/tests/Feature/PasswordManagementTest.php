<?php

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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

    public function test_register_creates_inactive_user_with_no_role(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'New Guy',
            'username' => 'newguy',
            'email' => 'newguy@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertOk();
        $user = User::where('email', 'newguy@example.com')->firstOrFail();
        $this->assertFalse($user->is_active);
        $this->assertCount(0, $user->roles);
        $this->assertDatabaseHas('audit_logs', ['action' => 'self_registered', 'user_id' => $user->id]);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Someone',
            'username' => 'someone',
            'email' => 'taken@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(422);
    }

    public function test_forgot_password_sends_mail_for_known_email(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);

        $response->assertOk();
        Mail::assertSent(PasswordResetMail::class, fn ($mail) => $mail->hasTo($user->email));
    }

    public function test_forgot_password_returns_identical_generic_response_and_sends_no_mail_for_unknown_email(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'known@example.com']);

        $knownResponse = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'known@example.com']);
        $unknownResponse = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com']);

        $knownResponse->assertOk();
        $unknownResponse->assertOk();
        $this->assertSame($knownResponse->json(), $unknownResponse->json());
        Mail::assertSentCount(1);
        Mail::assertNotSent(PasswordResetMail::class, fn ($mail) => $mail->hasTo('nobody@example.com'));
    }

    public function test_reset_password_succeeds_with_valid_token_and_token_is_single_use(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password')]);
        $user->createToken('frontend');

        Mail::fake();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
        $rawToken = null;
        Mail::assertSent(PasswordResetMail::class, function ($mail) use (&$rawToken) {
            $rawToken = (string) \Illuminate\Support\Str::of($mail->resetUrl)->after('token=');

            return true;
        });

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $rawToken,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $response->assertOk();
        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertNull(DB::table('password_reset_tokens')->where('email', $user->email)->first());

        // Second use of the same (already-consumed) token must fail.
        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $rawToken,
            'password' => 'another-password',
            'password_confirmation' => 'another-password',
        ])->assertStatus(400);
    }

    public function test_reset_password_rejects_wrong_token(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password')]);

        Mail::fake();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => 'not-the-real-token',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertStatus(400);

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }
}
