<?php

namespace App\Services\Import\Templates\Concerns;

/** For templates with nothing to derive — every field maps straight from a real file header. */
trait HasNoRowTransform
{
    public function transformRow(array $row): array
    {
        return $row;
    }
}
