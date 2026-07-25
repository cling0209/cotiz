<?php

namespace Tests\Unit;

use App\Services\CompraAgilApiService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CompraAgilOportunidadExisteMpTest extends TestCase
{
    public function test_mensaje_no_existe_incluye_codigo(): void
    {
        $this->assertSame(
            'La cotización «273-611-COT26» no existe en Mercado Público. No se puede cargar.',
            CompraAgilApiService::mensajeNoExisteEnMp('273-611-COT26'),
        );
    }

    #[DataProvider('mensajesNoExisteProvider')]
    public function test_detecta_mensajes_de_no_existe_en_mp(string $mensaje, bool $esperado): void
    {
        $this->assertSame($esperado, CompraAgilApiService::esNoExisteEnMp($mensaje));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function mensajesNoExisteProvider(): array
    {
        return [
            'mensaje_nuevo' => [
                'La cotización «999-000-COT26» no existe en Mercado Público. No se puede cargar.',
                true,
            ],
            'mensaje_legacy' => [
                'No existe Compra Ágil con el código indicado.',
                true,
            ],
            'otro_error' => [
                'Timeout o error de conexión con Mercado Público.',
                false,
            ],
        ];
    }

    public function test_es_codigo_compra_agil(): void
    {
        $svc = app(\App\Services\CompraAgilOportunidadService::class);

        $this->assertTrue($svc->esCodigoCompraAgil('273-611-COT26'));
        $this->assertFalse($svc->esCodigoCompraAgil('COT-IMPORT-001'));
        $this->assertFalse($svc->esCodigoCompraAgil(''));
    }
}
