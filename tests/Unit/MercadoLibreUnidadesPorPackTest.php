<?php

namespace Tests\Unit;

use App\Services\MercadoLibreApiService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MercadoLibreUnidadesPorPackTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function titulos(): array
    {
        return [
            'unidades' => ['Pastilla Para Estanque Inodoro 4 Un Azul Ambientador', 4],
            'piezas abreviado' => ['Pastilla Para Estanque De Inodoro Azul 4 Pcs', 4],
            'piezas' => ['Set Vasos Vidrio 6 Piezas', 6],
            'pack' => ['Pack 12 Pastilla Para Estanque De Inodoro Azul 2pcs', 12],
            'medida no es pack' => ['Cartulina Color 53.5 X 77 Blanca', 1],
            'gramos no es pack' => ['Pastilla Para Inodoro Glade Pino 25 Gr', 1],
        ];
    }

    #[DataProvider('titulos')]
    public function test_unidades_por_pack_desde_el_titulo(string $titulo, int $esperado): void
    {
        $this->assertSame($esperado, app(MercadoLibreApiService::class)->unidadesPorPack($titulo));
    }

    public function test_unidades_desde_atributos_del_catalogo(): void
    {
        $svc = app(MercadoLibreApiService::class);
        $atributos = fn (array $valores) => ['attributes' => array_map(
            static fn (string $id, string $valor) => ['id' => $id, 'value_name' => $valor],
            array_keys($valores),
            array_values($valores),
        )];

        $this->assertSame(3, $svc->unidadesDesdeAtributos($atributos(['UNITS_PER_PACK' => '1', 'UNITS_PER_PACKAGE' => '3'])));
        $this->assertSame(12, $svc->unidadesDesdeAtributos($atributos(['UNITS_PER_PACK' => '3', 'UNITS_PER_PACKAGE' => '4'])));
        $this->assertSame(1, $svc->unidadesDesdeAtributos($atributos(['SALE_FORMAT' => 'Unidad'])));
        $this->assertSame(1, $svc->unidadesDesdeAtributos([]));
    }
}
