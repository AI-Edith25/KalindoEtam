<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps a plain stdClass row from TaxReportRepository's UNION query (not an
 * Eloquent model — JsonResource proxies attribute access to the wrapped
 * object regardless, so this works identically to a model-backed Resource).
 * document_date/tax_rate arrive as raw DB strings (no Eloquent cast layer
 * on a query-builder row), formatted here so the frontend never has to
 * special-case this report's date/decimal shape.
 */
class TaxReportRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'document_id' => $this->document_id,
            'document_type' => $this->document_type,
            'document_number' => $this->document_number,
            'document_date' => $this->document_date,
            'party_id' => $this->party_id,
            'party_name' => $this->party_name,
            'branch_id' => $this->branch_id,
            'warehouse_id' => $this->warehouse_id,
            'tax_id' => $this->tax_id,
            'tax_code' => $this->tax_code,
            'tax_rate' => $this->tax_rate !== null ? (float) $this->tax_rate : null,
            'dpp' => (float) $this->dpp,
            'ppn' => (float) $this->ppn,
            'total' => (float) $this->dpp + (float) $this->ppn,
        ];
    }
}
