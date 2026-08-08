<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceChangeRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'status' => $this->status,
            'requested_by' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy ? [
                'id' => $this->requestedBy->id,
                'name' => $this->requestedBy->name,
                'email' => $this->requestedBy->email,
            ] : null),
            'request_reason' => $this->request_reason,
            'decided_by' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy ? [
                'id' => $this->decidedBy->id,
                'name' => $this->decidedBy->name,
                'email' => $this->decidedBy->email,
            ] : null),
            'decision_remarks' => $this->decision_remarks,
            'decided_at' => $this->decided_at,
            'consumed_at' => $this->consumed_at,
            'created_at' => $this->created_at,
        ];
    }
}
