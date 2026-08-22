<?php

namespace App\Support;

class MaterialesImportArchivo
{
    public static function maxMb(): int
    {
        return max(1, (int) config('cotiz.materiales_import.max_archivo_mb', 50));
    }

    public static function maxKb(): int
    {
        return self::maxMb() * 1024;
    }

    public static function maxBytes(): int
    {
        return self::maxMb() * 1024 * 1024;
    }

    public static function superaLimite(int $bytes): bool
    {
        return $bytes > self::maxBytes();
    }

    public static function mensajeSuperaLimite(string $nombre = ''): string
    {
        $limite = self::maxMb().' MB';
        $nom = trim($nombre);
        if ($nom !== '') {
            return "El archivo «{$nom}» supera el límite de {$limite}.";
        }

        return "El archivo supera el límite de {$limite}.";
    }
}
