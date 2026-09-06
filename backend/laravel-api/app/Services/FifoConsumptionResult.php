<?php

namespace App\Services;

/**
 * Result of FifoLayerService::consume() — everything a caller needs to post
 * COGS or carry cost into a paired IN movement (Stock Transfer's destination
 * leg, Credit Note restock's averageConsumedCost()).
 *
 * @param  array<int, array{fifo_layer_id: string, qty_consumed: float, unit_cost: float, total_cost: float}>  $lines
 */
final class FifoConsumptionResult
{
    public function __construct(
        public readonly float $totalCost,
        public readonly float $weightedAverageUnitCost,
        public readonly array $lines,
    ) {}
}
