<?php

namespace Tests\Unit\Support;

use App\Support\DocumentKeyNormalizer;
use PHPUnit\Framework\TestCase;

class DocumentKeyNormalizerTest extends TestCase
{
    public function test_trims_and_uppercases(): void
    {
        $this->assertSame('SI/KE/00001/09/2026', DocumentKeyNormalizer::normalize(' si/ke/00001/09/2026 '));
    }

    public function test_blank_and_null_normalize_to_null(): void
    {
        $this->assertNull(DocumentKeyNormalizer::normalize(null));
        $this->assertNull(DocumentKeyNormalizer::normalize(''));
        $this->assertNull(DocumentKeyNormalizer::normalize('   '));
    }

    public function test_already_normalized_value_is_unchanged(): void
    {
        $this->assertSame('OR/KE/00007/08/2026', DocumentKeyNormalizer::normalize('OR/KE/00007/08/2026'));
    }
}
