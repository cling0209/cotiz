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
                $nombreActual = trim((string) ($existente->nombre ?? ''));
                if ($nombre !== '' && ($nombreActual === '' || $this->nombreEsMejor($nombre, $nombreActual))) {
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
     *
     * @param  string|null  $nombreMp  organismo_comprador de la API MP (preferido)
     * @param  string|null  $nombreNota  notas.empresa (fallback si no parece código CA)
     * @param  string|null  $encargado  código CA para descartar homónimos en empresa
     */
    public function registrarDesdeCerrada(
        ?string $rut,
        ?string $nombre = null,
        ?string $nombreMp = null,
        ?string $nombreNota = null,
        ?string $encargado = null,
    ): ?OrganismoObservacion {
        $rutNorm = $this->parser->completarRutConDv((string) $rut);
        if ($rutNorm === '') {
            return null;
        }

        $cuerpo = $this->cuerpoSinDv($rutNorm);
        if ($cuerpo === '') {
            return null;
        }

        // Compat: llamadas antiguas pasan solo $nombre; las nuevas usan MP + nota.
        if ($nombreMp !== null || $nombreNota !== null) {
            $nombre = $this->nombreOrganismoFidedigno(
                (string) $nombreMp,
                (string) ($nombreNota ?? $nombre),
                (string) $encargado,
            );
        } else {
            $nombre = trim((string) $nombre);
            if ($this->nombrePareceCodigoCa($nombre)) {
                $nombre = '';
            }
        }

        if ($nombre === '') {
            return null;
        }

        $existente = $this->buscarPorCuerpoRut($cuerpo);

        if ($existente === null) {
            return OrganismoObservacion::query()->create([
                'rut_organismo' => $rutNorm,
                'nombre' => mb_substr($nombre, 0, 200),
            ]);
        }

        if ($this->rutEsMejor($rutNorm, (string) $existente->rut_organismo)) {
            $existente->rut_organismo = $rutNorm;
        }
        if ($this->nombreEsMejor($nombre, trim((string) ($existente->nombre ?? '')))) {
            $existente->nombre = mb_substr($nombre, 0, 200);
        } elseif (trim((string) ($existente->nombre ?? '')) === '') {
            $existente->nombre = mb_substr($nombre, 0, 200);
        }
        if ($existente->isDirty()) {
            $existente->save();
        }

        return $existente;
    }

    public function listar(?string $buscar = null, int $porPagina = 20): LengthAwarePaginator
    {
        $this->fusionarDuplicadosPorCuerpoRut();

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

    /**
     * Une filas ya guardadas con el mismo cuerpo de RUT (ej. 65077010 y 65077010-2).
     */
    public function fusionarDuplicadosPorCuerpoRut(): int
    {
        $grupos = [];
        foreach (OrganismoObservacion::query()->orderBy('id')->get() as $org) {
            $cuerpo = $this->cuerpoSinDv((string) $org->rut_organismo);
            if ($cuerpo === '') {
                continue;
            }
            $grupos[$cuerpo][] = $org;
        }

        $eliminados = 0;
        foreach ($grupos as $filas) {
            if (count($filas) < 2) {
                continue;
            }

            usort($filas, function (OrganismoObservacion $a, OrganismoObservacion $b) {
                if ($this->rutEsMejor((string) $a->rut_organismo, (string) $b->rut_organismo)) {
                    return -1;
                }
                if ($this->rutEsMejor((string) $b->rut_organismo, (string) $a->rut_organismo)) {
                    return 1;
                }

                return $a->id <=> $b->id;
            });

            /** @var OrganismoObservacion $keeper */
            $keeper = $filas[0];
            for ($i = 1; $i < count($filas); $i++) {
                $dup = $filas[$i];
                if ($this->rutEsMejor((string) $dup->rut_organismo, (string) $keeper->rut_organismo)) {
                    $keeper->rut_organismo = $dup->rut_organismo;
                }
                if (trim((string) ($keeper->nombre ?? '')) === '' && trim((string) ($dup->nombre ?? '')) !== '') {
                    $keeper->nombre = $dup->nombre;
                }
                if (! $keeper->tieneObservacion() && $dup->tieneObservacion()) {
                    $keeper->observacion = $dup->observacion;
                    $keeper->updated_by = $dup->updated_by;
                }
                if (! $keeper->tieneObservacionAutomatica() && $dup->tieneObservacionAutomatica()) {
                    $keeper->observacion_automatica = $dup->observacion_automatica;
                    $keeper->observacion_automatica_casos = $dup->observacion_automatica_casos;
                    $keeper->observacion_automatica_en = $dup->observacion_automatica_en;
                } elseif (
                    $dup->tieneObservacionAutomatica()
                    && (int) ($dup->observacion_automatica_casos ?? 0) > (int) ($keeper->observacion_automatica_casos ?? 0)
                ) {
                    $keeper->observacion_automatica = $dup->observacion_automatica;
                    $keeper->observacion_automatica_casos = $dup->observacion_automatica_casos;
                    $keeper->observacion_automatica_en = $dup->observacion_automatica_en;
                }
                $dup->delete();
                $eliminados++;
            }
            if ($keeper->isDirty()) {
                $keeper->save();
            }
        }

        return $eliminados;
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
        $rutNorm = $this->parser->completarRutConDv((string) $rut);
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
        // Solo resultados MP reales (no "no_encontrada" u otros finalizados sin institución).
        $filas = DB::table('nota_mp_seguimientos as s')
            ->join('notas as n', 'n.nronota', '=', 's.nronota')
            ->whereRaw('s.finalizado IS TRUE')
            ->whereIn('s.resultado_propio', ['cerrada', 'desierta', 'cancelada'])
            ->whereNotNull('n.rutempresa')
            ->whereRaw("trim(n.rutempresa) <> ''")
            ->orderByDesc('s.nronota')
            ->select([
                'n.rutempresa',
                'n.empresa',
                'n.encargado',
                's.organismo',
                's.codigo_proceso',
            ])
            ->get();

        $codigos = [];
        foreach ($filas as $fila) {
            $codigo = strtoupper(trim((string) (
                filled(trim((string) ($fila->codigo_proceso ?? '')))
                    ? $fila->codigo_proceso
                    : ($fila->encargado ?? '')
            )));
            if ($codigo !== '') {
                $codigos[$codigo] = true;
            }
        }

        /** @var array<string, object{organismo: ?string, rut_organismo: ?string}> $cachePorCodigo */
        $cachePorCodigo = [];
        if ($codigos !== []) {
            foreach (DB::table('compra_agil_procesos')
                ->whereIn('codigo', array_keys($codigos))
                ->get(['codigo', 'organismo', 'rut_organismo']) as $proc) {
                $cachePorCodigo[strtoupper(trim((string) $proc->codigo))] = $proc;
            }
        }

        /** @var array<string, array{rut: string, nombre: string, cuerpo: string}> $porCuerpo */
        $porCuerpo = [];

        foreach ($filas as $fila) {
            $codigo = strtoupper(trim((string) (
                filled(trim((string) ($fila->codigo_proceso ?? '')))
                    ? $fila->codigo_proceso
                    : ($fila->encargado ?? '')
            )));
            $cache = $cachePorCodigo[$codigo] ?? null;

            $rutNota = $this->parser->completarRutConDv((string) ($fila->rutempresa ?? ''));
            $rutCache = $this->parser->completarRutConDv((string) ($cache->rut_organismo ?? ''));
            $rut = $rutCache !== '' && ($rutNota === '' || $this->rutEsMejor($rutCache, $rutNota))
                ? $rutCache
                : $rutNota;
            if ($rut === '') {
                continue;
            }
            $rut = $this->parser->completarRutConDv($rut);
            $cuerpo = $this->cuerpoSinDv($rut);
            if ($cuerpo === '') {
                continue;
            }

            // Preferir organismo MP (seguimiento → cache CA); notas.empresa a veces trae el código CA.
            $nombre = $this->nombreOrganismoFidedigno(
                (string) ($fila->organismo ?? ''),
                (string) ($fila->empresa ?? ''),
                (string) ($fila->encargado ?? $fila->codigo_proceso ?? ''),
                (string) ($cache->organismo ?? ''),
            );
            if ($nombre === '') {
                continue;
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
            if ($this->nombreEsMejor($nombre, $actual['nombre'])) {
                $actual['nombre'] = $nombre;
            }
            $porCuerpo[$cuerpo] = $actual;
        }

        return array_values($porCuerpo);
    }

    /**
     * Nombre usable para el mantenedor: MP primero; descarta códigos CA (ej. 4201-366-COT26).
     */
    public function nombreOrganismoFidedigno(
        string $organismoMp,
        string $empresaNota,
        string $encargado = '',
        string $organismoCache = '',
    ): string {
        $candidatos = [
            trim($organismoMp),
            trim($organismoCache),
            trim($empresaNota),
        ];

        $encargadoNorm = strtoupper(trim($encargado));

        foreach ($candidatos as $candidato) {
            if ($candidato === '') {
                continue;
            }
            if ($encargadoNorm !== '' && strtoupper($candidato) === $encargadoNorm) {
                continue;
            }
            if ($this->nombrePareceCodigoCa($candidato)) {
                continue;
            }

            return mb_substr($candidato, 0, 200);
        }

        return '';
    }

    /** Códigos Compra Ágil / encargado colados como "empresa". */
    public function nombrePareceCodigoCa(string $nombre): bool
    {
        $n = strtoupper(trim($nombre));
        if ($n === '') {
            return false;
        }

        if (preg_match('/^\d{1,6}-\d{1,6}-COT\d*$/i', $n) === 1) {
            return true;
        }

        if (preg_match('/^[A-Z0-9]{2,12}-\d{1,6}-COT/i', $n) === 1) {
            return true;
        }

        return false;
    }

    private function nombreEsMejor(string $candidato, string $actual): bool
    {
        if ($candidato === '') {
            return false;
        }
        if ($actual === '') {
            return true;
        }
        if ($this->nombrePareceCodigoCa($actual) && ! $this->nombrePareceCodigoCa($candidato)) {
            return true;
        }

        return false;
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
