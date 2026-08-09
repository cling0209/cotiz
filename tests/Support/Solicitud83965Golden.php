<?php

namespace Tests\Support;

use PHPUnit\Framework\TestCase;

final class Solicitud83965Golden
{
    private const FIXTURE = __DIR__.'/../Fixtures/pdf_materiales/solicitud_83965_paddle_golden.json';

    /**
     * @return array{total: int, lineas: list<array{cantidad: int, descripcion: string, needle: string}>}
     */
    public static function load(): array
    {
        $json = file_get_contents(self::FIXTURE);
        if ($json === false) {
            throw new \RuntimeException('No se pudo leer el fixture golden: '.self::FIXTURE);
        }

        /** @var array{total: int, lineas: list<array{cantidad: int, descripcion: string, needle: string}>} $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @param  array<int, array{cantidad: int, descripcion: string}>  $lineas
     */
    public static function assertLineasMatchGolden(TestCase $test, array $lineas): void
    {
        $golden = self::load();

        $test->assertSame($golden['total'], count($lineas));
        $test->assertCount($golden['total'], $golden['lineas']);

        foreach ($golden['lineas'] as $esperada) {
            $encontrada = null;

            foreach ($lineas as $linea) {
                if (str_contains(
                    mb_strtoupper($linea['descripcion']),
                    mb_strtoupper($esperada['needle']),
                )) {
                    $encontrada = $linea;
                    break;
                }
            }

            $test->assertNotNull(
                $encontrada,
                'Falta producto golden: '.$esperada['needle'],
            );
            $test->assertSame(
                $esperada['cantidad'],
                $encontrada['cantidad'],
                'Cantidad incorrecta para: '.$esperada['needle'],
            );
        }
    }
}
