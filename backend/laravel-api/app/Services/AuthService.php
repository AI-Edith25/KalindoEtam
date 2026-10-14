<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

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

    public function login(string $username, string $password): array
    {
        $user = $this->userRepository->findByUsername($username);

        if (! $user || ! Hash::check($password, $user->password)) {
            $this->auditLogService->record('login_failed', 'auth', "Failed login attempt for {$username}.", userId: $user?->id);

            throw new BusinessException('Invalid username or password.', 401);
        }

        if (! $user->is_active) {
            $this->auditLogService->record('login_blocked', 'auth', "Login blocked for deactivated user {$username}.", userId: $user->id);

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

    /** Minutes a password reset link stays valid after being requested. */
    private const RESET_TOKEN_EXPIRY_MINUTES = 60;

    /**
     * Public self-registration — always inactive, no role (admin approves + assigns a role
     * afterward via the existing Activate action, see UserService::setActive()). Kept here
     * rather than reusing UserService::create() since it's a distinct public-entry path with
     * its own audit action, not the admin-creation flow.
     */
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = $this->userRepository->create([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'is_active' => false,
            ]);

            $this->auditLogService->record('self_registered', 'auth', "{$user->email} registered and is pending approval.", userId: $user->id);

            return $user;
        });
    }

    /**
     * Phase 2 forgot-password: emails a single-use, time-limited reset link. Silently no-ops on
     * unknown emails — the controller always returns the same generic response regardless, which
     * (not a separate "check" step here) is what actually prevents account enumeration.
     */
    public function forgotPassword(string $email): void
    {
        $user = $this->userRepository->findByEmail($email);

        if (! $user) {
            return;
        }

        $rawToken = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            ['token' => Hash::make($rawToken), 'created_at' => now()],
        );

        $resetUrl = rtrim(config('app.frontend_url'), '/').'/reset-password?email='.urlencode($email).'&token='.$rawToken;

        Mail::to($email)->send(new PasswordResetMail($resetUrl, $user->name));

        $this->auditLogService->record('password_reset_requested', 'auth', "Password reset requested for {$user->email}.", userId: $user->id);
    }

    /**
     * Validates the emailed token (hashed at rest, single-use — the row is deleted on success)
     * and applies the new password. The failure message is deliberately vague (same reasoning as
     * login's "Invalid email or password") — it doesn't distinguish "no such request", "wrong
     * token", and "expired".
     */
    public function resetPassword(string $email, string $token, string $newPassword): void
    {
        $row = DB::table('password_reset_tokens')->where('email', $email)->first();
        $user = $row ? $this->userRepository->findByEmail($email) : null;

        if (! $row || ! $user || ! Hash::check($token, $row->token) || now()->diffInMinutes($row->created_at) > self::RESET_TOKEN_EXPIRY_MINUTES) {
            throw new BusinessException('Link reset password tidak valid atau sudah kedaluwarsa.', 400);
        }

        DB::transaction(function () use ($user, $email, $newPassword) {
            $user->update(['password' => Hash::make($newPassword)]);
            $user->tokens()->delete();
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            $this->auditLogService->record('password_reset_verified', 'auth', "Password reset (verified) for {$user->email}.", userId: $user->id);
        });
    }
}
