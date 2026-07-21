<?php

namespace App\Services;

use App\Repositories\PermissionRepository;
use Illuminate\Database\Eloquent\Collection;

/**
 * Permissions are a small, fixed, seeded set ({module}.{action} — see
 * RolePermissionSeeder) — never browsed as a paginated list, only ever
 * consumed whole by the Roles & Permissions matrix (docs/ADMINISTRATION_DESIGN.md
 * §5), which needs every module represented every time. Pagination here
 * would silently hide modules past page 1 — deliberately not paginated.
 */
class PermissionService
{
    public function __construct(protected PermissionRepository $permissionRepository) {}

    public function list(): Collection
    {
        return $this->permissionRepository->all();
    }
}
