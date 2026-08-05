<?php

namespace App\Services;

use App\Contracts\DocumentNumberGeneratorInterface;
use App\Exceptions\BusinessException;
use App\Repositories\NamingSeriesRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DocumentNumberGeneratorService implements DocumentNumberGeneratorInterface
{
    public function __construct(protected NamingSeriesRepository $namingSeriesRepository) {}

    public function generate(string $documentType): string
    {
        return DB::transaction(function () use ($documentType) {
            $series = $this->namingSeriesRepository->lockDefaultForType($documentType);

            if ($series === null) {
                $label = Str::headline($documentType);

                throw new BusinessException("No active Naming Series is configured for \"{$label}\". Set one up under Administration > Naming Series before creating this document.");
            }

            $nextNumber = $series->current_number + 1;
            $series->update(['current_number' => $nextNumber]);

            return $series->prefix
                .str_pad((string) $nextNumber, $series->digit_length, '0', STR_PAD_LEFT)
                .$series->suffix;
        });
    }
}
