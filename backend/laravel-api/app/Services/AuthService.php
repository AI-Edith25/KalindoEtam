<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Repositories\UserRepository;
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
}
