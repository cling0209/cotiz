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

    public static function hostLocal(): string
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

    /**
     * @return array{url_env: string|null, url_utilizada: string, nota_url: string|null}
     */
    public static function resolucionUrlConsulta(): array
    {
        $env = self::urlConsultaEncargadoEnv();
        $utilizada = self::urlConsultaEncargado();
        $nota = null;

        if ($env === '') {
            $nota = $utilizada !== ''
                ? 'COTIZ_API_CONSULTA_NRO_COTIZACION vacía: URL inferida de APP_URL / COTIZ_SISTEMA.'
                : null;
        } elseif ($env !== $utilizada) {
            $nota = self::urlApuntaAlHostLocal($env)
                ? 'COTIZ_API_CONSULTA_NRO_COTIZACION apunta a este mismo sitio y se ignoró; se usó el par automático.'
                : 'Se usó otra URL distinta a la del entorno (revisar APP_URL / COTIZ_SISTEMA).';
        }

        return [
            'url_env' => $env !== '' ? $env : null,
            'url_utilizada' => $utilizada,
            'nota_url' => $nota,
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
