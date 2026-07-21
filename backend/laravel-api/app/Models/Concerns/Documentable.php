<?php

namespace App\Models\Concerns;

use App\Contracts\DocumentNumberGeneratorInterface;
use App\Enums\ApprovalStatus;
use App\Enums\DocumentStatus;
use App\Models\ApprovalFlow;
use App\Models\DocumentAttachment;
use App\Models\DocumentTimeline;
use App\Services\DocumentTimelineService;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

/**
 * The Document Engine. Any model representing an ERP document (Purchase
 * Order, Sales Invoice, Journal Entry, ...) uses this trait instead of
 * rolling its own numbering/status/timeline logic.
 *
 * Consuming models must:
 * - implement documentType(): string
 * - also use HasUuids, SoftDeletes, HasAuditTrail (identity/audit are separate concerns)
 * - have document_number, status, submitted_at, cancelled_at, revision columns
 */
trait Documentable
{
    protected static function bootDocumentable(): void
    {
        static::creating(function ($model) {
            if (empty($model->document_number)) {
                $model->document_number = app(DocumentNumberGeneratorInterface::class)->generate($model->documentType());
            }

            if (empty($model->status)) {
                $model->status = DocumentStatus::DRAFT;
            }

            if (empty($model->revision)) {
                $model->revision = 1;
            }
        });

        static::created(function ($model) {
            app(DocumentTimelineService::class)->record($model, 'created');
            $model->afterCreate();
        });
    }

    abstract public static function documentType(): string;

    public function attachments(): MorphMany
    {
        return $this->morphMany(DocumentAttachment::class, 'attachable');
    }

    public function timeline(): MorphMany
    {
        return $this->morphMany(DocumentTimeline::class, 'subject');
    }

    public function approvalFlows(): MorphMany
    {
        return $this->morphMany(ApprovalFlow::class, 'approvable');
    }

    public function submit(): static
    {
        abort_if($this->status !== DocumentStatus::DRAFT, 422, 'Only draft documents can be submitted.');

        if ($this->requiresApproval()) {
            $latestApproval = $this->approvalFlows()->orderByDesc('step')->first();

            abort_if(! $latestApproval, 422, 'This document requires approval before it can be submitted — request approval first.');
            abort_if($latestApproval->status === ApprovalStatus::PENDING, 422, 'This document is still awaiting approval.');
            abort_if($latestApproval->status === ApprovalStatus::REJECTED, 422, 'This document was rejected — revise it and request approval again.');
        }

        return DB::transaction(function () {
            $this->update(['status' => DocumentStatus::SUBMITTED, 'submitted_at' => now()]);
            app(DocumentTimelineService::class)->record($this, 'submitted');
            $this->afterSubmit();

            return $this;
        });
    }

    /**
     * Opt-in gate, defaulting to false — every currently-shipped Documentable
     * type (Invoice, Delivery, Credit/Debit Note, Goods Receipt, Stock
     * Adjustment, Payment/Receipt Entry) is unaffected. Override per model
     * to require an APPROVED ApprovalFlow before submit() will proceed. See
     * docs/APPROVAL_WORKFLOW_DESIGN.md §1/§3.
     */
    public function requiresApproval(): bool
    {
        return false;
    }

    public function cancel(): static
    {
        abort_if($this->status !== DocumentStatus::SUBMITTED, 422, 'Only submitted documents can be cancelled.');

        return DB::transaction(function () {
            $this->update(['status' => DocumentStatus::CANCELLED, 'cancelled_at' => now()]);
            app(DocumentTimelineService::class)->record($this, 'cancelled');
            $this->afterCancel();

            return $this;
        });
    }

    /**
     * Override in the consuming model to run custom logic after creation.
     */
    protected function afterCreate(): void {}

    /**
     * Override in the consuming model to run custom logic after submit.
     */
    protected function afterSubmit(): void {}

    /**
     * Override in the consuming model to run custom logic after cancel.
     */
    protected function afterCancel(): void {}
}
