<?php

namespace App\Services;

use App\Support\SoftlandProductoImportLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CotizacionListadoExportService
{
    /**
     * @return Collection<int, object{prod_item: string, prod_nombre: string, prod_valor: int, valorconimpuesto: int}>
     */
    public function productosSinCodigoSoftland(): Collection
    {
        $rows = DB::select("
            SELECT prod_item, prod_nombre, prod_valor, valorconimpuesto
            FROM (
                SELECT
                    nd.prod_item,
                    m.prod_nombre,
                    nd.prod_valor,
                    ROUND(nd.prod_valor * 1.19)::int AS valorconimpuesto,
                    ROW_NUMBER() OVER (
                        PARTITION BY nd.prod_item
                        ORDER BY nd.fechahora DESC
                    ) AS rn
                FROM notasdetalle nd
                INNER JOIN notas n ON n.nronota = nd.nronota
                INNER JOIN maeprod m ON m.prod_item = nd.prod_item
                WHERE LOWER(COALESCE(n.estado, '')) = 'aceptada'
                  AND COALESCE(m.prod_item_softland, '') = ''
                  AND m.prod_item <> ''
            ) ranked
            WHERE rn = 1
            ORDER BY prod_item
        ");

        return collect($rows);
    }

    public function respuestaSinCodigoSoftlandTxt(string $username): StreamedResponse
    {
        $productos = $this->productosSinCodigoSoftland();
        $contenido = $productos
            ->map(fn ($p) => SoftlandProductoImportLine::build(
                (string) $p->prod_item,
                (string) $p->prod_nombre,
                (int) $p->prod_valor,
                (int) $p->valorconimpuesto,
            ))
            ->implode("\n");

        $nombre = 'sin_codigo_softland_'.$username.'_'.now()->format('YmdHis').'.TXT';

        return response()->streamDownload(
            static function () use ($contenido) {
                echo $contenido;
            },
            $nombre,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    public function respuestaAceptadasCsv(): StreamedResponse
    {
        $nombre = 'notaventa_aceptadas_'.now()->format('d-m-Y_His').'.csv';

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'Nota', 'Fecha', 'Empresa', 'Nro.Cotización', 'Total', 'Usuario', 'Fecha Aceptación', 'Usuario Aceptación',
            ], ';');

            $rows = DB::table('notas as n')
                ->leftJoinSub(
                    DB::table('notasdetalle')
                        ->selectRaw('nronota, COALESCE(SUM(prod_valor * cantidad), 0) AS total')
                        ->groupBy('nronota'),
                    'nd_tot',
                    'nd_tot.nronota',
                    '=',
                    'n.nronota'
                )
                ->whereRaw("LOWER(COALESCE(n.estado, '')) = 'aceptada'")
                ->orderByDesc('n.nronota')
                ->limit(5000)
                ->get([
                    'n.nronota',
                    'n.fecha',
                    'n.empresa',
                    'n.encargado',
                    DB::raw('COALESCE(nd_tot.total, 0) AS total'),
                    'n.usuario',
                    'n.estadofecha',
                    'n.estadousuario',
                ]);

            foreach ($rows as $row) {
                $fecha = $row->fecha ? date('d/m/Y', strtotime((string) $row->fecha)) : '';
                $estadoFecha = $row->estadofecha
                    ? date('d/m/Y H:i:s', strtotime((string) $row->estadofecha))
                    : '';

                fputcsv($out, [
                    $row->nronota,
                    $fecha,
                    $row->empresa,
                    $row->encargado,
                    (int) $row->total,
                    $row->usuario,
                    $estadoFecha,
                    $row->estadousuario,
                ], ';');
            }

            fclose($out);
        }, $nombre, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
