<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One Journal List row — wraps the array shape JournalListService::summarize() builds per cash/bank line. */
class JournalListLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'transaction' => $this->resource['transaction'],
            'date' => $this->resource['date'],
            'ref_no' => $this->resource['ref_no'],
            'particulars' => $this->resource['particulars'],
            'debit' => $this->resource['debit'],
            'credit' => $this->resource['credit'],
        ];
    }
}
