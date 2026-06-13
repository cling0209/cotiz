<?php

namespace App\Support;

class MaeprodImportColumnMapping
{
    /** @var array<string, array{label: string, required: bool, key: string}> */
    public const FIELDS = [
        'codigo' => ['label' => 'Código', 'required' => true, 'key' => 'prod_item'],
        'nombre' => ['label' => 'Nombre', 'required' => true, 'key' => 'prod_nombre'],
        'familia' => ['label' => 'Familia', 'required' => true, 'key' => 'prod_familia'],
        'precio' => ['label' => 'Precio', 'required' => true, 'key' => 'prod_valor'],
        'costo' => ['label' => 'Costo', 'required' => false, 'key' => 'prod_valor_costo'],
        'nombre_archivo' => ['label' => 'Nombre archivo imagen', 'required' => false, 'key' => 'prod_imagen'],
        'gramaje' => ['label' => 'Gramaje', 'required' => false, 'key' => 'prod_gramaje'],
        'stock' => ['label' => 'Stock', 'required' => false, 'key' => 'prod_stock_real'],
        'softland' => ['label' => 'Softland', 'required' => false, 'key' => 'prod_item_softland'],
    ];

    /**
     * @return list<array{field: string, label: string, required: bool}>
     */
    public static function fieldDefinitions(): array
    {
        $definitions = [];

        foreach (self::FIELDS as $field => $meta) {
            $definitions[] = [
                'field' => $field,
                'label' => $meta['label'],
                'required' => $meta['required'],
            ];
        }

        return $definitions;
    }

    /**
     * @param  array<string, string|null>  $mapping
     */
    public static function validate(array $mapping): void
    {
        foreach (self::FIELDS as $field => $meta) {
            if (! $meta['required']) {
                continue;
            }

            $source = trim((string) ($mapping[$field] ?? ''));

            if ($source === '') {
                throw new \InvalidArgumentException("Debe indicar la columna para «{$meta['label']}».");
            }
        }

        $used = [];
        foreach ($mapping as $field => $source) {
            if (! isset(self::FIELDS[$field])) {
                throw new \InvalidArgumentException("Campo de mapeo desconocido: {$field}.");
            }

            $source = trim((string) $source);
            if ($source === '') {
                continue;
            }

            if (isset($used[$source])) {
                throw new \InvalidArgumentException("La columna «{$source}» está asignada a más de un campo.");
            }

            $used[$source] = $field;
        }
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, string>
     */
    public static function suggest(array $headers): array
    {
        $aliases = [
            'codigo' => ['codigo', 'prod_item', 'sku', 'item', 'code'],
            'nombre' => ['nombre', 'prod_nombre', 'descripcion', 'description', 'producto'],
            'familia' => ['familia', 'prod_familia', 'family', 'categoria'],
            'precio' => ['precio', 'prod_valor', 'price', 'valor', 'pvp'],
            'costo' => ['costo', 'prod_valor_costo', 'cost'],
            'nombre_archivo' => ['nombre_archivo', 'prod_imagen', 'imagen', 'image', 'archivo'],
            'gramaje' => ['gramaje', 'prod_gramaje', 'peso', 'weight'],
            'stock' => ['stock', 'prod_stock_real', 'inventario', 'qty'],
            'softland' => ['softland', 'prod_item_softland', 'cod_softland'],
        ];

        $normalized = [];
        foreach ($headers as $header) {
            $normalized[mb_strtolower(trim($header))] = $header;
        }

        $suggested = [];

        foreach ($aliases as $field => $candidates) {
            foreach ($candidates as $candidate) {
                if (isset($normalized[$candidate])) {
                    $suggested[$field] = $normalized[$candidate];
                    break;
                }
            }
        }

        return $suggested;
    }
}
