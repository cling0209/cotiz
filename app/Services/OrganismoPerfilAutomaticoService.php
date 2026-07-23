<?php

namespace App\Services;

use App\Models\CompraAgilProceso;
use App\Models\OrganismoObservacion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Perfil automático del organismo (marca / calidad) a partir de adjudicaciones.
 * Solo debe ejecutarse en el sitio con MERCADOPUBLICO_ANALISIS_ADMIN=true.
 */
class OrganismoPerfilAutomaticoService
{
    private const MIN_CASOS = 3;

    /** @var list<string> */
    private const MARCAS = [
        'brother', 'hp', 'hewlett', 'epson', 'canon', 'samsung', 'xerox', 'kyocera',
        'ricoh', 'lexmark', 'dell', 'lenovo', 'asus', 'acer', 'apple', 'microsoft',
        '3m', 'bic', 'pilot', 'faber', 'stabilo', 'staedtler', 'sharpie', 'post-it',
        'scotch', 'kimberly', 'softys', 'elite', 'confort', 'scott', 'chuin', 'torre',
        'artel', 'vinifan', 'cobra', 'leitz', 'fellowes', 'avery', 'dymo', 'casio',
        'panasonic', 'lg', 'sony', 'philips', 'bosch', 'makita', 'dewalt',
    ];

    /** @var list<string> */
    private const CALIDAD = [
        'original', 'originales', 'certificado', 'certificada', 'premium', 'genuino',
        'genuina', 'oficial', 'autentico', 'auténtico',
    ];

    /** @var list<string> */
    private const GENERICO = [
        'generico', 'genérico', 'similar', 'compatibles', 'compatible', 'alternativo',
        'alternativa', 'economico', 'económico', 'remanufacturado',
    ];

    public function __construct(
        protected CompraAgilTextoParserService $parser,
        protected OrganismoObservacionService $organismos,
    ) {}

    public function analisisHabilitado(): bool
    {
        return (bool) config('cotiz.mercadopublico.analisis_admin_habilitado', false);
    }

    /**
     * Recalcula perfiles y persiste. Devuelve contadores.
     *
     * @return array{organismos: int, con_perfil: int, sin_historial: int}
     */
    public function recalcularTodos(): array
    {
        if (! $this->analisisHabilitado()) {
            return ['organismos' => 0, 'con_perfil' => 0, 'sin_historial' => 0];
        }

        $this->organismos->sincronizarDesdeCerradas();

        $textosPorProceso = $this->textosAdjudicadosPorRut();
        $ahora = now();
        $conPerfil = 0;
        $sinHistorial = 0;
        $total = 0;

        OrganismoObservacion::query()->orderBy('id')->chunkById(100, function ($chunk) use (
            $textosPorProceso,
            $ahora,
            &$conPerfil,
            &$sinHistorial,
            &$total,
        ) {
            foreach ($chunk as $org) {
                $total++;
                $rut = (string) $org->rut_organismo;
                $textos = $textosPorProceso->get('rut:'.$rut, collect());
                $perfil = $this->armarPerfil($textos);

                $org->observacion_automatica = $perfil['texto'];
                $org->observacion_automatica_casos = $perfil['casos'];
                $org->observacion_automatica_en = $ahora;
                $org->save();

                if ($perfil['casos'] >= self::MIN_CASOS) {
                    $conPerfil++;
                } else {
                    $sinHistorial++;
                }
            }
        });

        return [
            'organismos' => $total,
            'con_perfil' => $conPerfil,
            'sin_historial' => $sinHistorial,
        ];
    }

    /**
     * @param  Collection<int, string>  $textosPorCaso  un string por CA (productos adjudicados concatenados)
     * @return array{texto: string|null, casos: int}
     */
    public function armarPerfil(Collection $textosPorCaso): array
    {
        $casos = $textosPorCaso->filter(fn ($t) => trim((string) $t) !== '')->values();
        $n = $casos->count();

        if ($n < self::MIN_CASOS) {
            return [
                'texto' => $n === 0
                    ? 'Sin historial de adjudicaciones con productos para inferir preferencias.'
                    : "Historial insuficiente ({$n} CA con productos; se requieren al menos ".self::MIN_CASOS.').',
                'casos' => $n,
            ];
        }

        $marcaCounts = [];
        $casosConMarca = 0;
        $casosCalidad = 0;
        $casosGenerico = 0;

        foreach ($casos as $texto) {
            $norm = mb_strtolower($texto);
            $marcasEnCaso = [];
            foreach (self::MARCAS as $marca) {
                if ($this->contienePalabra($norm, $marca)) {
                    $marcasEnCaso[$marca] = true;
                    $marcaCounts[$marca] = ($marcaCounts[$marca] ?? 0) + 1;
                }
            }
            if ($marcasEnCaso !== []) {
                $casosConMarca++;
            }

            $tieneCalidad = false;
            foreach (self::CALIDAD as $kw) {
                if ($this->contienePalabra($norm, $kw)) {
                    $tieneCalidad = true;
                    break;
                }
            }
            if ($tieneCalidad) {
                $casosCalidad++;
            }

            $tieneGenerico = false;
            foreach (self::GENERICO as $kw) {
                if ($this->contienePalabra($norm, $kw)) {
                    $tieneGenerico = true;
                    break;
                }
            }
            if ($tieneGenerico) {
                $casosGenerico++;
            }
        }

        arsort($marcaCounts);
        $topMarcas = array_slice(array_keys($marcaCounts), 0, 5);
        $topMarcasLabel = array_map(fn ($m) => $this->etiquetaMarca($m), $topMarcas);

        $partes = ["Según {$n} CA con productos adjudicados"];

        $pctMarca = $casosConMarca / $n;
        if ($pctMarca >= 0.45 && $topMarcasLabel !== []) {
            $partes[] = 'suele adjudicar marca reconocida (frecuentes: '.implode(', ', $topMarcasLabel).')';
        } elseif ($topMarcasLabel !== []) {
            $partes[] = 'marcas vistas: '.implode(', ', $topMarcasLabel);
        } else {
            $partes[] = 'pocas marcas fuertes detectadas en textos adjudicados';
        }

        if ($casosCalidad >= max(2, (int) ceil($n * 0.25)) && $casosCalidad >= $casosGenerico) {
            $partes[] = 'señales de calidad/original en varias adjudicaciones';
        } elseif ($casosGenerico >= max(2, (int) ceil($n * 0.25)) && $casosGenerico > $casosCalidad) {
            $partes[] = 'tiende a genérico/económico o compatible';
        }

        return [
            'texto' => implode('. ', $partes).'.',
            'casos' => $n,
        ];
    }

    /**
     * @return Collection<string, Collection<int, string>> rut => textos por proceso
     */
    private function textosAdjudicadosPorRut(): Collection
    {
        /** @var array<string, list<string>> $map */
        $map = [];

        $desdeCache = CompraAgilProceso::query()
            ->whereNotNull('rut_organismo')
            ->whereRaw("trim(rut_organismo) <> ''")
            ->whereNotNull('rut_ganador')
            ->whereRaw("trim(rut_ganador) <> ''")
            ->with(['lineasMercado' => fn ($q) => $q->select(['id', 'codigo_proceso', 'nombre_producto'])])
            ->orderByDesc('fecha_cierre')
            ->limit(8000)
            ->get();

        foreach ($desdeCache as $proc) {
            $rut = $this->parser->normalizarRut((string) $proc->rut_organismo);
            if ($rut === '') {
                continue;
            }
            $texto = $proc->lineasMercado
                ->map(fn ($l) => trim((string) ($l->nombre_producto ?? '')))
                ->filter()
                ->implode(' | ');
            if ($texto === '') {
                continue;
            }
            $key = 'rut:'.$rut;
            $map[$key] ??= [];
            $map[$key][] = $texto;
        }

        $desdeNotas = DB::table('nota_mp_seguimientos as s')
            ->join('notas as n', 'n.nronota', '=', 's.nronota')
            ->join('nota_mp_ofertas as o', 'o.nronota', '=', 's.nronota')
            ->join('nota_mp_oferta_lineas as l', 'l.oferta_id', '=', 'o.id')
            ->whereRaw('s.finalizado IS TRUE')
            ->whereRaw('o.proveedor_seleccionado IS TRUE')
            ->whereNotNull('n.rutempresa')
            ->whereRaw("trim(n.rutempresa) <> ''")
            ->select([
                'n.rutempresa',
                's.nronota',
                'l.nombre_producto',
                'l.descripcion',
            ])
            ->orderByDesc('s.nronota')
            ->limit(15000)
            ->get()
            ->groupBy('nronota');

        foreach ($desdeNotas as $lineas) {
            $rut = $this->parser->normalizarRut((string) ($lineas->first()->rutempresa ?? ''));
            if ($rut === '') {
                continue;
            }
            $texto = $lineas
                ->map(fn ($row) => trim(trim((string) ($row->nombre_producto ?? '')).' '.trim((string) ($row->descripcion ?? ''))))
                ->filter()
                ->implode(' | ');
            if ($texto === '') {
                continue;
            }
            $key = 'rut:'.$rut;
            $map[$key] ??= [];
            $map[$key][] = $texto;
        }

        $out = collect();
        foreach ($map as $key => $textos) {
            $out->put((string) $key, collect($textos));
        }

        return $out;
    }

    private function contienePalabra(string $haystackLower, string $needleLower): bool
    {
        $needle = preg_quote($needleLower, '/');

        return (bool) preg_match('/(?:^|[^a-z0-9áéíóúñ])'.$needle.'(?:[^a-z0-9áéíóúñ]|$)/u', $haystackLower);
    }

    private function etiquetaMarca(string $marca): string
    {
        return match ($marca) {
            'hewlett' => 'HP',
            'post-it' => 'Post-it',
            '3m' => '3M',
            default => mb_convert_case($marca, MB_CASE_TITLE, 'UTF-8'),
        };
    }
}
