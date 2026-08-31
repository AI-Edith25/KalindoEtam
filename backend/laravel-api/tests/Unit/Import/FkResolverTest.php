<?php

namespace Tests\Unit\Import;

use App\Models\ItemGroup;
use App\Services\Import\FkResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FkResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_classifies_exact_case_insensitive_match(): void
    {
        $group = ItemGroup::query()->create(['name' => 'General']);

        $result = (new FkResolver)->classify(ItemGroup::class, 'name', ['general']);

        $this->assertSame('match', $result['general']['status']);
        $this->assertSame($group->id, $result['general']['id']);
    }

    public function test_classifies_typo_as_ambiguous_with_suggestion(): void
    {
        $group = ItemGroup::query()->create(['name' => 'General']);

        $result = (new FkResolver)->classify(ItemGroup::class, 'name', ['Genral']);

        $this->assertSame('ambiguous', $result['Genral']['status']);
        $this->assertNull($result['Genral']['id']);
        $this->assertSame($group->id, $result['Genral']['suggestions'][0]['id']);
    }

    public function test_classifies_unrelated_value_as_no_match(): void
    {
        ItemGroup::query()->create(['name' => 'General']);

        $result = (new FkResolver)->classify(ItemGroup::class, 'name', ['Zzzqqqxxx']);

        $this->assertSame('no_match', $result['Zzzqqqxxx']['status']);
        $this->assertNull($result['Zzzqqqxxx']['id']);
    }

    public function test_deduplicates_repeated_raw_values(): void
    {
        ItemGroup::query()->create(['name' => 'General']);

        $result = (new FkResolver)->classify(ItemGroup::class, 'name', ['General', 'General', ' General ']);

        $this->assertCount(1, $result);
    }
}
