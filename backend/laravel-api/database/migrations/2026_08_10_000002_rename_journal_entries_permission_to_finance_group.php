<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Journal Entries moved from the Accounting Reports nav group to Finance and
 * was relabeled "General Journal" — under navTree.ts's `{group}.{page}.{action}`
 * scheme that necessarily renames the permission from `accounting.journal_entries.*`
 * to `finance.journal_entries.*`. Same rename-in-place pattern as
 * 2026_08_03_000004_remap_permissions_to_page_scoped_names.php: create the new
 * permission rows, re-sync every Role (not just Admin) from old name to new
 * name, then soft-delete the old rows — this is a straight 1:1 map (no
 * fan-out), since it's a group-prefix rename, not a page split.
 */
return new class extends Migration
{
    protected array $oldToNew = [
        'accounting.journal_entries.view' => 'finance.journal_entries.view',
        'accounting.journal_entries.create' => 'finance.journal_entries.create',
        'accounting.journal_entries.update' => 'finance.journal_entries.update',
        'accounting.journal_entries.delete' => 'finance.journal_entries.delete',
        'accounting.journal_entries.approve' => 'finance.journal_entries.approve',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () {
            foreach ($this->oldToNew as $newName) {
                Permission::query()->firstOrCreate(['name' => $newName, 'guard_name' => 'web']);
            }

            Role::query()->with('permissions')->each(function (Role $role) {
                $newSet = collect();

                foreach ($role->permissions as $permission) {
                    $newSet->push($this->oldToNew[$permission->name] ?? $permission->name);
                }

                $role->syncPermissions($newSet->unique()->values()->all());
            });

            Permission::query()->whereIn('name', array_keys($this->oldToNew))->delete(); // soft-delete
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () {
            $newToOld = array_flip($this->oldToNew);

            Permission::onlyTrashed()
                ->whereIn('name', array_keys($this->oldToNew))
                ->restore();

            Role::query()->with('permissions')->each(function (Role $role) {
                $oldSet = collect();

                foreach ($role->permissions as $permission) {
                    $oldSet->push($newToOld[$permission->name] ?? $permission->name);
                }

                $role->syncPermissions($oldSet->unique()->values()->all());
            });

            Permission::query()->whereIn('name', array_values($this->oldToNew))->delete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
