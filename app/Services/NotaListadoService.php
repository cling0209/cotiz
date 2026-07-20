<?php

namespace App\Services;

use App\Models\Nota;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class NotaListadoService
{
    /** Estados de seguimiento MP disponibles en filtro/columna del listado. */
    public const ESTADOS_MP_FILTRO = [
        'sin_consultar' => 'Sin consultar MP',
        'pendiente' => 'Pendiente seguimiento',
        'cerrada' => 'Cerrada',
        'ganada_propio' => 'Ganador propio',
        'desierta' => 'Desierta',
        'cancelada' => 'Cancelada',
        'no_encontrada' => 'No existe en MP',
    ];

    public function __construct(
        protected CompraAgilGanadorResolver $ganadorResolver,
    ) {}

    public function listar(User $user, array $filtros): LengthAwarePaginator
    {
        $porPagina = config('cotiz.listado_por_pagina', 20);
        $query = $this->baseQuery($user, $filtros);

        $campo = $filtros['orden_campo'] ?? 'nronota';
        $dir = strtolower($filtros['orden_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        if ($campo === 'total') {
            $query->orderBy('total_calculado', $dir)->orderByDesc('notas.nronota');
        } elseif ($campo === 'fecha') {
            $query->orderBy('notas.fecha', $dir)->orderByDesc('notas.nronota');
        } else {
            $query->orderBy('notas.nronota', $dir);
        }

        $paginado = $query->paginate($porPagina)->withQueryString();
        $this->marcarGanadorPropio($paginado->getCollection());

        return $paginado;
    }

    /**
     * @param  Collection<int, Nota>  $notas
     */
    private function marcarGanadorPropio(Collection $notas): void
    {
        $rutPropio = $this->ganadorResolver->rutEmpresaPropia();

        foreach ($notas as $nota) {
            $seg = $nota->mpSeguimiento;
            if ($seg === null) {
                continue;
            }

            $seg->es_ganador_propio = $rutPropio !== ''
                && $this->ganadorResolver->rutsCoinciden($seg->rut_ganador, $rutPropio);
        }
    }

    /** Columna y filtro de estado MP: superadmin y ejecutivos (mismo acceso que Oportunidades). */
    public function puedeVerEstadoMp(User $user): bool
    {
        return $user->canVerOportunidades();
    }

    /**
     * Cotizaciones subidas por el usuario con seguimiento pendiente,
     * estado MP publicada y convocatoria en segundo llamado (listas para postular).
     *
     * @return Collection<int, Nota>
     */
    public function cotizacionesSegundoLlamadoParaPostular(User $user): Collection
    {
        return Nota::query()
            ->select(
                'notas.nronota',
                'notas.encargado',
                'notas.empresa',
                'seg.fecha_cierre_segundo_llamado',
            )
            ->join('nota_mp_seguimientos as seg', 'seg.nronota', '=', 'notas.nronota')
            ->where('notas.usuario', $user->username)
            ->where('seg.resultado_propio', 'pendiente')
            ->where(function ($q): void {
                $q->whereRaw('lower(seg.estado_mp_glosa) like ?', ['%publicada%'])
                    ->orWhereRaw('lower(seg.estado_mp_codigo) like ?', ['%publicada%']);
            })
            ->whereRaw('lower(seg.convocatoria_descripcion) like ?', ['%segundo llamado%'])
            ->orderByDesc('notas.nronota')
            ->get();
    }

    private function baseQuery(User $user, array $filtros): Builder
    {
        $query = Nota::query()
            ->select('notas.*')
            ->selectSub(
                DB::table('notasdetalle')
                    ->selectRaw('COALESCE(SUM(prod_valor * cantidad), 0)')
                    ->whereColumn('notasdetalle.nronota', 'notas.nronota'),
                'total_calculado'
            )
            ->with(['usuarioRel', 'mpSeguimiento']);

        $this->aplicarReglasPerfil($query, $user);
        $this->aplicarFiltroEstadoMp($query, $user, $filtros);

        if (! empty($filtros['nronota'])) {
            $query->where('notas.nronota', (int) $filtros['nronota']);
        } elseif (! empty($filtros['cotizacion'])) {
            $term = trim($filtros['cotizacion']);
            $query->whereRaw('lower(trim(notas.encargado)) like lower(?)', ['%'.$term.'%']);
        } else {
            if (! empty($filtros['fechadesde'])) {
                $query->whereDate('notas.fecha', '>=', $filtros['fechadesde']);
            }
            if (! empty($filtros['fechahasta'])) {
                $query->whereDate('notas.fecha', '<=', $filtros['fechahasta']);
            }
        }

        return $query;
    }

    private function aplicarFiltroEstadoMp(Builder $query, User $user, array $filtros): void
    {
        $estado = (string) ($filtros['estado_mp'] ?? '');
        if ($estado === '' || ! $this->puedeVerEstadoMp($user)) {
            return;
        }

        if (! array_key_exists($estado, self::ESTADOS_MP_FILTRO)) {
            return;
        }

        if ($estado === 'sin_consultar') {
            $query->whereDoesntHave('mpSeguimiento');

            return;
        }

        if ($estado === 'ganada_propio') {
            $this->aplicarFiltroGanadorPropio($query);

            return;
        }

        $query->whereHas('mpSeguimiento', function (Builder $q) use ($estado): void {
            $q->where('resultado_propio', $estado);
        });
    }

    private function aplicarFiltroGanadorPropio(Builder $query): void
    {
        $rutPropio = $this->ganadorResolver->rutEmpresaPropia();
        $rutNorm = preg_replace('/[^0-9kK]/', '', $rutPropio) ?? '';
        if ($rutNorm === '') {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereHas('mpSeguimiento', function (Builder $q) use ($rutNorm): void {
            $q->where('resultado_propio', 'cerrada')
                ->whereNotNull('rut_ganador')
                ->whereRaw(
                    "replace(replace(replace(replace(lower(coalesce(rut_ganador, '')), '.', ''), '-', ''), ' ', ''), '/', '') = ?",
                    [mb_strtolower($rutNorm)]
                );
        });
    }

    private function aplicarReglasPerfil(Builder $query, User $user): void
    {
        if ($user->perfil === User::PERFIL_SUPERADMIN) {
            if ($user->username !== 'admin') {
                $query->where('notas.usuario', '<>', 'admin');
            }

            return;
        }

        if ($user->perfil === User::PERFIL_EJECUTIVO) {
            $query->where('notas.usuario', $user->username);

            return;
        }

        $query->where('notas.usuario', $user->username);
    }

    public function puedeVer(User $user, Nota $nota): bool
    {
        if ($user->perfil === User::PERFIL_SUPERADMIN) {
            if ($user->username === 'admin') {
                return true;
            }

            return strcasecmp((string) $nota->usuario, 'admin') !== 0;
        }

        return strcasecmp((string) $nota->usuario, $user->username) === 0;
    }

    public function puedeGestionar(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * @return Collection<int, User>
     */
    public function usuariosParaAsignar(): Collection
    {
        return $this->usuariosParaFiltroEjecutivo();
    }

    /**
     * Usuarios que pueden figurar como dueño de una nota (filtro combo).
     *
     * @return Collection<int, User>
     */
    public function usuariosParaFiltroEjecutivo(): Collection
    {
        return User::query()
            ->whereIn('perfil', [User::PERFIL_SUPERADMIN, User::PERFIL_EJECUTIVO])
            ->orderBy('nombre')
            ->orderBy('apellidop')
            ->orderBy('username')
            ->get();
    }
}
