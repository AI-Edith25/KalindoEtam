<?php

namespace App\Services\Import;

/**
 * What Layer 1 did to a file, for the "before → after" transparency panel in
 * the mapping step — cleaning is never applied silently.
 */
final class CleaningReport
{
    /**
     * @param  string[]  $droppedEmptyColumns
     * @param  string[]  $droppedConstantColumns
     * @param  array<int, array{column: string, before: mixed, after: mixed}>  $samples
     */
    public function __construct(
        public readonly array $droppedEmptyColumns = [],
        public readonly array $droppedConstantColumns = [],
        public readonly array $samples = [],
    ) {}

    public function toArray(): array
    {
        return [
            'dropped_empty_columns' => $this->droppedEmptyColumns,
            'dropped_constant_columns' => $this->droppedConstantColumns,
            'samples' => $this->samples,
        ];
    }
}
