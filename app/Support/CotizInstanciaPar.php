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

    public static function hostLocal(): string
    {
        return strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    }

    public static function basePar(): ?string
    {
        return self::BASE_PAR_POR_HOST[self::hostLocal()] ?? null;
    }

    public static function esInstanciaPar(): bool
    {
        return self::basePar() !== null;
    }

    public static function urlConsultaEncargado(): string
    {
        $explicit = trim((string) config('cotiz.api_nota.consulta_nro_cotizacion', ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $base = self::basePar();

        return $base ? rtrim($base, '/').'/api/v1/nota-consulta' : '';
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
}
