<?php

namespace App\Services;

use App\Models\Nota;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class NotaAdjudicadaListadoService
{
    public function listar(User $user, array $filtros): LengthAwarePaginator
    {
        $porPagina = config('cotiz.listado_por_pagina', 20);

        return $this->baseQuery($user, $filtros)
            ->orderByDesc('notas.nronota')
            ->paginate($porPagina)
            ->withQueryString();
    }

    private function baseQuery(User $user, array $filtros): Builder
    {
        $query = Nota::query()
            ->select('notas.*')
            ->whereRaw("LOWER(COALESCE(notas.estado, '')) = 'aceptada'")
            ->with('usuarioRel');

        if ($user->username !== 'admin') {
            $query->where('notas.usuario', '<>', 'admin');
        }

        if (! empty($filtros['nronota'])) {
            $query->where('notas.nronota', (int) $filtros['nronota']);
        }

        $desde = trim((string) ($filtros['fechaentregadesde'] ?? ''));
        $hasta = trim((string) ($filtros['fechaentregahasta'] ?? ''));

        if ($desde !== '' && $hasta !== '') {
            $query->whereDate('notas.fechaentrega', '>=', $desde)
                ->whereDate('notas.fechaentrega', '<=', $hasta);
        }

        return $query;
    }

    /**
     * @return array{nronota: int, fechaentregadesde: ?string, fechaentregahasta: ?string}
     */
    public function normalizarFiltros(array $input): array
    {
        return [
            'nronota' => max(0, (int) ($input['nronota'] ?? 0)),
            'fechaentregadesde' => $this->nullableDate($input['fechaentregadesde'] ?? null),
            'fechaentregahasta' => $this->nullableDate($input['fechaentregahasta'] ?? null),
        ];
    }

    public function filtrosFechaInvalidos(array $filtros): bool
    {
        $desde = $filtros['fechaentregadesde'] ?? null;
        $hasta = $filtros['fechaentregahasta'] ?? null;

        return ($desde === null) xor ($hasta === null);
    }

    private function nullableDate(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
