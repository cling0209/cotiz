<?php

namespace App\Services;

use App\Models\CompraAgilProceso;
use App\Models\Nota;
use App\Models\OportunidadEncontrada;
use App\Models\OrganismoObservacion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class OrganismoObservacionService
{
    public function __construct(
        protected CompraAgilTextoParserService $parser,
    ) {}

    /**
     * Incorpora organismos ya vistos (oportunidades, cache CA, notas) sin pisar observaciones.
     */
    public function sincronizarDesdeFuentes(): int
    {
        $porRut = $this->organismosDesdeFuentes();
        if ($porRut === []) {
            return 0;
        }

        $ruts = [];
        foreach (array_keys($porRut) as $key) {
            $ruts[] = $this->rutDesdeClaveMapa((string) $key);
        }
        $existentes = [];
        foreach (array_chunk($ruts, 500) as $chunk) {
            $filas = OrganismoObservacion::query()
                ->whereIn('rut_organismo', $chunk)
                ->pluck('id', 'rut_organismo');
            foreach ($filas as $rutExistente => $id) {
                $existentes[(string) $rutExistente] = true;
            }
        }

        $ahora = now();
        $inserts = [];
        $nombresActualizar = [];

        foreach ($porRut as $key => $nombre) {
            $rut = $this->rutDesdeClaveMapa((string) $key);
            if (isset($existentes[$rut])) {
                if ($nombre !== '') {
                    $nombresActualizar[$rut] = $nombre;
                }

                continue;
            }

            $inserts[] = [
                'rut_organismo' => $rut,
                'nombre' => $nombre !== '' ? mb_substr($nombre, 0, 200) : null,
                'observacion' => null,
                'observacion_automatica' => null,
                'observacion_automatica_casos' => null,
                'observacion_automatica_en' => null,
                'updated_by' => null,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
            $existentes[$rut] = true;
        }

        $creados = 0;
        if ($inserts !== []) {
            foreach (array_chunk($inserts, 200) as $chunk) {
                OrganismoObservacion::query()->insert($chunk);
                $creados += count($chunk);
            }
        }

        foreach ($nombresActualizar as $rut => $nombre) {
            OrganismoObservacion::query()
                ->where('rut_organismo', (string) $rut)
                ->where(function ($q) {
                    $q->whereNull('nombre')->orWhere('nombre', '');
                })
                ->update([
                    'nombre' => mb_substr($nombre, 0, 200),
                    'updated_at' => $ahora,
                ]);
        }

        return $creados;
    }

    public function listar(?string $buscar = null, int $porPagina = 20): LengthAwarePaginator
    {
        $this->sincronizarDesdeFuentes();

        $term = trim((string) $buscar);

        return OrganismoObservacion::query()
            ->with('editor')
            ->when($term !== '', function ($q) use ($term) {
                $like = '%'.$term.'%';

                return $q->where(function ($inner) use ($like) {
                    $inner->where('rut_organismo', 'ilike', $like)
                        ->orWhere('nombre', 'ilike', $like)
                        ->orWhere('observacion', 'ilike', $like)
                        ->orWhere('observacion_automatica', 'ilike', $like);
                });
            })
            ->orderByRaw("CASE WHEN coalesce(trim(observacion), '') = '' THEN 1 ELSE 0 END")
            ->orderByRaw("CASE WHEN coalesce(trim(observacion_automatica), '') = '' THEN 1 ELSE 0 END")
            ->orderBy('nombre')
            ->orderBy('rut_organismo')
            ->paginate($porPagina)
            ->withQueryString();
    }

    public function actualizar(
        OrganismoObservacion $organismo,
        string $nombre,
        string $observacion,
        ?int $userId,
    ): OrganismoObservacion {
        $organismo->nombre = mb_substr(trim($nombre), 0, 200) ?: $organismo->nombre;
        $organismo->observacion = trim($observacion) !== '' ? trim($observacion) : null;
        $organismo->updated_by = $userId;
        $organismo->save();

        return $organismo;
    }

    public function buscarPorRut(?string $rut): ?OrganismoObservacion
    {
        $rutNorm = $this->parser->normalizarRut((string) $rut);
        if ($rutNorm === '') {
            return null;
        }

        $key = $this->claveRut($rutNorm);

        return OrganismoObservacion::query()
            ->where(function ($q) use ($rutNorm, $key) {
                $q->where('rut_organismo', $rutNorm)
                    ->orWhereRaw(
                        "regexp_replace(upper(rut_organismo), '[^0-9K]', '', 'g') = ?",
                        [$key],
                    );
            })
            ->first();
    }

    /**
     * Observación de administrador visible al ejecutivo (texto no vacío).
     */
    public function observacionAdminParaRut(?string $rut): ?string
    {
        $row = $this->buscarPorRut($rut);
        if ($row === null || ! $row->tieneObservacion()) {
            return null;
        }

        return trim((string) $row->observacion);
    }

    /**
     * Perfil automático visible al ejecutivo (texto no vacío).
     */
    public function observacionAutomaticaParaRut(?string $rut): ?string
    {
        $row = $this->buscarPorRut($rut);
        if ($row === null || ! $row->tieneObservacionAutomatica()) {
            return null;
        }

        return trim((string) $row->observacion_automatica);
    }

    /**
     * @return array{admin: ?string, automatica: ?string}
     */
    public function observacionesParaRut(?string $rut): array
    {
        $row = $this->buscarPorRut($rut);

        return [
            'admin' => ($row !== null && $row->tieneObservacion())
                ? trim((string) $row->observacion)
                : null,
            'automatica' => ($row !== null && $row->tieneObservacionAutomatica())
                ? trim((string) $row->observacion_automatica)
                : null,
        ];
    }

    /**
     * @return array<string, string> rut normalizado => nombre
     */
    private function organismosDesdeFuentes(): array
    {
        /** @var array<string, string> $map */
        $map = [];

        $this->agregarFuente(
            $map,
            OportunidadEncontrada::query()
                ->select(['rut_organismo', 'organismo'])
                ->whereNotNull('rut_organismo')
                ->whereRaw("trim(rut_organismo) <> ''")
                ->orderByDesc('id')
                ->get()
                ->map(fn ($r) => [(string) $r->rut_organismo, (string) ($r->organismo ?? '')]),
        );

        $this->agregarFuente(
            $map,
            CompraAgilProceso::query()
                ->select(['rut_organismo', 'organismo'])
                ->whereNotNull('rut_organismo')
                ->whereRaw("trim(rut_organismo) <> ''")
                ->orderByDesc('sincronizado_en')
                ->get()
                ->map(fn ($r) => [(string) $r->rut_organismo, (string) ($r->organismo ?? '')]),
        );

        $this->agregarFuente(
            $map,
            Nota::query()
                ->select(['rutempresa', 'empresa'])
                ->whereNotNull('rutempresa')
                ->whereRaw("trim(rutempresa) <> ''")
                ->orderByDesc('nronota')
                ->limit(5000)
                ->get()
                ->map(fn ($r) => [(string) $r->rutempresa, (string) ($r->empresa ?? '')]),
        );

        return $map;
    }

    /**
     * @param  array<string, string>  $map
     * @param  Collection<int, array{0: string, 1: string}>  $filas
     */
    private function agregarFuente(array &$map, Collection $filas): void
    {
        foreach ($filas as $fila) {
            $rut = $this->parser->normalizarRut($fila[0]);
            if ($rut === '') {
                continue;
            }

            $nombre = trim($fila[1]);
            // Prefijo evita que PHP casteé RUT solo-dígitos a int (rompe whereIn en PG).
            $key = $this->claveMapaRut($rut);
            if (! array_key_exists($key, $map)) {
                $map[$key] = $nombre;

                continue;
            }

            if ($nombre !== '' && trim($map[$key]) === '') {
                $map[$key] = $nombre;
            }
        }
    }

    /** Clave interna estable; el RUT real se recupera con rutDesdeClaveMapa(). */
    private function claveMapaRut(string $rut): string
    {
        return 'rut:'.$rut;
    }

    private function rutDesdeClaveMapa(string $key): string
    {
        return str_starts_with($key, 'rut:') ? substr($key, 4) : $key;
    }

    private function claveRut(string $rutNormalizado): string
    {
        return strtoupper(preg_replace('/[^0-9kK]/', '', $rutNormalizado) ?? '');
    }
}
