<?php

namespace App\Services;

use App\Models\Maeprod;
use App\Models\Nota;
use App\Models\NotaDetalle;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CotizacionExportService
{
    public function __construct(
        protected NotaDetalleService $detalleService,
    ) {}

    public function cargarNota(int $nronota): Nota
    {
        return Nota::query()
            ->with([
                'detalle' => fn ($q) => $q->with('producto')->orderBy('orden'),
                'usuarioRel',
            ])
            ->findOrFail($nronota);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function lineasParaExport(Nota $nota): Collection
    {
        return $this->detalleService->lineasDeNota($nota);
    }

    public function datosPdf(Nota $nota): array
    {
        $lineas = $this->lineasParaExport($nota);
        $totalNeto = $lineas->sum(fn ($row) => $row['total']);
        $iva = (int) round($totalNeto * 19 / 100);

        return [
            'nota' => $nota,
            'lineas' => $lineas,
            'totalNeto' => $totalNeto,
            'iva' => $iva,
            'total' => $totalNeto + $iva,
            'fecha' => now()->format('d-m-Y'),
            'vendedor' => $nota->usuarioRel?->nombre ?? $nota->usuario,
        ];
    }

    public function respuestaSoftlandTxt(Nota $nota): StreamedResponse
    {
        $lineas = $this->lineasParaExport($nota);
        $contenido = $lineas
            ->map(fn ($row) => $this->filaSoftland($nota, $row['linea'], $row['linea']->producto, $row['prod_nombre']))
            ->implode("\n");

        $nombre = 'NOTA_'.$nota->nronota.'_'.now()->format('YmdHis').'.TXT';

        return $this->respuestaTexto($contenido, $nombre, 'text/plain; charset=UTF-8');
    }

    public function respuestaGuiaTxt(Nota $nota): StreamedResponse
    {
        $lineas = $this->lineasParaExport($nota);
        $contenido = $lineas
            ->map(fn ($row) => $row['linea']->prod_item.str_repeat(';', 7).trim((string) $row['linea']->cantidad))
            ->implode("\n");

        $nombre = 'GUIA_'.$nota->nronota.'_'.now()->format('YmdHis').'.TXT';

        return $this->respuestaTexto($contenido, $nombre, 'text/plain; charset=UTF-8');
    }

    public function respuestaGuiaIngresoCsv(Nota $nota): StreamedResponse
    {
        $bodega = (string) config('cotiz.codigo_bodega', '01');
        $concepto = (string) config('cotiz.concepto_bodega', '26');
        $proveedor = (string) config('cotiz.codigo_proveedor', '76185139');
        $vacias = array_fill(0, 13, '');
        $folio = (string) $nota->nronota;
        $fecha = $nota->fecha?->format('d/m/Y') ?? now()->format('d/m/Y');

        $nombre = 'notasguiaingreso_'.$nota->nronota.'_'.now()->format('YmdHis').'.csv';

        return response()->streamDownload(function () use ($nota, $bodega, $concepto, $proveedor, $vacias, $folio, $fecha) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            foreach ($nota->detalle as $linea) {
                $codigo = trim((string) ($linea->producto?->prod_item_softland ?? ''));
                $fila = array_merge(
                    [$bodega, $folio, $fecha, $concepto, '', $proveedor],
                    $vacias,
                    [$codigo, '', '', (string) $linea->cantidad],
                    ['', '', '', '', '', '', '', '']
                );
                fputcsv($out, $fila, ';', '"');
            }

            fclose($out);
        }, $nombre, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function respuestaExcel(Nota $nota): StreamedResponse
    {
        $lineas = $this->lineasParaExport($nota);
        $nombre = 'notaventa_'.$nota->nronota.'_'.now()->format('d-m-Y_His').'.csv';

        return response()->streamDownload(function () use ($nota, $lineas) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'Rut Empresa', 'Cliente', 'Cotización', 'Celular', 'Contacto', 'E-Mail',
            ], ';');
            fputcsv($out, [
                $nota->rutempresa ?? '',
                $nota->empresa ?? '',
                $nota->encargado ?? '',
                $nota->celular ?? '',
                $nota->contacto ?? '',
                $nota->contactocorreo ?? '',
            ], ';');

            fputcsv($out, [], ';');
            fputcsv($out, [
                'Código', 'Código Softland', 'Descripción Producto', 'Precio Unitario', 'Cantidad', 'Total',
            ], ';');

            foreach ($lineas as $row) {
                /** @var NotaDetalle $linea */
                $linea = $row['linea'];
                fputcsv($out, [
                    $linea->prod_item,
                    $row['prod_item_softland'],
                    $row['prod_nombre'],
                    $linea->prod_valor,
                    $linea->cantidad,
                    $row['total'],
                ], ';');
            }

            fclose($out);
        }, $nombre, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function filaSoftland(Nota $nota, NotaDetalle $linea, ?Maeprod $producto, string $prodNombre): string
    {
        $fecha = $nota->fecha?->format('d-m-Y') ?? '';
        $fechaEntrega = $nota->fechaentrega?->format('d-m-Y') ?? '';
        $rut = (string) ($nota->rutempresa ?? '');
        $softland = trim((string) ($producto?->prod_item_softland ?? ''));

        $campos = [
            (string) ($nota->nota_softland ?? ''),
            $this->entreComillas($fecha),
            $this->entreComillas($fechaEntrega),
            '',
            $this->entreComillas((string) ($nota->ocompra ?? '')),
            $this->entreComillas($rut),
            $this->entreComillas('01'),
            '',
            '',
            $this->entreComillas((string) $nota->descripcion),
            '',
            '',
            $this->entreComillas($rut),
            '',
            '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '',
            '',
            $this->entreComillas($softland),
            '',
            (string) $linea->cantidad,
            (string) $linea->prod_valor,
            '', '', '', '', '', '', '', '', '',
            '', '', '',
            $this->entreComillas(trim($prodNombre)),
            '', '', '', '',
        ];

        return implode(';', $campos);
    }

    private function entreComillas(string $valor): string
    {
        return '"'.str_replace('"', '""', $valor).'"';
    }

    private function respuestaTexto(string $contenido, string $nombre, string $contentType): StreamedResponse
    {
        return response()->streamDownload(
            static function () use ($contenido) {
                echo $contenido;
            },
            $nombre,
            ['Content-Type' => $contentType]
        );
    }
}
