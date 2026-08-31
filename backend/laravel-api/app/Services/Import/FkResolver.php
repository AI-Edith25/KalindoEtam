<?php

namespace App\Services\Import;

/** Classifies raw FK column values against a target master table using stdlib similar_text — no new dependency. */
final class FkResolver
{
    private const FUZZY_THRESHOLD = 70.0;

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @param  string[]  $values  raw, uncleaned values as they appear in the file
     * @return array<string, array{status: string, id: string|null, suggestions: array<int, array{id: string, value: string, score: float}>}>
     */
    public function classify(string $modelClass, string $displayColumn, array $values): array
    {
        $distinct = array_values(array_unique(array_filter(
            array_map(fn ($v) => is_string($v) ? trim($v) : $v, $values),
            fn ($v) => $v !== null && $v !== ''
        )));

        $candidates = $modelClass::query()->get(['id', $displayColumn]);

        $result = [];

        foreach ($distinct as $value) {
            $exact = $candidates->first(
                fn ($row) => mb_strtolower(trim((string) $row->{$displayColumn})) === mb_strtolower($value)
            );

            if ($exact) {
                $result[$value] = ['status' => 'match', 'id' => $exact->id, 'suggestions' => []];

                continue;
            }

            $scored = $candidates->map(function ($row) use ($value, $displayColumn) {
                similar_text(mb_strtolower($value), mb_strtolower((string) $row->{$displayColumn}), $percent);

                return ['id' => $row->id, 'value' => $row->{$displayColumn}, 'score' => round($percent, 1)];
            })->sortByDesc('score')->values();

            $bestScore = $scored->first()['score'] ?? 0.0;

            $result[$value] = [
                'status' => $bestScore >= self::FUZZY_THRESHOLD ? 'ambiguous' : 'no_match',
                'id' => null,
                'suggestions' => $scored->take(3)->all(),
            ];
        }

        return $result;
    }
}
