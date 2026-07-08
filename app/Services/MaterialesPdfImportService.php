<?php

namespace App\Services;

use App\Models\Nota;
use Illuminate\Http\UploadedFile;

class MaterialesPdfImportService
{
    public function __construct(
        protected ListadoMaterialesPdfParserService $parser,
        protected CompraAgilImportService $compraAgilImport,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(UploadedFile $file): array
    {
        $datos = $this->datosDesdePdf($file);
        $total = count($datos['lineas']);
        $resultado = $this->compraAgilImport->previewLoteDesdeDatos($datos, 0, $total);
        unset($resultado['total'], $resultado['procesadas'], $resultado['completado']);

        return $resultado;
    }

    /**
     * @return array<string, mixed>
     */
    public function previewLote(UploadedFile $file, int $desde, int $hasta): array
    {
        $datos = $this->datosDesdePdf($file);

        return $this->compraAgilImport->previewLoteDesdeDatos($datos, $desde, $hasta);
    }

    /**
     * @return array<string, mixed>
     */
    public function aplicar(Nota $nota, UploadedFile $file, string $usuario): array
    {
        $datos = $this->datosDesdePdf($file);
        $total = count($datos['lineas']);
        $resultado = $this->compraAgilImport->aplicarLoteDesdeDatos($nota, $datos, $usuario, 0, $total);
        unset($resultado['total'], $resultado['procesadas'], $resultado['completado']);

        return $resultado;
    }

    /**
     * @return array<string, mixed>
     */
    public function aplicarLote(Nota $nota, UploadedFile $file, string $usuario, int $desde, int $hasta): array
    {
        $datos = $this->datosDesdePdf($file);

        return $this->compraAgilImport->aplicarLoteDesdeDatos($nota, $datos, $usuario, $desde, $hasta);
    }

    /**
     * @return array{
     *   cabecera: array{codigo_cotizacion: string, empresa: string, rutempresa: string, nombre: string},
     *   lineas: array<int, array{id_agile: string, descripcion: string, cantidad: int, categoria: string}>
     * }
     */
    private function datosDesdePdf(UploadedFile $file): array
    {
        $parseadas = $this->parser->parseUploadedFile($file);
        $lineas = [];

        foreach ($parseadas as $fila) {
            $descripcion = trim($fila['descripcion']);
            if ($descripcion === '') {
                continue;
            }

            $lineas[] = [
                'id_agile' => $this->idAgileParaDescripcion($descripcion),
                'descripcion' => $descripcion,
                'cantidad' => max(1, (int) $fila['cantidad']),
                'categoria' => '',
            ];
        }

        return [
            'cabecera' => [
                'codigo_cotizacion' => '',
                'empresa' => '',
                'rutempresa' => '',
                'nombre' => '',
            ],
            'lineas' => $lineas,
        ];
    }

    private function idAgileParaDescripcion(string $descripcion): string
    {
        $normalizada = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $descripcion) ?? $descripcion));

        return 'pdf:'.substr(md5($normalizada), 0, 46);
    }
}
