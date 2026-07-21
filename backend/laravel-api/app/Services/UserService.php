<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The missing backend surface flagged in docs/ADMINISTRATION_DESIGN.md §4 —
 * until this sprint, `User` had authentication (AuthService) but no CRUD.
 * Role assignment reuses Spatie's HasRoles::syncRoles() the same way
 * RoleService::syncPermissions() already reuses syncPermissions() — no new
 * assignment mechanism invented.
 */
class UserService
{
    public function __construct(
        protected UserRepository $userRepository,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(int $perPage = 15): LengthAwarePaginator
    {
        return $this->userRepository->paginate($perPage)->through(fn (User $user) => $user->load('roles'));
    }

    public function create(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = $this->userRepository->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            if (! empty($data['role'])) {
                $user->assignRole($data['role']);
            }

            $this->auditLogService->record('created', 'user', "Created user {$user->email}.");

            return $user->load('roles');
        });
    }

    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $user = $this->userRepository->update($user, $data);

            $this->auditLogService->record('updated', 'user', "Updated user {$user->email}.");

            return $user->load('roles');
        });
    }

    public function setActive(User $user, bool $isActive): User
    {
        return DB::transaction(function () use ($user, $isActive) {
            $user->update(['is_active' => $isActive]);

            $this->auditLogService->record($isActive ? 'activated' : 'deactivated', 'user', ($isActive ? 'Activated' : 'Deactivated')." user {$user->email}.");

            if (! $isActive) {
                $user->tokens()->delete();
            }

            return $user->load('roles');
        });
    }

    /** No email infra exists in this codebase (docs/ADMINISTRATION_DESIGN.md Open Question 4) — returns the new plain-text password once so the admin can relay it out-of-band, instead of silently emailing a link nothing can send. */
    public function resetPassword(User $user): string
    {
        return DB::transaction(function () use ($user) {
            $newPassword = Str::password(12);

            $user->update(['password' => Hash::make($newPassword)]);
            $user->tokens()->delete();

            $this->auditLogService->record('password_reset', 'user', "Password reset for user {$user->email}.");

            return $newPassword;
        });
    }

    public function assignRole(User $user, string $role): User
    {
        return DB::transaction(function () use ($user, $role) {
            $user->syncRoles([$role]);

            $this->auditLogService->record('role_assigned', 'user', "Assigned role \"{$role}\" to user {$user->email}.");

            return $user->load('roles');
        });
    }
}
