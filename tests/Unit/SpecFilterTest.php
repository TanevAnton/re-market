<?php

namespace Tests\Unit;

use App\Support\SpecFilter;
use Tests\TestCase;

/**
 * SpecFilter interpolates a column name into SQL, so its schema whitelist is
 * a security boundary, not a convenience. These tests pin that behaviour.
 */
class SpecFilterTest extends TestCase
{
    public function test_it_reads_facets_from_the_catalogue_config(): void
    {
        $facets = (new SpecFilter('gpu'))->facets();

        $this->assertArrayHasKey('vram_gb', $facets);
        $this->assertArrayHasKey('length_mm', $facets);
    }

    public function test_facets_come_back_in_priority_order(): void
    {
        $keys = array_keys((new SpecFilter('gpu'))->facets());

        $this->assertSame('chipset', $keys[0]);
        $this->assertSame('vram_gb', $keys[1]);
    }

    public function test_only_keys_declared_in_the_schema_are_accepted(): void
    {
        $filter = new SpecFilter('gpu');

        $this->assertTrue($filter->isKnownKey('vram_gb'));
        $this->assertFalse($filter->isKnownKey('socket'));          // wrong category
        $this->assertFalse($filter->isKnownKey("1) OR 1=1--"));      // injection attempt
        $this->assertFalse($filter->isKnownKey('specs; DROP TABLE listings'));
    }

    public function test_an_unknown_category_yields_no_facets(): void
    {
        $this->assertSame([], (new SpecFilter('nonsense'))->facets());
    }

    public function test_labels_and_units_come_from_the_schema(): void
    {
        $filter = new SpecFilter('gpu');

        $this->assertSame('Видео памет', $filter->label('vram_gb'));
        $this->assertSame('GB', $filter->unit('vram_gb'));
        $this->assertSame('мм', $filter->unit('length_mm'));
    }

    public function test_every_category_in_the_catalogue_is_listable(): void
    {
        $categories = SpecFilter::categories();

        $this->assertNotEmpty($categories);
        foreach ($categories as $c) {
            $this->assertArrayHasKey('key', $c);
            $this->assertArrayHasKey('label', $c);
            $this->assertNotEmpty($c['label'], "category {$c['key']} has no label");
        }
    }
}
