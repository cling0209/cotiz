<?php

namespace App\Support;

/**
 * Romulo ↔ Reicol: URLs del sitio par según APP_URL.
 */
class CotizInstanciaPar
{
    /** @var array<string, string> host local => base URL del par (sin path) */
    private const BASE_PAR_POR_HOST = [
        'cotiza.reicol.cl' => 'https://cotiza.romulo.cl',
        'cotiza.romulo.cl' => 'https://cotiza.reicol.cl',
        'www.cotiza.reicol.cl' => 'https://cotiza.romulo.cl',
        'www.cotiza.romulo.cl' => 'https://cotiza.reicol.cl',
    ];

    /** Fallback cuando APP_URL no es el dominio canónico (Render, staging). */
    private const BASE_PAR_POR_SISTEMA = [
        'reicol' => 'https://cotiza.romulo.cl',
        'romulo' => 'https://cotiza.reicol.cl',
        'rómulo' => 'https://cotiza.reicol.cl',
    ];

    /** @var array<string, string> COTIZ_SISTEMA => host canónico local */
    private const HOST_POR_SISTEMA = [
        'reicol' => 'cotiza.reicol.cl',
        'romulo' => 'cotiza.romulo.cl',
        'rómulo' => 'cotiza.romulo.cl',
    ];

    /**
     * Host de esta instancia. Prioriza COTIZ_SISTEMA sobre APP_URL (Render a veces tiene APP_URL incorrecto).
     */
    public static function hostLocal(): string
    {
        $sistema = self::normalizarSistema((string) config('cotiz.sistema', ''));
        if (isset(self::HOST_POR_SISTEMA[$sistema])) {
            return self::HOST_POR_SISTEMA[$sistema];
        }

        return strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    }

    public static function hostAppUrl(): string
    {
        return strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    }

    public static function basePar(): ?string
    {
        $porHost = self::BASE_PAR_POR_HOST[self::hostLocal()] ?? null;
        if ($porHost !== null) {
            return $porHost;
        }

        $sistema = self::normalizarSistema((string) config('cotiz.sistema', ''));

        return self::BASE_PAR_POR_SISTEMA[$sistema] ?? null;
    }

    public static function debeExigirConsultaPar(): bool
    {
        return self::basePar() !== null;
    }

    public static function esInstanciaPar(): bool
    {
        return self::basePar() !== null;
    }

    public static function urlConsultaEncargadoEnv(): string
    {
        return trim((string) config('cotiz.api_nota.consulta_nro_cotizacion', ''));
    }

    public static function urlConsultaEncargado(): string
    {
        $explicit = self::urlConsultaEncargadoEnv();
        if ($explicit !== '' && ! self::urlApuntaAlHostLocal($explicit)) {
            return $explicit;
        }

        $base = self::basePar();

        return $base ? rtrim($base, '/').'/api/v1/nota-consulta' : '';
    }

    /** Health check Render (/up) para despertar el sitio par dormido. */
    public static function urlDespertarSitioPar(): string
    {
        $base = self::basePar();

        return $base ? rtrim($base, '/').'/up' : '';
    }

    /**
     * @return array{
     *     url_env: string|null,
     *     url_utilizada: string,
     *     nota_url: string|null,
     *     app_url: string,
     *     host_local: string,
     *     host_app_url: string,
     *     cotiz_sistema: string
     * }
     */
    public static function resolucionUrlConsulta(): array
    {
        $env = self::urlConsultaEncargadoEnv();
        $utilizada = self::urlConsultaEncargado();
        $nota = null;

        if ($env === '') {
            $nota = $utilizada !== ''
                ? 'COTIZ_API_CONSULTA_NRO_COTIZACION vacía: URL inferida de COTIZ_SISTEMA / APP_URL.'
                : null;
        } elseif ($env !== $utilizada) {
            $nota = self::urlApuntaAlHostLocal($env)
                ? 'COTIZ_API_CONSULTA_NRO_COTIZACION apunta a este mismo sitio y se ignoró; se usó el par automático.'
                : null;
        }

        return [
            'url_env' => $env !== '' ? $env : null,
            'url_utilizada' => $utilizada,
            'nota_url' => $nota,
            'app_url' => (string) config('app.url'),
            'host_local' => self::hostLocal(),
            'host_app_url' => self::hostAppUrl(),
            'cotiz_sistema' => (string) config('cotiz.sistema', ''),
        ];
    }

    public static function urlApuntaAlHostLocal(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $local = self::hostLocal();

        return $host !== '' && $local !== '' && $host === $local;
    }

    public static function hostRemotoConsulta(): string
    {
        return strtolower((string) parse_url(self::urlConsultaEncargado(), PHP_URL_HOST));
    }

    public static function nombrePar(): string
    {
        $host = self::hostRemotoConsulta();
        if (str_contains($host, 'romulo')) {
            return 'Rómulo';
        }
        if (str_contains($host, 'reicol')) {
            return 'Reicol';
        }

        return $host !== '' ? $host : 'sitio par';
    }

    public static function debeConsultarPar(): bool
    {
        $url = self::urlConsultaEncargado();
        if ($url === '') {
            return false;
        }

        $hostRemoto = self::hostRemotoConsulta();
        $hostLocal = self::hostLocal();

        return $hostRemoto !== '' && $hostLocal !== '' && $hostRemoto !== $hostLocal;
    }

    private static function normalizarSistema(string $sistema): string
    {
        return strtolower(trim($sistema));
    }
}
