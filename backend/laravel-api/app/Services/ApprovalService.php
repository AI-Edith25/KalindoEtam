<?php

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Enums\DocumentStatus;
use App\Exceptions\BusinessException;
use App\Models\ApprovalFlow;
use App\Models\JournalEntry;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Repositories\ApprovalFlowRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The single write path for the approval workflow — see
 * docs/APPROVAL_WORKFLOW_DESIGN.md §1. Every document type that opts in
 * (Documentable::requiresApproval()) is served by this one service; no
 * document service (SalesOrderService, PurchaseOrderService,
 * JournalEntryService) implements any approval logic of its own.
 */
class ApprovalService
{
    /**
     * Maps an approvable model class to its permission-module name — the
     * app's global morph map (AppServiceProvider) is scoped specifically to
     * journal_entries.reference_type and deliberately left untouched here;
     * approve()/reject() serve all three document types through one route,
     * so the module name has to be resolved from data, not a static route.
     */
    protected const MODULES = [
        SalesOrder::class => 'sales_order',
        PurchaseOrder::class => 'purchase_order',
        JournalEntry::class => 'journal_entry',
    ];

    public function __construct(
        protected ApprovalFlowRepository $approvalFlowRepository,
        protected AuditLogService $auditLogService,
        protected DocumentTimelineService $documentTimelineService,
    ) {}

    public function moduleFor(Model $document): string
    {
        return self::MODULES[$document::class] ?? throw new BusinessException('This document type does not support approval.');
    }

    public function requestApproval(Model $document): ApprovalFlow
    {
        abort_if($document->status !== DocumentStatus::DRAFT, 422, 'Only draft documents can request approval.');

        $module = $this->moduleFor($document);
        $latest = $this->approvalFlowRepository->latestFor($document->getMorphClass(), $document->getKey());

        abort_if($latest?->status === ApprovalStatus::PENDING, 422, 'Approval is already pending for this document.');

        $flow = DB::transaction(function () use ($document, $latest) {
            $flow = $this->approvalFlowRepository->create([
                'approvable_type' => $document->getMorphClass(),
                'approvable_id' => $document->getKey(),
                'approver_id' => null,
                'status' => ApprovalStatus::PENDING,
                'step' => ($latest?->step ?? 0) + 1,
            ]);

            $this->documentTimelineService->record($document, 'approval_requested');

            return $flow;
        });

        $this->auditLogService->record('approval_requested', $module, "Requested approval for {$document->document_number}.", ['approval_flow_id' => $flow->id]);

        return $flow;
    }

    public function approve(ApprovalFlow $flow, ?string $remarks = null): ApprovalFlow
    {
        return $this->decide($flow, ApprovalStatus::APPROVED, 'approval_approved', $remarks);
    }

    public function reject(ApprovalFlow $flow, string $remarks): ApprovalFlow
    {
        return $this->decide($flow, ApprovalStatus::REJECTED, 'approval_rejected', $remarks);
    }

    protected function decide(ApprovalFlow $flow, ApprovalStatus $decision, string $auditAction, ?string $remarks): ApprovalFlow
    {
        abort_if($flow->status !== ApprovalStatus::PENDING, 422, 'This approval has already been decided.');

        $document = $flow->approvable;
        $module = $this->moduleFor($document);

        // The document-dependent equivalent of `->middleware('permission:{module}.approve')` —
        // one route serves all three document types, so the permission name can't be static
        // per-route the way Sprint 22B's other gated routes are. Same underlying Spatie check.
        if (! Auth::user()->can("{$module}.approve")) {
            $this->auditLogService->record('approval_decision_denied', $module, "Denied approval decision on {$document->document_number}.");

            throw new BusinessException('You do not have permission to decide this approval.', 403);
        }

        DB::transaction(function () use ($flow, $decision, $remarks, $auditAction) {
            $flow->update([
                'approver_id' => Auth::id(),
                'status' => $decision,
                'remarks' => $remarks,
                'decided_at' => now(),
            ]);

            $this->documentTimelineService->record($flow->approvable, $auditAction);
        });

        $this->auditLogService->record(
            $auditAction,
            $module,
            ($decision === ApprovalStatus::APPROVED ? 'Approved' : 'Rejected')." {$document->document_number}".($remarks ? ": {$remarks}" : '').'.',
            ['approval_flow_id' => $flow->id],
        );

        return $flow->fresh();
    }

    /** Same shape as DocumentTimelineService::forSubject() — read by type+id, not by a loaded model. */
    public function historyFor(string $approvableType, string $approvableId)
    {
        return $this->approvalFlowRepository->historyFor($approvableType, $approvableId);
    }
}
