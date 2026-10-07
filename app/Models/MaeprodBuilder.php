<?php

namespace App\Models;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Builder<Maeprod>
 */
class MaeprodBuilder extends Builder
{
    /**
     * PK con espacios heredados: compara trim(columna) = trim(valor) en ambos lados.
     */
    public function whereKey($id)
    {
        return $this->whereTrimmedKey($id, not: false);
    }

    public function whereKeyNot($id)
    {
        return $this->whereTrimmedKey($id, not: true);
    }

    private function whereTrimmedKey(mixed $id, bool $not): static
    {
        if ($id instanceof Model) {
            $id = $id->getKey();
        }

        $columna = 'trim('.$this->model->getQualifiedKeyName().')';

        if (is_array($id) || $id instanceof Arrayable) {
            $ids = [];
            foreach ($id as $value) {
                $value = trim((string) $value);
                if ($value !== '') {
                    $ids[] = $value;
                }
            }

            if ($ids === []) {
                return $not ? $this : $this->whereRaw('1 = 0');
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $operador = $not ? 'not in' : 'in';

            return $this->whereRaw($columna.' '.$operador.' ('.$placeholders.')', $ids);
        }

        $operador = $not ? '<>' : '=';

        return $this->whereRaw($columna.' '.$operador.' trim(?)', [(string) $id]);
    }
}
