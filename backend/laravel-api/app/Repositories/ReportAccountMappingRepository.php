<?php

namespace App\Repositories;

use App\Enums\ReportStatementType;
use App\Models\ReportAccountMapping;

class ReportAccountMappingRepository extends BaseRepository
{
    public function __construct(ReportAccountMapping $model)
    {
        parent::__construct($model);
    }

    /**
     * One query, no N+1 — every mapping for a statement type, keyed by the
     * account it classifies. See docs/PROFIT_LOSS_DESIGN.md §4.
     *
     * @return array<string, ReportAccountMapping>
     */
    public function forStatementType(ReportStatementType $type): array
    {
        return $this->model->query()
            ->where('statement_type', $type->value)
            ->get()
            ->keyBy('chart_of_account_id')
            ->all();
    }
}
