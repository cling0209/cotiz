<?php

namespace App\Services;

use App\Models\Maeprod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Búsqueda de productos por similitud (port legacy: maeprod_busqueda_similitud + sp_maeprod buscarSimilitud).
 */
class MaeprodBusquedaSimilitudService
{
    /** @var string[] */
    private const STOPWORDS = [
        'PARA', 'CON', 'SIN', 'DEL', 'DE', 'LA', 'LAS', 'LOS', 'EL', 'EN',
        'UN', 'UNA', 'Y', 'AL', 'POR', 'QUE', 'THE', 'SET',
    ];

    /**
     * Tokens muy frecuentes en listados que no identifican el producto
     * (PACK/COLORES/SURTIDOS/CM…) y generan falsos positivos al vincular.
     *
     * @var string[]
     */
    private const TOKENS_GENERICOS = [
        'PACK', 'PAQUETE', 'PAQUETES', 'PACKS',
        'SURTIDO', 'SURTIDOS', 'SURTIDA', 'SURTIDAS',
        'COLOR', 'COLORES', 'COLOREADO', 'COLOREADOS',
        'AZUL', 'ROJO', 'ROJA', 'VERDE', 'NEGRO', 'NEGRA', 'BLANCO', 'BLANCA',
        'AMARILLO', 'AMARILLA', 'NARANJA', 'ROSADO', 'ROSADA', 'MORADO', 'MORADA',
        'GRIS', 'CAFE', 'BEIGE', 'CELESTE', 'FUCSIA', 'TRANSPARENTE',
        'UNIDAD', 'UNIDADES', 'UND', 'UDS',
        'CM', 'MM', 'MT', 'MTS', 'METRO', 'METROS', 'ML', 'CC', 'FR',
        'KG', 'KILO', 'KILOS', 'GR', 'GRAMOS', 'GMS',
        'APROX', 'APROXIMADO', 'IDEAL', 'IDEALMENTE',
        'MINIMO', 'MAXIMO', 'GARANTIA', 'MESES',
        'TIPO', 'MODELO', 'MEDIDA', 'MEDIDAS', 'TAMANO', 'TAMAÑO',
        'PIEZA', 'PIEZAS', 'HOJA', 'HOJAS', 'PLIEGO', 'PLIEGOS',
        'CAJA', 'CAJAS', 'BOLSA', 'BOLSAS',
        'PRODUCTO', 'PRODUCTOS', 'ITEM', 'ITEMS', 'ARTICULO', 'ARTICULOS',
        'REQUERIMIENTO', 'DETALLE', 'REFERENCIA', 'IMAGEN',
        // Atributos/empaque que no identifican el producto (JERINGA≠MARCADOR por DESECHABLE/PUNTA)
        'DESECHABLE', 'DESECHABLES', 'PUNTA', 'PUNTAS', 'ROMA', 'REDONDA', 'REDONDO',
        'FINA', 'FINO', 'GRUESA', 'GRUESO', 'METAL', 'PLASTICO', 'PLASTICA',
        'REF', 'COD', 'CODIGO', 'INT', 'PG', 'PC',
        'SIMILAR', 'SUPERIOR', 'INFERIOR', 'CUMPLIR', 'ESPECIFICACION', 'EXCLUYENTE',
        'ORIGINAL', 'GENERICO', 'JUMBO',
    ];

    /**
     * Familias de tipo de producto para detectar vínculos sin relación
     * (ej. CLIP↔PORTAMINAS, CARTULINA↔DESTACADOR). Se usa como guarda: si
     * ambos textos tienen familia detectada y son disjuntas, no se vincula.
     *
     * Cada keyword se compara por INICIO de palabra sobre el texto normalizado
     * (mayúsculas), por lo que cubre singular/plural (CLIP→CLIPS, PORTAMINA→PORTAMINAS).
     *
     * @var array<string, string[]>
     */
    private const FAMILIAS_PRODUCTO = [
        'PORTAMINAS' => ['PORTAMINA'],
        'ACCOCLIP' => ['ACCOCLIP'],
        'CLIP' => ['CLIP', 'BINDER', 'APRETADOR'],
        'CALCULADORA' => ['CALCULADORA'],
        'RESMA' => ['RESMA'],
        'CARTULINA' => ['CARTULINA', 'CARTULUNA'],
        'CINTA' => ['CINTA', 'MASKING'],
        'CARPETA' => ['CARPETA', 'ARCHIVADOR', 'REVISTERO'],
        'GOMA_EVA' => ['FOAMI'],
        'CHINCHE' => ['CHINCHE'],
        'LLAVERO' => ['LLAVERO'],
        'CANAMO' => ['CANAMO', 'PITILLA'],
        'DESTACADOR' => ['DESTACADOR', 'TEXMARKET'],
        'PLUMON' => ['PLUMON'],
        'REGLA' => ['REGLA'],
        'BROCHETA' => ['BROCHETA'],
        'CREPE' => ['CREPE'],
        'TERMOLAMINADO' => ['TERMOLAMIN'],
        'ELASTICO' => ['ELASTICO'],
        'CORCHETE' => ['CORCHETE', 'CORCHETERA', 'ENGRAP'],
        'TIJERA' => ['TIJERA'],
        'TONER' => ['TONER', 'CARTUCHO'],
        'SACAPUNTA' => ['SACAPUNTA', 'TAJADOR'],
        'CUADERNO' => ['CUADERNO'],
        'TIMBRE' => ['FECHADOR', 'FECHERO'],
        'ESPIRAL' => ['ESPIRAL'],
        'COMPAS' => ['COMPAS'],
        'JERINGA' => ['JERINGA'],
        'PILA' => ['PILA', 'BATERIA'],
        'TATUAJE' => ['TATUAJE'],
    ];

    public function buscar(string $term, ?string $familia = null, int $limit = 15): Collection
    {
        $term = trim($term);
        if ($term === '') {
            return collect();
        }

        $limit = max(1, $limit);
        $payload = $this->codificarPayloadBuscarSimilitud($term);
        if ($payload === '') {
            return collect();
        }

        $filas = $this->buscarSimilitudEnSql($payload, $familia, $limit);
        if ($filas->isNotEmpty()) {
            return $filas;
        }

        $candidatosMax = max(50, (int) config('cotiz.buscar_productos_candidatos_max', 250));
        $tokens = $this->tokensSignificativos($this->normalizarTexto($term));
        $patron = implode('%', $tokens);

        if ($tokens === []) {
            return collect();
        }

        $candidatos = $this->obtenerCandidatosPorPatron($patron, $tokens, $familia, $candidatosMax);
        $ranked = $this->rankTopSimilares($term, $candidatos->all(), $limit);

        return collect($this->filtrarPorPuntajeMinimo($ranked, $term));
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
            if ($t === '' || $this->esStopword($t)) {
                continue;
            }
            if (preg_match('/^\d{1,2}$/', $t)) {
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

        return count($out) > 0 ? $out : array_values(array_filter($tokens, fn (string $t) => ! $this->esStopword($t)));
    }

    /**
     * @return string[]
     */
    public function tokensSignificativos(string $textoNormalizado): array
    {
        return $this->tokensConsultaSql($this->extraerTokens($textoNormalizado));
    }

    /**
     * Tokens que identifican el producto (excluye stopwords y genéricos PACK/COLORES/…).
     *
     * @return string[]
     */
    public function tokensDistintivos(string $textoNormalizado): array
    {
        $out = [];
        foreach ($this->tokensSignificativos($textoNormalizado) as $token) {
            if ($this->esTokenGenerico($token)) {
                continue;
            }
            // Números cortos (10, 12, 4…) son ruido en listados de empaque.
            if (preg_match('/^\d{1,2}$/', $token)) {
                continue;
            }
            $out[] = $token;
        }

        return array_values(array_unique($out));
    }

    /**
     * Exige solape de tokens distintivos para evitar vínculos tipo LENCI→EVA o CARTÓN→TINTA.
     */
    /**
     * Familias de producto detectadas en el texto (por inicio de palabra).
     *
     * @return string[]
     */
    public function familiasProducto(string $texto): array
    {
        $norm = $this->normalizarTexto($texto);
        if ($norm === '') {
            return [];
        }

        $hay = ' '.$norm.' ';
        $familias = [];
        foreach (self::FAMILIAS_PRODUCTO as $familia => $keywords) {
            foreach ($keywords as $kw) {
                // Coincidencia por inicio de palabra: cubre plural/sufijo y evita
                // matches en medio de otra palabra (ej. CLIP dentro de ACCOCLIP).
                if (str_contains($hay, ' '.$kw)) {
                    $familias[] = $familia;
                    break;
                }
            }
        }

        return array_values(array_unique($familias));
    }

    /**
     * Verdadero solo cuando AMBOS textos tienen familia de producto y son
     * disjuntas (sin familia en común). Nunca marca conflicto si algún lado
     * no tiene familia detectable (evita falsos negativos).
     */
    public function hayConflictoFamilia(string $textoA, string $textoB): bool
    {
        $fa = $this->familiasProducto($textoA);
        $fb = $this->familiasProducto($textoB);
        if ($fa === [] || $fb === []) {
            return false;
        }

        return array_intersect($fa, $fb) === [];
    }

    public function tieneSolapeDistintivo(string $textoConsulta, string $textoCandidato): bool
    {
        // Guarda por familia: descarta vínculos sin relación (CLIP↔PORTAMINAS,
        // CARTULINA↔DESTACADOR) aunque compartan tokens genéricos residuales.
        if ($this->hayConflictoFamilia($textoConsulta, $textoCandidato)) {
            return false;
        }

        $distConsulta = $this->tokensDistintivos($this->normalizarTexto($textoConsulta));
        if ($distConsulta === []) {
            return false;
        }

        $candidatoNorm = $this->normalizarTexto($textoCandidato);
        if ($candidatoNorm === '') {
            return false;
        }

        $hits = 0;
        $hitsNucleo = 0;
        foreach ($distConsulta as $token) {
            if (! $this->tokenVarianteCaeEnTexto($token, $candidatoNorm)) {
                continue;
            }
            $hits++;
            // Exige que al menos un token “núcleo” (≥5 letras) coincida: evita JERINGA→MARCADOR
            // solo por atributos cortos/genéricos residuales.
            if (mb_strlen($token, 'UTF-8') >= 5 && ! preg_match('/^\d+$/', $token)) {
                $hitsNucleo++;
            }
        }

        $requeridos = count($distConsulta) >= 3 ? 2 : 1;
        if ($hits < $requeridos) {
            return false;
        }

        $nucleosConsulta = array_values(array_filter(
            $distConsulta,
            static fn (string $t): bool => mb_strlen($t, 'UTF-8') >= 5 && ! preg_match('/^\d+$/', $t),
        ));

        if ($nucleosConsulta !== [] && $hitsNucleo === 0) {
            return false;
        }

        return true;
    }

    public function esTokenGenerico(string $token): bool
    {
        $t = mb_strtoupper(trim($token), 'UTF-8');
        if ($t === '' || in_array($t, self::TOKENS_GENERICOS, true)) {
            return true;
        }

        // Singular/plural simple ya cubierto en la lista; variantes con S.
        if (str_ends_with($t, 'S') && mb_strlen($t, 'UTF-8') >= 5) {
            $singular = mb_substr($t, 0, -1, 'UTF-8');
            if (in_array($singular, self::TOKENS_GENERICOS, true)) {
                return true;
            }
        }

        return false;
    }

    public function codificarPayloadBuscarSimilitud(string $textoBusqueda, int $maxLen = 255): string
    {
        $norm = $this->normalizarTexto($textoBusqueda);
        if ($norm === '') {
            return '';
        }

        $frase = $norm;
        if (mb_strlen($frase, 'UTF-8') > 140) {
            $frase = mb_substr($frase, 0, 140, 'UTF-8');
        }

        $tokens = $this->tokensConsultaSql($this->extraerTokens($norm));
        $parts = [];
        foreach ($tokens as $t) {
            $t = trim((string) $t);
            if ($t === '') {
                continue;
            }
            $try = $frase.'||'.implode('|', array_merge($parts, [$t]));
            if (strlen($try) > $maxLen) {
                break;
            }
            $parts[] = $t;
        }

        return $frase.'||'.implode('|', $parts);
    }

    /**
     * @return array{0: string, 1: string[]}
     */
    public function parsearPayloadSimilitud(string $payload): array
    {
        $payload = trim($payload);
        if ($payload === '') {
            return ['', []];
        }

        if (str_contains($payload, '||')) {
            [$frase, $resto] = explode('||', $payload, 2);

            return [trim($frase), array_values(array_filter(explode('|', trim($resto))))];
        }

        return [$payload, []];
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

        if (mb_strlen($token, 'UTF-8') >= 5 && str_ends_with($token, 'S') && ! preg_match('/\d/u', $token)) {
            $singular = mb_substr($token, 0, -1, 'UTF-8');
            if (mb_strlen($singular, 'UTF-8') >= 4) {
                $vars[] = $singular;
            }
        }

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

    public function pesoToken(string $palabra): float
    {
        if ($this->esTokenGenerico($palabra)) {
            return 0.15;
        }

        $longitud = strlen($palabra);
        if (preg_match('/^\d{1,2}$/', $palabra)) {
            return 0.4;
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
        $tokens = $this->tokensSignificativos($textoNorm);
        $distintivos = $this->tokensDistintivos($textoNorm);
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

        // Ratio solo con tokens distintivos: evita PACK/COLORES/SURTIDOS como “match”.
        $tokensRatio = $distintivos !== [] ? $distintivos : $tokens;
        if (count($tokensRatio) > 0 && $nombreNorm !== '') {
            $coin = 0;
            foreach ($tokensRatio as $t) {
                if ($this->tokenVarianteCaeEnTexto($t, $nombreNorm)) {
                    $coin++;
                }
            }
            $ratio = $coin / count($tokensRatio);
            $score += $ratio * 120000.0;

            if ($distintivos !== [] && $coin === 0) {
                // Sin solape real del producto: no auto-vincular aunque haya ruido genérico.
                return min($score * 0.05, 800.0);
            }
        }

        return $score;
    }

    public function puntajeSqlMinimo(): int
    {
        return max(1, (int) config('cotiz.buscar_productos_puntaje_minimo', 55));
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

    private function buscarSimilitudEnSql(string $payload, ?string $familia, int $limit): Collection
    {
        [$phrase, $tokens] = $this->parsearPayloadSimilitud($payload);
        if ($phrase === '' && $tokens === []) {
            return collect();
        }

        $built = $this->construirExpresionPuntajeSql($phrase, $tokens);
        if ($built === null) {
            return collect();
        }

        [$scoreSql, $whereSql, $scoreBindings, $whereBindings] = $built;
        $minPuntaje = $this->puntajeSqlMinimo();

        $sql = 'SELECT prod_item, prod_nombre, prod_valor, prod_valor_costo, prod_stock_real, prod_familia, prod_imagen, '
            .'('.$scoreSql.') AS puntaje '
            .'FROM maeprod '
            .'WHERE prod_nombre IS NOT NULL AND prod_nombre <> \'\' '
            .'AND prod_item IS NOT NULL AND prod_item <> \'\' ';

        $bindings = $scoreBindings;

        if ($familia) {
            $sql .= 'AND prod_familia = ? ';
            $bindings[] = trim($familia);
        }

        $sql .= 'AND ('.$whereSql.') '
            .'AND ('.$scoreSql.') >= ? '
            .'ORDER BY puntaje DESC, prod_nombre ASC '
            .'LIMIT ?';

        $bindings = array_merge($bindings, $whereBindings, $scoreBindings);
        $bindings[] = $minPuntaje;
        $bindings[] = $limit;

        $rows = DB::select($sql, $bindings);

        return collect($rows)->map(function ($row) {
            return Maeprod::query()->find($row->prod_item) ?? new Maeprod([
                'prod_item' => $row->prod_item,
                'prod_nombre' => $row->prod_nombre,
                'prod_valor' => $row->prod_valor,
                'prod_valor_costo' => $row->prod_valor_costo,
                'prod_stock_real' => $row->prod_stock_real,
                'prod_familia' => $row->prod_familia,
                'prod_imagen' => $row->prod_imagen,
            ]);
        })->filter();
    }

    /**
     * @param  string[]  $tokens
     * @return array{0: string, 1: string, 2: array<int, mixed>, 3: array<int, mixed>}|null
     */
    private function construirExpresionPuntajeSql(string $phrase, array $tokens): ?array
    {
        $like = $this->likeOperator();
        $scoreParts = [];
        $whereParts = [];
        $scoreBindings = [];
        $whereBindings = [];

        if ($phrase !== '') {
            $scoreParts[] = '(CASE WHEN prod_nombre '.$like.' ? OR prod_item '.$like.' ? THEN 200 ELSE 0 END)';
            $scoreBindings[] = '%'.$phrase.'%';
            $scoreBindings[] = '%'.$phrase.'%';
            $whereParts[] = '(prod_nombre '.$like.' ? OR prod_item '.$like.' ?)';
            $whereBindings[] = '%'.$phrase.'%';
            $whereBindings[] = '%'.$phrase.'%';
        }

        $prevTok = '';
        foreach ($tokens as $tok) {
            $tok = trim($tok);
            if ($tok === '') {
                continue;
            }

            if ($prevTok !== '' && ! preg_match('/^\d+$/', $prevTok) && ! preg_match('/^\d+$/', $tok)) {
                $bigram = $prevTok.' '.$tok;
                $ordered = '%'.$prevTok.'%'.$tok.'%';
                $scoreParts[] = '(CASE WHEN prod_nombre '.$like.' ? OR prod_item '.$like.' ? THEN 75 '
                    .'WHEN prod_nombre '.$like.' ? OR prod_item '.$like.' ? THEN 55 ELSE 0 END)';
                $scoreBindings[] = '%'.$bigram.'%';
                $scoreBindings[] = '%'.$bigram.'%';
                $scoreBindings[] = $ordered;
                $scoreBindings[] = $ordered;
                $whereParts[] = '(prod_nombre '.$like.' ? OR prod_item '.$like.' ? OR prod_nombre '.$like.' ? OR prod_item '.$like.' ?)';
                $whereBindings[] = '%'.$bigram.'%';
                $whereBindings[] = '%'.$bigram.'%';
                $whereBindings[] = $ordered;
                $whereBindings[] = $ordered;
            }

            if (preg_match('/^\d+$/', $tok)) {
                $tokLen = strlen($tok);
                if ($tokLen <= 2 && $this->isPostgres()) {
                    $regex = '\\m'.preg_quote($tok, '/').'\\M';
                    $scoreParts[] = '(CASE WHEN prod_nombre ~* ? THEN 30 ELSE 0 END)';
                    $scoreBindings[] = $regex;
                } else {
                    $scoreParts[] = '(CASE WHEN prod_nombre '.$like.' ? THEN 30 ELSE 0 END)';
                    $scoreBindings[] = '%'.$tok.'%';
                    $whereParts[] = 'prod_nombre '.$like.' ?';
                    $whereBindings[] = '%'.$tok.'%';
                }
            } else {
                foreach ($this->tokenVariantes($tok) as $variante) {
                    $w = 42 + min(27, max(0, mb_strlen($variante, 'UTF-8') - 5) * 3);
                    $scoreParts[] = '(CASE WHEN prod_nombre '.$like.' ? OR prod_item '.$like.' ? THEN '.$w.' ELSE 0 END)';
                    $scoreBindings[] = '%'.$variante.'%';
                    $scoreBindings[] = '%'.$variante.'%';
                    $whereParts[] = '(prod_nombre '.$like.' ? OR prod_item '.$like.' ?)';
                    $whereBindings[] = '%'.$variante.'%';
                    $whereBindings[] = '%'.$variante.'%';
                }
            }

            $prevTok = $tok;
        }

        if ($scoreParts === [] || $whereParts === []) {
            return null;
        }

        return [
            implode(' + ', $scoreParts),
            implode(' OR ', $whereParts),
            $scoreBindings,
            $whereBindings,
        ];
    }

    /**
     * @param  string[]  $tokens
     */
    private function obtenerCandidatosPorPatron(string $patron, array $tokens, ?string $familia, int $candidatosMax): Collection
    {
        $query = Maeprod::query()
            ->whereNotNull('prod_nombre')
            ->where('prod_nombre', '!=', '')
            ->whereNotNull('prod_item')
            ->where('prod_item', '!=', '');

        if ($familia) {
            $query->where('prod_familia', trim($familia));
        }

        $like = $this->likeOperator();
        $query->where(function ($q) use ($patron, $tokens, $like) {
            if ($patron !== '') {
                $q->orWhere('prod_nombre', $like, '%'.$patron.'%');
            }
            foreach ($tokens as $token) {
                foreach ($this->tokenVariantes($token) as $variante) {
                    $q->orWhere('prod_nombre', $like, '%'.$variante.'%')
                        ->orWhere('prod_item', $like, '%'.$variante.'%');
                }
            }
        });

        return $query->limit($candidatosMax)->get();
    }

    private function likeOperator(): string
    {
        return $this->isPostgres() ? 'ilike' : 'like';
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * @param  array<int, Maeprod>  $filas
     * @return array<int, Maeprod>
     */
    private function filtrarPorPuntajeMinimo(array $filas, string $term): array
    {
        if ($filas === []) {
            return [];
        }

        $minPhp = max(1000.0, (float) config('cotiz.buscar_productos_score_php_minimo', 5000));
        $out = [];
        foreach ($filas as $row) {
            $nombre = (string) $row->prod_nombre;
            if (! $this->tieneSolapeDistintivo($term, $nombre)) {
                continue;
            }
            if ($this->scoreSimilitudFila($term, (string) $row->prod_item, $nombre) >= $minPhp) {
                $out[] = $row;
            }
        }

        return $out;
    }

    private function esStopword(string $token): bool
    {
        return in_array(mb_strtoupper($token, 'UTF-8'), self::STOPWORDS, true);
    }
}
