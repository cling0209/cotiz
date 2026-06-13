<?php

namespace App\Services;

use App\Models\MaeprodImportErrorLog;
use App\Models\MaeprodImportRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MaeprodImportRunService
{
    /**
     * @return array{created: int, updated: int, skipped: int, errors: list<array{fila: int|null, codigo: string, nombre: string, familia: string, mensaje: string, detalle: string|null}>}
     */
    public function beginRun(string $usuario, string $archivo): MaeprodImportRun
    {
        return DB::transaction(function () use ($usuario, $archivo) {
            MaeprodImportRun::query()->delete();

            return MaeprodImportRun::query()->create([
                'usuario' => $usuario,
                'archivo' => $archivo,
                'estado' => MaeprodImportRun::ESTADO_OK,
            ]);
        });
    }

    /**
     * @param  array{created: int, updated: int, skipped: int, errors: list<array{fila: int|null, codigo: string, nombre: string, familia: string, mensaje: string, detalle: string|null}>}  $result
     */
    public function completeRun(MaeprodImportRun $run, array $result): MaeprodImportRun
    {
        $totalErrores = count($result['errors']);
        $importoAlgo = $result['created'] + $result['updated'] > 0;

        $estado = MaeprodImportRun::ESTADO_OK;
        if ($totalErrores > 0 && ! $importoAlgo) {
            $estado = MaeprodImportRun::ESTADO_FALLIDO;
        } elseif ($totalErrores > 0) {
            $estado = MaeprodImportRun::ESTADO_ERRORES;
        }

        $run->update([
            'creados' => $result['created'],
            'actualizados' => $result['updated'],
            'omitidos' => $result['skipped'],
            'total_errores' => $totalErrores,
            'estado' => $estado,
            'finished_at' => now(),
        ]);

        if ($totalErrores > 0) {
            $this->persistErrors($run, $result['errors']);
        }

        return $run->fresh();
    }

    /**
     * @param  list<array{fila: int|null, codigo: string, nombre: string, familia: string, mensaje: string, detalle: string|null}>  $errors
     */
    public function persistErrors(MaeprodImportRun $run, array $errors): void
    {
        foreach (array_chunk($errors, 500) as $chunk) {
            $rows = [];

            foreach ($chunk as $error) {
                $rows[] = [
                    'run_id' => $run->id,
                    'fila' => $error['fila'],
                    'codigo' => $error['codigo'] !== '' ? $error['codigo'] : null,
                    'nombre' => $error['nombre'] !== '' ? mb_substr($error['nombre'], 0, 255) : null,
                    'familia' => $error['familia'] !== '' ? $error['familia'] : null,
                    'mensaje' => mb_substr($error['mensaje'], 0, 255),
                    'detalle' => $error['detalle'] !== null && $error['detalle'] !== ''
                        ? mb_substr($error['detalle'], 0, 255)
                        : null,
                ];
            }

            MaeprodImportErrorLog::query()->insert($rows);
        }
    }

    public function latestRun(): ?MaeprodImportRun
    {
        return MaeprodImportRun::query()->latest('id')->first();
    }

    public function findRun(int $runId): MaeprodImportRun
    {
        return MaeprodImportRun::query()->findOrFail($runId);
    }

    public function paginateErrors(MaeprodImportRun $run, int $perPage = 50): LengthAwarePaginator
    {
        return MaeprodImportErrorLog::query()
            ->where('run_id', $run->id)
            ->orderBy('fila')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function exportErrorsCsvResponse(MaeprodImportRun $run): StreamedResponse
    {
        return response()->streamDownload(function () use ($run) {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['fila', 'codigo', 'nombre', 'familia', 'error', 'detalle'], ';');

            MaeprodImportErrorLog::query()
                ->where('run_id', $run->id)
                ->orderBy('fila')
                ->orderBy('id')
                ->chunk(500, function ($errores) use ($handle) {
                    foreach ($errores as $error) {
                        fputcsv($handle, [
                            $error->fila ?? '',
                            $error->codigo ?? '',
                            $error->nombre ?? '',
                            $error->familia ?? '',
                            $error->mensaje,
                            $error->detalle ?? '',
                        ], ';');
                    }
                });

            fclose($handle);
        }, 'errores_importacion_'.$run->id.'_'.now()->format('Y-m-d_His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function redirectUrlForRun(MaeprodImportRun $run): string
    {
        if ($run->tieneErrores()) {
            return route('admin.productos.import.errores', ['run' => $run->id]);
        }

        return route('admin.productos.import.resultado', ['run' => $run->id]);
    }
}
