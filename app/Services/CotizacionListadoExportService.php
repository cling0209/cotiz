<?php

namespace App\Services;

use App\Models\User;
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

    /**
     * @param  array{nronota: int, usuario?: string, fechaentregadesde: ?string, fechaentregahasta: ?string}  $filtros
     */
    public function respuestaAceptadasDetalleCsv(User $user, array $filtros): StreamedResponse
    {
        $nombre = 'notaventa_aceptadas_detalle_'.now()->format('d-m-Y_His').'.csv';

        return response()->streamDownload(function () use ($user, $filtros) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'Nota', 'Fecha', 'Empresa', 'Nro.Cotización', 'Celular', 'Contacto', 'Correo',
                'Rut Empresa', 'Días Hábiles', 'Orden de Compra', 'Fecha Entrega', 'Descripción',
                'Usuario', 'Nombre Usuario', 'Código', 'Precio Costo', 'Precio Venta', 'Cantidad', 'Total',
            ], ';');

            $query = DB::table('notas as n')
                ->join('notasdetalle as nd', 'nd.nronota', '=', 'n.nronota')
                ->leftJoin('users as u', 'u.username', '=', 'n.usuario')
                ->whereRaw("LOWER(COALESCE(n.estado, '')) = 'aceptada'")
                ->orderByDesc('n.nronota')
                ->orderBy('nd.orden');

            if ($user->username !== 'admin') {
                $query->where('n.usuario', '<>', 'admin');
            }

            if (! empty($filtros['nronota'])) {
                $query->where('n.nronota', (int) $filtros['nronota']);
            }

            $usuario = trim((string) ($filtros['usuario'] ?? ''));
            if ($usuario !== '') {
                $query->where('n.usuario', $usuario);
            }

            $desde = $filtros['fechaentregadesde'] ?? null;
            $hasta = $filtros['fechaentregahasta'] ?? null;
            if ($desde && $hasta) {
                $query->whereDate('n.fechaentrega', '>=', $desde)
                    ->whereDate('n.fechaentrega', '<=', $hasta);
            }

            $rows = $query->get([
                'n.nronota',
                'n.fecha',
                'n.empresa',
                'n.encargado',
                'n.celular',
                'n.contacto',
                'n.contactocorreo',
                'n.rutempresa',
                'n.diashabiles',
                'n.ocompra',
                'n.fechaentrega',
                'n.descripcion',
                'n.usuario',
                DB::raw("TRIM(CONCAT(COALESCE(u.nombre, ''), ' ', COALESCE(u.apellidop, ''))) AS nombre_usuario"),
                'nd.prod_item',
                'nd.prod_valor_costo',
                'nd.prod_valor',
                'nd.cantidad',
                DB::raw('(nd.prod_valor * nd.cantidad) AS total'),
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->nronota,
                    $row->fecha ? date('d/m/Y', strtotime((string) $row->fecha)) : '',
                    $row->empresa,
                    $row->encargado,
                    $row->celular,
                    $row->contacto,
                    $row->contactocorreo,
                    $row->rutempresa,
                    $row->diashabiles,
                    $row->ocompra,
                    $row->fechaentrega ? date('d/m/Y', strtotime((string) $row->fechaentrega)) : '',
                    $row->descripcion,
                    $row->usuario,
                    trim((string) $row->nombre_usuario),
                    $row->prod_item,
                    (int) $row->prod_valor_costo,
                    (int) $row->prod_valor,
                    (int) $row->cantidad,
                    (int) $row->total,
                ], ';');
            }

            fclose($out);
        }, $nombre, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
