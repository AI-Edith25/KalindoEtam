<?php

namespace Tests;

use App\Models\Permission;
use App\Models\User;
use App\Services\ApprovalService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Sales Order / Purchase Order / manual Journal Entry now require an
     * approved ApprovalFlow before submit() will proceed (Sprint 24B, see
     * docs/APPROVAL_WORKFLOW_DESIGN.md). Fixture helpers across the suite
     * call this immediately before submit() on those three types, the same
     * two-step request+approve a real user now performs.
     *
     * These are service-level tests, not HTTP tests — none of them run
     * RolePermissionSeeder or authenticate a user, so ApprovalService::approve()'s
     * own permission check (Auth::user()->can(...)) has no one to check.
     * Rather than seeding the full permission catalog per test, this grants
     * a throwaway user exactly the one permission this document's approval
     * needs, acting as them only for the approve() call itself.
     */
    protected function approveDocument(Model $document): void
    {
        $service = app(ApprovalService::class);
        $flow = $service->requestApproval($document);

        $module = $service->moduleFor($document);
        Permission::query()->firstOrCreate(['name' => "{$module}.approve", 'guard_name' => 'web']);
        $approver = User::factory()->create();
        $approver->givePermissionTo("{$module}.approve");

        $this->actingAs($approver);
        $service->approve($flow);
    }

    /**
     * Sales Order create()/submit() now block a customer that's overdue or
     * over their credit limit (Customer Credit feature, see
     * CustomerCreditService). Fixture helpers that deliberately create
     * multiple back-dated invoices for the same customer (e.g. AR aging
     * report tests) trip this by design once the first one is overdue —
     * same bypass shape as approveDocument() above: grant a throwaway user
     * the override permission, act as them only for the call that needs it.
     * Callers must still pass override_credit_block/override_reason
     * themselves — this only satisfies the permission check.
     */
    protected function actingAsCreditOverride(): void
    {
        Permission::query()->firstOrCreate(['name' => 'sales.orders.override_credit_check', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('sales.orders.override_credit_check');

        $this->actingAs($user);
    }
}
