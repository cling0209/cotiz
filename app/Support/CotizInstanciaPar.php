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

    public static function urlConsultaEncargado(): string
    {
        $explicit = trim((string) config('cotiz.api_nota.consulta_nro_cotizacion', ''));
        if ($explicit !== '' && ! self::urlApuntaAlHostLocal($explicit)) {
            return $explicit;
        }

        $base = self::basePar();

        return $base ? rtrim($base, '/').'/api/v1/nota-consulta' : '';
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
