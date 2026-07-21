<?php

namespace App\Services;

use App\Contracts\DocumentNumberGeneratorInterface;
use App\Repositories\NamingSeriesRepository;
use Illuminate\Support\Facades\DB;

class DocumentNumberGeneratorService implements DocumentNumberGeneratorInterface
{
    public function __construct(protected NamingSeriesRepository $namingSeriesRepository) {}

    public function generate(string $documentType): string
    {
        return DB::transaction(function () use ($documentType) {
            $series = $this->namingSeriesRepository->lockDefaultForType($documentType);

            $nextNumber = $series->current_number + 1;
            $series->update(['current_number' => $nextNumber]);

            return $series->prefix
                .str_pad((string) $nextNumber, $series->digit_length, '0', STR_PAD_LEFT)
                .$series->suffix;
        });
    }
}
