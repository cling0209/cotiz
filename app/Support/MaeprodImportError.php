<?php

namespace App\Support;

class MaeprodImportError
{
    /**
     * @param  array<string, string>  $row
     * @return array{fila: int|null, codigo: string, nombre: string, familia: string, mensaje: string, detalle: string|null}
     */
    public static function row(int $fila, array $row, string $mensaje, ?string $detalle = null): array
    {
        return [
            'fila' => $fila,
            'codigo' => self::value($row, ['prod_item', 'codigo', 'sku']),
            'nombre' => self::value($row, ['prod_nombre', 'nombre', 'descripcion']),
            'familia' => self::value($row, ['prod_familia', 'familia']),
            'mensaje' => $mensaje,
            'detalle' => $detalle,
        ];
    }

    /**
     * @return array{fila: int|null, codigo: string, nombre: string, familia: string, mensaje: string, detalle: string|null}
     */
    public static function general(string $mensaje, ?int $fila = null, ?string $codigo = null): array
    {
        return [
            'fila' => $fila,
            'codigo' => $codigo ?? '',
            'nombre' => '',
            'familia' => '',
            'mensaje' => $mensaje,
            'detalle' => null,
        ];
    }

    /**
     * @param  array{fila: int|null, codigo: string, nombre: string, familia: string, mensaje: string, detalle: string|null}  $error
     */
    public static function summary(array $error): string
    {
        $parts = [];

        if ($error['fila'] !== null) {
            $parts[] = 'Fila '.$error['fila'];
        }

        if ($error['codigo'] !== '') {
            $parts[] = 'código '.$error['codigo'];
        }

        if ($error['nombre'] !== '') {
            $parts[] = 'nombre "'.self::truncate($error['nombre'], 40).'"';
        }

        if ($error['familia'] !== '') {
            $parts[] = 'familia '.$error['familia'];
        }

        $text = $parts !== [] ? implode(' — ', $parts).': ' : '';
        $text .= $error['mensaje'];

        if ($error['detalle'] !== null && $error['detalle'] !== '') {
            $text .= ' ('.$error['detalle'].')';
        }

        return $text;
    }

    /**
     * @param  list<array{fila: int|null, codigo: string, nombre: string, familia: string, mensaje: string, detalle: string|null}>  $errors
     */
    public static function storeForDownload(array $errors): ?string
    {
        if ($errors === []) {
            return null;
        }

        $token = (string) \Illuminate\Support\Str::uuid();
        $dir = storage_path('app/imports/errors');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $dir.'/'.$token.'.json',
            json_encode($errors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );

        return $token;
    }

    /**
     * @return list<array{fila: int|null, codigo: string, nombre: string, familia: string, mensaje: string, detalle: string|null}>
     */
    public static function readStored(string $token): array
    {
        if (! \Illuminate\Support\Str::isUuid($token)) {
            return [];
        }

        $path = storage_path('app/imports/errors/'.$token.'.json');

        if (! is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, string>  $row
     * @param  list<string>  $keys
     */
    private static function value(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
                return trim((string) $row[$key]);
            }
        }

        return '';
    }

    private static function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max - 1).'…';
    }
}
