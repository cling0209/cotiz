<?php

namespace Tests\Unit;

use App\Support\ProductCodeNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductCodeNormalizerTest extends TestCase
{
    #[DataProvider('scientificNotationProvider')]
    public function test_expands_scientific_notation_strings(mixed $input, string $expected): void
    {
        $this->assertSame($expected, ProductCodeNormalizer::normalize($input));
    }

    public static function scientificNotationProvider(): array
    {
        return [
            'excel chile comma' => ['5,01E+13', '50100000000000'],
            'dot decimal' => ['5.01e+13', '50100000000000'],
            'plain code unchanged' => ['DEMO001', 'DEMO001'],
            'numeric string unchanged' => ['7810266640415', '7810266640415'],
            'float whole number' => [50100000000000.0, '50100000000000'],
        ];
    }
}
