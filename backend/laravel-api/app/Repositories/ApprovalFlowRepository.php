<?php

namespace App\Repositories;

use App\Enums\ApprovalStatus;
use App\Models\ApprovalFlow;

class ApprovalFlowRepository extends BaseRepository
{
    public function __construct(ApprovalFlow $model)
    {
        parent::__construct($model);
    }

    /** The current, decidable request for a document — the newest step, regardless of decision. */
    public function latestFor(string $approvableType, string $approvableId): ?ApprovalFlow
    {
        return $this->model->query()
            ->where('approvable_type', $approvableType)
            ->where('approvable_id', $approvableId)
            ->orderByDesc('step')
            ->first();
    }

    public function historyFor(string $approvableType, string $approvableId)
    {
        return $this->model->query()
            ->with('approver')
            ->where('approvable_type', $approvableType)
            ->where('approvable_id', $approvableId)
            ->orderByDesc('step')
            ->get();
    }

    /** Dashboard Pending Tasks widget (docs/APPROVAL_WORKFLOW_DESIGN.md §6) — count only, same shape every other pendingTasks() row already has. */
    public function countPending(): int
    {
        return $this->model->query()->where('status', ApprovalStatus::PENDING)->count();
    }
}
