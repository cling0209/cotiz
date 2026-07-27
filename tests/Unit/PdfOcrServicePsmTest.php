<?php

namespace Tests\Unit;

use App\Services\PdfOcrService;
use ReflectionMethod;
use Tests\TestCase;

class PdfOcrServicePsmTest extends TestCase
{
    public function test_psm_6_y_7_se_remapean_a_4(): void
    {
        $ocr = new PdfOcrService;
        $metodo = new ReflectionMethod(PdfOcrService::class, 'resolverPsm');
        $metodo->setAccessible(true);

        config(['cotiz.ocr.psm' => 6]);
        $this->assertSame(4, $metodo->invoke($ocr));

        config(['cotiz.ocr.psm' => 7]);
        $this->assertSame(4, $metodo->invoke($ocr));

        config(['cotiz.ocr.psm' => 4]);
        $this->assertSame(4, $metodo->invoke($ocr));

        config(['cotiz.ocr.psm' => 3]);
        $this->assertSame(3, $metodo->invoke($ocr));
    }
}
