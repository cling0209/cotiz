<?php

namespace App\Enums;

enum MaeprodSoftlandOrigen: string
{
    case PRODUCTO = 'producto';
    case COTIZACION = 'cotizacion';
    case IMPORT = 'import';
    case API = 'api';

    public function label(): string
    {
        return match ($this) {
            self::PRODUCTO => 'Mantenedor de productos',
            self::COTIZACION => 'Cotización',
            self::IMPORT => 'Carga masiva',
            self::API => 'API recepción',
        };
    }
}
