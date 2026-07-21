<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'status' => $this->status,
            'revision' => $this->revision,
            'posting_date' => $this->posting_date?->format('Y-m-d'),
            // Short stable key (e.g. "invoice"), never a raw class name — see the morph map in AppServiceProvider::boot().
            'reference_type' => $this->reference_type,
            'reference_label' => $this->reference_type ? ucwords(str_replace('_', ' ', $this->reference_type)) : null,
            'reference_id' => $this->reference_id,
            'reference_document_number' => $this->whenLoaded('referenceDocument', fn () => $this->referenceDocument?->document_number),
            'description' => $this->description,
            'total_debit' => $this->total_debit,
            'total_credit' => $this->total_credit,
            'reverses_id' => $this->reverses_id,
            'reverses_document_number' => $this->whenLoaded('reverses', fn () => $this->reverses?->document_number),
            'reversed_by_id' => $this->reversed_by_id,
            'reversed_by_document_number' => $this->whenLoaded('reversedBy', fn () => $this->reversedBy?->document_number),
            'lines' => JournalEntryLineResource::collection($this->whenLoaded('lines')),
            'created_by_name' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'submitted_at' => $this->submitted_at,
            'cancelled_at' => $this->cancelled_at,
            'created_at' => $this->created_at,
            'requires_approval' => $this->requiresApproval(),
            'latest_approval' => $this->whenLoaded('approvalFlows', fn () => $this->approvalFlows->sortByDesc('step')->first()
                ? new ApprovalFlowResource($this->approvalFlows->sortByDesc('step')->first())
                : null),
        ];
    }
}
