<?php

namespace App\Services;

use App\Contracts\DocumentNumberGeneratorInterface;
use App\Exceptions\BusinessException;
use App\Models\NamingSeries;
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
                throw $this->unconfigured($documentType);
            }

            $nextNumber = $series->current_number + 1;
            $series->update(['current_number' => $nextNumber]);

            return $this->format($series, $nextNumber);
        });
    }

    public function peek(string $documentType): string
    {
        $series = $this->namingSeriesRepository->findDefaultForType($documentType);

        if ($series === null) {
            throw $this->unconfigured($documentType);
        }

        return $this->format($series, $series->current_number + 1);
    }

    protected function unconfigured(string $documentType): BusinessException
    {
        $label = Str::headline($documentType);

        return new BusinessException("No active Naming Series is configured for \"{$label}\". Set one up under Administration > Naming Series before creating this document.");
    }

    protected function format(NamingSeries $series, int $number): string
    {
        return $this->interpolate($series->prefix)
            .str_pad((string) $number, $series->digit_length, '0', STR_PAD_LEFT)
            .$this->interpolate($series->suffix);
    }

    /**
     * {MM}/{YYYY} tokens, resolved to the generation date — opt-in only, a
     * prefix/suffix with no token is returned unchanged (every series but
     * Invoice's today). Not a reset boundary: current_number keeps counting
     * up regardless of month/year, the tokens are a display tag only.
     */
    protected function interpolate(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return str_replace(['{MM}', '{YYYY}'], [now()->format('m'), now()->format('Y')], $value);
    }
}
