<?php

namespace App\Contracts;

interface DocumentNumberGeneratorInterface
{
    /**
     * Generate the next document number for the given document type,
     * using its default active NamingSeries. Throws if none is configured.
     */
    public function generate(string $documentType): string;

    /**
     * Preview the number `generate()` would produce next, without
     * incrementing the counter — for UI display only (e.g. a disabled
     * "Customer Code" field showing what will be assigned on save). The
     * previewed value can go stale if another request generates one in
     * between; only `generate()`'s result is authoritative. Throws if
     * no series is configured, same as `generate()`.
     */
    public function peek(string $documentType): string;
}
