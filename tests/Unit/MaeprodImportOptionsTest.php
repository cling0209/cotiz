<?php

namespace Tests\Unit;

use App\Support\MaeprodImportOptions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MaeprodImportOptionsTest extends TestCase
{
    #[Test]
    public function defaults_include_allow_create_and_all_updatable_fields(): void
    {
        $defaults = MaeprodImportOptions::defaults();

        $this->assertTrue($defaults['allow_create']);
        $this->assertSame(MaeprodImportOptions::UPDATABLE_FIELDS, $defaults['updatable_fields']);
    }

    #[Test]
    public function normalize_filters_unknown_fields_and_keeps_allow_create(): void
    {
        $options = MaeprodImportOptions::normalize([
            'allow_create' => false,
            'updatable_fields' => ['precio', 'hack', 'stock', 'precio'],
        ]);

        $this->assertFalse($options['allow_create']);
        $this->assertSame(['precio', 'stock'], $options['updatable_fields']);
    }

    #[Test]
    public function from_request_falls_back_to_defaults_for_missing_keys(): void
    {
        config([
            'cotiz.import.allow_create' => false,
            'cotiz.import.updatable_fields' => ['precio', 'costo'],
        ]);

        $options = MaeprodImportOptions::fromRequest([
            'updatable_fields' => ['nombre'],
        ]);

        $this->assertFalse($options['allow_create']);
        $this->assertSame(['nombre'], $options['updatable_fields']);
    }
}
