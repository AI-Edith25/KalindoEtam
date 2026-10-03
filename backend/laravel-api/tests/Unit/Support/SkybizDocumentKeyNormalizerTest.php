<?php

namespace Tests\Unit\Support;

use App\Support\SkybizDocumentKeyNormalizer;
use PHPUnit\Framework\TestCase;

class SkybizDocumentKeyNormalizerTest extends TestCase
{
    public function test_current_ke_format_resolves_strict_and_loose(): void
    {
        $result = SkybizDocumentKeyNormalizer::normalize('SI/KE/07133/08/2026');

        $this->assertSame('SI', $result['type']);
        $this->assertSame(7133, $result['seq']);
        $this->assertSame('SI|7133|8|2026', $result['strict_key']);
        $this->assertSame('SI|7133', $result['loose_key']);
    }

    public function test_old_dash_format_with_month_and_year_resolves_strict(): void
    {
        $result = SkybizDocumentKeyNormalizer::normalize('SI-KE-7212-08-2025');

        $this->assertSame('SI', $result['type']);
        $this->assertSame(7212, $result['seq']);
        $this->assertSame('SI|7212|8|2025', $result['strict_key']);
        $this->assertSame('SI|7212', $result['loose_key']);
    }

    public function test_old_format_without_month_year_is_loose_only(): void
    {
        $result = SkybizDocumentKeyNormalizer::normalize('SI-KE-00746');

        $this->assertSame('SI', $result['type']);
        $this->assertSame(746, $result['seq']);
        $this->assertNull($result['strict_key']);
        $this->assertSame('SI|746', $result['loose_key']);
    }

    public function test_very_old_branch_prefixed_tr_format_is_loose_only(): void
    {
        $result = SkybizDocumentKeyNormalizer::normalize('KE-TR-BPP-00017');

        $this->assertSame('TR', $result['type']);
        $this->assertSame(17, $result['seq']);
        $this->assertNull($result['strict_key']);
        $this->assertSame('TR|17', $result['loose_key']);
    }

    public function test_very_old_si_format_with_branch_and_date_resolves_strict(): void
    {
        $result = SkybizDocumentKeyNormalizer::normalize('SI-BPP-00017-01-2023');

        $this->assertSame('SI', $result['type']);
        $this->assertSame(17, $result['seq']);
        $this->assertSame('SI|17|1|2023', $result['strict_key']);
        $this->assertSame('SI|17', $result['loose_key']);
    }

    public function test_bare_numbers_are_unparseable(): void
    {
        $this->assertNull(SkybizDocumentKeyNormalizer::normalize('127'));
        $this->assertNull(SkybizDocumentKeyNormalizer::normalize('300920222'));
    }

    public function test_no_digit_token_is_unparseable(): void
    {
        $this->assertNull(SkybizDocumentKeyNormalizer::normalize('SI-KE-ABC'));
    }

    public function test_blank_and_null_are_unparseable(): void
    {
        $this->assertNull(SkybizDocumentKeyNormalizer::normalize(null));
        $this->assertNull(SkybizDocumentKeyNormalizer::normalize(''));
    }

    public function test_leading_zeros_are_dropped_from_seq(): void
    {
        $result = SkybizDocumentKeyNormalizer::normalize('SI/KE/00001/09/2026');

        $this->assertSame(1, $result['seq']);
        $this->assertSame('SI|1|9|2026', $result['strict_key']);
    }
}
