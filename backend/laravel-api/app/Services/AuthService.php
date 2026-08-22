<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Minimum viable auth for Frontend Sprint 1: token issuance via Sanctum,
 * single Admin user, no permission enforcement yet. Session/cookie-based
 * SPA auth was deliberately not used — see docs/DECISIONS.md.
 */
class AuthService
{
    public function __construct(
        protected UserRepository $userRepository,
        protected AuditLogService $auditLogService,
    ) {}

    public function login(string $email, string $password): array
    {
        $user = $this->userRepository->findByEmail($email);

        if (! $user || ! Hash::check($password, $user->password)) {
            $this->auditLogService->record('login_failed', 'auth', "Failed login attempt for {$email}.", userId: $user?->id);

            throw new BusinessException('Invalid email or password.', 401);
        }

        if (! $user->is_active) {
            $this->auditLogService->record('login_blocked', 'auth', "Login blocked for deactivated user {$email}.", userId: $user->id);

            throw new BusinessException('This account is deactivated.', 401);
        }

        $token = $user->createToken('frontend')->plainTextToken;

        $this->auditLogService->record('login', 'auth', "{$user->email} logged in.", userId: $user->id);

        return ['token' => $token, 'user' => $user->load('roles')];
    }

    public function logout(User $user): void
    {
        $this->auditLogService->record('logout', 'auth', "{$user->email} logged out.", userId: $user->id);

        $user->currentAccessToken()->delete();
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw new BusinessException('Password lama tidak sesuai.');
        }

        DB::transaction(function () use ($user, $newPassword) {
            $user->update(['password' => Hash::make($newPassword)]);
            $user->tokens()->delete();

            $this->auditLogService->record('password_changed', 'auth', "{$user->email} changed their password.", userId: $user->id);
        });
    }

    /**
     * Phase 1 (no email/OTP verification — known, temporary trade-off): anyone who
     * knows an account's email can reset its password. Silently no-ops on unknown
     * emails so the response never reveals whether an account exists.
     * ponytail: phase 2 would add a token/OTP check here before allowing the update
     * (reusing the unused password_reset_tokens table + Password facade, or TOTP) —
     * deliberately deferred, not built now.
     */
    public function resetPasswordUnverified(string $email, string $newPassword): void
    {
        $user = $this->userRepository->findByEmail($email);

        if (! $user) {
            return;
        }

        DB::transaction(function () use ($user, $newPassword) {
            $user->update(['password' => Hash::make($newPassword)]);
            $user->tokens()->delete();

            $this->auditLogService->record('password_reset_unverified', 'auth', "Password reset (unverified) for {$user->email}.", userId: $user->id);
        });
    }
}
