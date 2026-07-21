<?php

namespace App\Services;

use App\Models\Role;
use App\Repositories\RoleRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class RoleService
{
    public function __construct(
        protected RoleRepository $roleRepository,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(int $perPage = 15): LengthAwarePaginator
    {
        return $this->roleRepository->paginate($perPage);
    }

    public function create(array $data): Role
    {
        return DB::transaction(function () use ($data) {
            $role = $this->roleRepository->create([
                'name' => $data['name'],
                'guard_name' => $data['guard_name'] ?? 'web',
            ]);

            $this->auditLogService->record('created', 'role', "Created role \"{$role->name}\".");

            return $role;
        });
    }

    public function update(Role $role, array $data): Role
    {
        return DB::transaction(function () use ($role, $data) {
            $role = $this->roleRepository->update($role, $data);

            $this->auditLogService->record('updated', 'role', "Updated role \"{$role->name}\".");

            return $role;
        });
    }

    public function delete(Role $role): void
    {
        DB::transaction(function () use ($role) {
            $name = $role->name;
            $this->roleRepository->delete($role);

            $this->auditLogService->record('deleted', 'role', "Deleted role \"{$name}\".");
        });
    }

    public function syncPermissions(Role $role, array $permissionNames): Role
    {
        return DB::transaction(function () use ($role, $permissionNames) {
            $role->syncPermissions($permissionNames);

            $this->auditLogService->record('permissions_updated', 'role', "Updated permissions for role \"{$role->name}\".", ['permissions' => $permissionNames]);

            return $role->fresh('permissions');
        });
    }
}
