<?php

namespace App\Contracts;

interface DocumentNumberGeneratorInterface
{
    /**
     * Generate the next document number for the given document type,
     * using its default active NamingSeries. Throws if none is configured.
     */
    public function generate(string $documentType): string;
}
