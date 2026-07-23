<?php

namespace App\Services;

use App\Models\OrganismoObservacion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class OrganismoObservacionService
{
    public function __construct(
        protected CompraAgilTextoParserService $parser,
    ) {}

    /**
     * Vacía el mantenedor y lo reconstruye solo desde cotizaciones MP cerradas.
     *
     * @return array{borrados: int, creados: int}
     */
    public function resetDesdeCerradas(): array
    {
        $borrados = OrganismoObservacion::query()->count();
        OrganismoObservacion::query()->delete();

        return [
            'borrados' => $borrados,
            'creados' => $this->sincronizarDesdeCerradas(),
        ];
    }

    /**
     * Incorpora organismos desde seguimientos finalizados (cerradas).
     * Unifica RUT con/sin dígito verificador.
     */
    public function sincronizarDesdeCerradas(): int
    {
        $porCuerpo = $this->organismosDesdeCerradas();
        if ($porCuerpo === []) {
            return 0;
        }

        $ahora = now();
        $creados = 0;

        foreach ($porCuerpo as $item) {
            $rut = $item['rut'];
            $nombre = $item['nombre'];
            $cuerpo = $item['cuerpo'];

            $existente = $this->buscarPorCuerpoRut($cuerpo);
            if ($existente !== null) {
                $cambios = false;
                if ($this->rutEsMejor($rut, (string) $existente->rut_organismo)
                    && (string) $existente->rut_organismo !== $rut) {
                    $existente->rut_organismo = $rut;
                    $cambios = true;
                }
                if ($nombre !== '' && trim((string) ($existente->nombre ?? '')) === '') {
                    $existente->nombre = mb_substr($nombre, 0, 200);
                    $cambios = true;
                }
                if ($cambios) {
                    $existente->updated_at = $ahora;
                    $existente->save();
                }

                continue;
            }

            OrganismoObservacion::query()->create([
                'rut_organismo' => $rut,
                'nombre' => $nombre !== '' ? mb_substr($nombre, 0, 200) : null,
                'observacion' => null,
                'observacion_automatica' => null,
                'observacion_automatica_casos' => null,
                'observacion_automatica_en' => null,
                'updated_by' => null,
            ]);
            $creados++;
        }

        return $creados;
    }

    /**
     * Alta/actualización al cerrar una CA (fuente fidedigna).
     */
    public function registrarDesdeCerrada(?string $rut, ?string $nombre): ?OrganismoObservacion
    {
        $rutNorm = $this->parser->normalizarRut((string) $rut);
        if ($rutNorm === '') {
            return null;
        }

        $cuerpo = $this->cuerpoSinDv($rutNorm);
        if ($cuerpo === '') {
            return null;
        }

        $nombre = trim((string) $nombre);
        $existente = $this->buscarPorCuerpoRut($cuerpo);

        if ($existente === null) {
            return OrganismoObservacion::query()->create([
                'rut_organismo' => $rutNorm,
                'nombre' => $nombre !== '' ? mb_substr($nombre, 0, 200) : null,
            ]);
        }

        if ($this->rutEsMejor($rutNorm, (string) $existente->rut_organismo)) {
            $existente->rut_organismo = $rutNorm;
        }
        if ($nombre !== '') {
            $existente->nombre = mb_substr($nombre, 0, 200);
        }
        if ($existente->isDirty()) {
            $existente->save();
        }

        return $existente;
    }

    public function listar(?string $buscar = null, int $porPagina = 20): LengthAwarePaginator
    {
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

        return $this->buscarPorCuerpoRut($this->cuerpoSinDv($rutNorm));
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
     * @return list<array{rut: string, nombre: string, cuerpo: string}>
     */
    private function organismosDesdeCerradas(): array
    {
        $filas = DB::table('nota_mp_seguimientos as s')
            ->join('notas as n', 'n.nronota', '=', 's.nronota')
            ->whereRaw('s.finalizado IS TRUE')
            ->whereNotNull('n.rutempresa')
            ->whereRaw("trim(n.rutempresa) <> ''")
            ->orderByDesc('s.nronota')
            ->select([
                'n.rutempresa',
                'n.empresa',
                's.organismo',
            ])
            ->get();

        /** @var array<string, array{rut: string, nombre: string, cuerpo: string}> $porCuerpo */
        $porCuerpo = [];

        foreach ($filas as $fila) {
            $rut = $this->parser->normalizarRut((string) ($fila->rutempresa ?? ''));
            if ($rut === '') {
                continue;
            }
            $cuerpo = $this->cuerpoSinDv($rut);
            if ($cuerpo === '') {
                continue;
            }

            $nombre = trim((string) ($fila->empresa ?? ''));
            if ($nombre === '') {
                $nombre = trim((string) ($fila->organismo ?? ''));
            }

            if (! isset($porCuerpo[$cuerpo])) {
                $porCuerpo[$cuerpo] = [
                    'rut' => $rut,
                    'nombre' => $nombre,
                    'cuerpo' => $cuerpo,
                ];

                continue;
            }

            $actual = $porCuerpo[$cuerpo];
            if ($this->rutEsMejor($rut, $actual['rut'])) {
                $actual['rut'] = $rut;
            }
            if ($nombre !== '' && $actual['nombre'] === '') {
                $actual['nombre'] = $nombre;
            }
            $porCuerpo[$cuerpo] = $actual;
        }

        return array_values($porCuerpo);
    }

    private function buscarPorCuerpoRut(string $cuerpo): ?OrganismoObservacion
    {
        if ($cuerpo === '') {
            return null;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            // Cuerpo sin DV: quitar último dígito/K del RUT limpio, o el RUT completo si no tiene guión.
            return OrganismoObservacion::query()
                ->whereRaw(
                    "CASE
                        WHEN position('-' in rut_organismo) > 0 THEN
                            regexp_replace(upper(split_part(rut_organismo, '-', 1)), '[^0-9]', '', 'g')
                        ELSE
                            regexp_replace(upper(rut_organismo), '[^0-9]', '', 'g')
                     END = ?",
                    [$cuerpo],
                )
                ->orderByRaw("CASE WHEN position('-' in rut_organismo) > 0 THEN 0 ELSE 1 END")
                ->orderByDesc('id')
                ->first();
        }

        return OrganismoObservacion::query()
            ->orderByDesc('id')
            ->get()
            ->sortByDesc(fn (OrganismoObservacion $o) => str_contains((string) $o->rut_organismo, '-') ? 1 : 0)
            ->first(fn (OrganismoObservacion $o) => $this->cuerpoSinDv((string) $o->rut_organismo) === $cuerpo);
    }

    /** Prefiere RUT con dígito verificador (guión). */
    private function rutEsMejor(string $candidato, string $actual): bool
    {
        $candDv = str_contains($candidato, '-');
        $actDv = str_contains($actual, '-');
        if ($candDv && ! $actDv) {
            return true;
        }
        if (! $candDv && $actDv) {
            return false;
        }

        return strlen($candidato) > strlen($actual);
    }

    /**
     * Cuerpo del RUT sin dígito verificador (para unificar 65077010 y 65077010-2).
     */
    private function cuerpoSinDv(string $rutNormalizado): string
    {
        $rutNormalizado = $this->parser->normalizarRut($rutNormalizado);
        if ($rutNormalizado === '') {
            return '';
        }

        if (str_contains($rutNormalizado, '-')) {
            [$body] = explode('-', $rutNormalizado, 2);

            return preg_replace('/[^0-9]/', '', $body) ?? '';
        }

        $digits = preg_replace('/[^0-9]/', '', $rutNormalizado) ?? '';

        return $digits;
    }

    private function claveRut(string $rutNormalizado): string
    {
        return strtoupper(preg_replace('/[^0-9kK]/', '', $rutNormalizado) ?? '');
    }
}
