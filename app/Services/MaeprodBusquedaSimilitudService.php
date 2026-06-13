<?php

namespace App\Services;

use App\Models\Maeprod;
use Illuminate\Support\Collection;

/**
 * Búsqueda de productos por similitud de texto (port de legacy maeprod_busqueda_similitud.php).
 */
class MaeprodBusquedaSimilitudService
{
    public function buscar(string $term, ?string $familia = null, int $limit = 15): Collection
    {
        $term = trim($term);
        if ($term === '') {
            return collect();
        }

        $candidatosMax = max(50, (int) config('cotiz.buscar_productos_candidatos_max', 250));
        $norm = $this->normalizarTexto($term);
        $tokens = $this->tokensConsultaSql($this->extraerTokens($norm));
        $patron = $this->patronLikeListadoPorTokens($term);

        if ($norm === '' && $tokens === []) {
            return collect();
        }

        $filas = $this->obtenerCandidatos($norm, $tokens, $patron, $familia, $candidatosMax);

        if ($filas->isEmpty()) {
            $filas = $this->obtenerCandidatosAmpliados($term, $familia, $candidatosMax);
        }

        return collect($this->rankTopSimilares($term, $filas->all(), $limit));
    }

    public function normalizarTexto(string $texto): string
    {
        $texto = mb_strtoupper($texto, 'UTF-8');
        $texto = preg_replace('/[^A-Z0-9ÁÉÍÓÚÑ\s]/u', ' ', $texto) ?? '';
        $texto = preg_replace('/\s+/u', ' ', $texto) ?? '';

        return trim($texto);
    }

    /**
     * @return string[]
     */
    public function extraerTokens(string $textoNormalizado): array
    {
        if ($textoNormalizado === '') {
            return [];
        }

        $words = preg_split('/\s+/u', $textoNormalizado, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = [];

        foreach ($words as $w) {
            if (preg_match('/^([A-ZÁÉÍÓÚÑ]{1,2})(\d)$/u', $w, $m)) {
                $tokens[] = $m[2];
            } elseif (preg_match('/^([A-ZÁÉÍÓÚÑ])(\d{2,})$/u', $w, $m)) {
                $tokens[] = $w;
            } elseif (preg_match('/^([A-ZÁÉÍÓÚÑ]{2,})\d+$/u', $w, $m)) {
                $tokens[] = $w;
            } elseif (preg_match('/^\d+$/u', $w)) {
                $tokens[] = $w;
            } elseif (preg_match('/^[A-ZÁÉÍÓÚÑ]{2,}$/u', $w)) {
                $tokens[] = $w;
            } else {
                preg_match_all('/[A-ZÁÉÍÓÚÑ]{2,}|\d+/u', $w, $sm);
                if (! empty($sm[0])) {
                    foreach ($sm[0] as $t) {
                        $tokens[] = $t;
                    }
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param  string[]  $tokens
     * @return string[]
     */
    public function tokensConsultaSql(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $t) {
            $t = trim((string) $t);
            if ($t === '') {
                continue;
            }
            if (preg_match('/^\d/u', $t)) {
                $out[] = $t;

                continue;
            }
            if (mb_strlen($t, 'UTF-8') >= 3) {
                $out[] = $t;
            }
        }

        $out = array_values(array_unique($out));

        return count($out) > 0 ? $out : $tokens;
    }

    /**
     * @return string[]
     */
    public function tokenVariantes(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            return [];
        }

        $vars = [$token];
        if (mb_strlen($token, 'UTF-8') >= 6 && ! preg_match('/\d/u', $token)) {
            $suf = mb_substr($token, 1, null, 'UTF-8');
            if (mb_strlen($suf, 'UTF-8') >= 4 && $suf !== $token) {
                $vars[] = $suf;
            }
        }

        return array_values(array_unique($vars));
    }

    public function tokenVarianteCaeEnTexto(string $token, string $textoNorm): bool
    {
        if ($textoNorm === '') {
            return false;
        }

        foreach ($this->tokenVariantes($token) as $v) {
            if (mb_strpos($textoNorm, $v, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    public function patronLikeListadoPorTokens(string $textoBusqueda): string
    {
        $norm = $this->normalizarTexto($textoBusqueda);
        $tokens = $this->tokensConsultaSql($this->extraerTokens($norm));
        if ($tokens === []) {
            return $norm;
        }

        return implode('%', $tokens);
    }

    public function pesoToken(string $palabra): float
    {
        $longitud = strlen($palabra);
        if (preg_match('/^\d{1,2}$/', $palabra)) {
            return 1.5;
        }
        if (preg_match('/^\d{3,}$/', $palabra)) {
            return 3.0;
        }
        if (preg_match('/\d/', $palabra)) {
            return $longitud >= 4 ? 2.5 : 2.0;
        }
        if ($longitud <= 3) {
            return 0.5;
        }
        if ($longitud <= 6) {
            return 1.0;
        }

        return 2.0;
    }

    public function scoreSimilitudFila(string $textoCrudo, string $prodItem, string $prodNombre): float
    {
        $textoCrudo = trim($textoCrudo);
        $textoNorm = $this->normalizarTexto($textoCrudo);
        $tokens = $this->extraerTokens($textoNorm);
        $nombreNorm = $this->normalizarTexto($prodNombre);
        $itemU = mb_strtoupper(trim($prodItem), 'UTF-8');

        if ($textoCrudo !== '' && strcasecmp($textoCrudo, trim($prodItem)) === 0) {
            return 10000000.0;
        }

        $score = 0.0;

        if ($textoNorm !== '' && $itemU !== '' && mb_strpos($itemU, $textoNorm, 0, 'UTF-8') !== false) {
            $score += 800000.0;
        }

        if ($textoNorm !== '' && $nombreNorm !== '' && mb_strpos($nombreNorm, $textoNorm, 0, 'UTF-8') !== false) {
            $score += 500000.0;
        }

        foreach ($tokens as $t) {
            $w = $this->pesoToken($t);
            if ($nombreNorm !== '' && $this->tokenVarianteCaeEnTexto($t, $nombreNorm)) {
                $score += 150.0 * $w;
            }
            if ($itemU !== '' && $this->tokenVarianteCaeEnTexto($t, $itemU)) {
                $score += 80.0 * $w;
            }
        }

        if ($textoNorm !== '' && $nombreNorm !== '') {
            $pct = 0.0;
            similar_text($textoNorm, $nombreNorm, $pct);
            $score += $pct * 650.0;
        }

        if (count($tokens) > 0 && $nombreNorm !== '') {
            $coin = 0;
            foreach ($tokens as $t) {
                if ($this->tokenVarianteCaeEnTexto($t, $nombreNorm)) {
                    $coin++;
                }
            }
            $ratio = $coin / count($tokens);
            $score += $ratio * 120000.0;
        }

        return $score;
    }

    /**
     * @param  array<int, Maeprod>  $filas
     * @return array<int, Maeprod>
     */
    public function rankTopSimilares(string $textoBusqueda, array $filas, int $limite): array
    {
        $limite = max(1, $limite);
        if ($filas === []) {
            return [];
        }

        $scored = [];
        foreach ($filas as $idx => $row) {
            $scored[] = [
                'score' => $this->scoreSimilitudFila($textoBusqueda, (string) $row->prod_item, (string) $row->prod_nombre),
                'row' => $row,
                'idx' => $idx,
            ];
        }

        usort($scored, function (array $a, array $b): int {
            if ($a['score'] == $b['score']) {
                $va = (int) ($a['row']->prod_valor ?? 0);
                $vb = (int) ($b['row']->prod_valor ?? 0);
                if ($va !== $vb) {
                    return $va <=> $vb;
                }

                return $a['idx'] <=> $b['idx'];
            }

            return $a['score'] > $b['score'] ? -1 : 1;
        });

        $out = [];
        $n = min($limite, count($scored));
        for ($i = 0; $i < $n; $i++) {
            $out[] = $scored[$i]['row'];
        }

        return $out;
    }

    /**
     * @param  string[]  $tokens
     */
    private function obtenerCandidatos(
        string $norm,
        array $tokens,
        string $patron,
        ?string $familia,
        int $candidatosMax,
    ): Collection {
        $query = $this->baseQuery($familia);

        $query->where(function ($q) use ($norm, $tokens, $patron) {
            if ($norm !== '') {
                $q->orWhere('prod_nombre', 'ilike', '%'.$norm.'%')
                    ->orWhere('prod_item', 'ilike', '%'.$norm.'%');
            }

            foreach ($tokens as $token) {
                foreach ($this->tokenVariantes($token) as $variante) {
                    if (preg_match('/^\d{1,2}$/', $token)) {
                        $q->orWhereRaw('prod_nombre ~* ?', ['\\m'.preg_quote($variante, '/').'\\M']);
                    } else {
                        $q->orWhere('prod_nombre', 'ilike', '%'.$variante.'%')
                            ->orWhere('prod_item', 'ilike', '%'.$variante.'%');
                    }
                }
            }

            if ($patron !== '') {
                $q->orWhere('prod_nombre', 'ilike', '%'.$patron.'%');
            }
        });

        return $query->limit($candidatosMax)->get();
    }

    private function obtenerCandidatosAmpliados(string $term, ?string $familia, int $candidatosMax): Collection
    {
        $words = preg_split('/\s+/u', mb_strtolower(trim($term)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return collect();
        }

        $query = $this->baseQuery($familia);
        $query->where(function ($q) use ($words) {
            foreach ($words as $word) {
                if (mb_strlen($word) < 2) {
                    continue;
                }
                $q->orWhere('prod_nombre', 'ilike', '%'.$word.'%')
                    ->orWhere('prod_item', 'ilike', '%'.$word.'%');
            }
        });

        return $query->limit($candidatosMax)->get();
    }

    private function baseQuery(?string $familia)
    {
        $query = Maeprod::query()
            ->whereNotNull('prod_nombre')
            ->where('prod_nombre', '!=', '')
            ->whereNotNull('prod_item')
            ->where('prod_item', '!=', '');

        if ($familia) {
            $query->where('prod_familia', trim($familia));
        }

        return $query;
    }
}
