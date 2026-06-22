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

        return $query->paginate($porPagina)->withQueryString();
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
            ->with('usuarioRel');

        $this->aplicarReglasPerfil($query, $user);

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
        return User::query()
            ->whereIn('perfil', [User::PERFIL_SUPERADMIN, User::PERFIL_EJECUTIVO])
            ->orderBy('nombre')
            ->orderBy('username')
            ->get();
    }
}
