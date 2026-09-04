<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

class MaeprodImportOptions
{
    /** @var list<string> */
    public const UPDATABLE_FIELDS = [
        'nombre',
        'familia',
        'precio',
        'costo',
        'nombre_archivo',
        'gramaje',
        'stock',
        'softland',
    ];

    /**
     * @return array{allow_create: bool, updatable_fields: list<string>}
     */
    public static function defaults(): array
    {
        $allowCreate = filter_var(
            config('cotiz.import.allow_create', true),
            FILTER_VALIDATE_BOOL,
        );

        $fields = config('cotiz.import.updatable_fields', self::UPDATABLE_FIELDS);

        if (! is_array($fields) || $fields === []) {
            $fields = self::UPDATABLE_FIELDS;
        }

        return self::normalize([
            'allow_create' => $allowCreate,
            'updatable_fields' => $fields,
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{allow_create: bool, updatable_fields: list<string>}
     */
    public static function normalize(array $input): array
    {
        $allowCreate = filter_var($input['allow_create'] ?? true, FILTER_VALIDATE_BOOL);

        $fields = $input['updatable_fields'] ?? self::UPDATABLE_FIELDS;
        if (! is_array($fields)) {
            $fields = self::UPDATABLE_FIELDS;
        }

        $normalized = [];
        foreach ($fields as $field) {
            $field = trim((string) $field);
            if ($field !== '' && in_array($field, self::UPDATABLE_FIELDS, true)) {
                $normalized[] = $field;
            }
        }

        $normalized = array_values(array_unique($normalized));

        if ($normalized === []) {
            $normalized = self::UPDATABLE_FIELDS;
        }

        return [
            'allow_create' => (bool) $allowCreate,
            'updatable_fields' => $normalized,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        return [
            'import_options' => ['nullable', 'array'],
            'import_options.allow_create' => ['nullable', 'boolean'],
            'import_options.updatable_fields' => ['nullable', 'array'],
            'import_options.updatable_fields.*' => ['string', 'in:'.implode(',', self::UPDATABLE_FIELDS)],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $input
     * @return array{allow_create: bool, updatable_fields: list<string>}
     */
    public static function fromRequest(?array $input): array
    {
        if ($input === null) {
            return self::defaults();
        }

        $defaults = self::defaults();

        return self::normalize([
            'allow_create' => array_key_exists('allow_create', $input)
                ? $input['allow_create']
                : $defaults['allow_create'],
            'updatable_fields' => array_key_exists('updatable_fields', $input)
                ? $input['updatable_fields']
                : $defaults['updatable_fields'],
        ]);
    }

    /**
     * @param  array{allow_create: bool, updatable_fields: list<string>}  $options
     */
    public static function persist(string $uploadId, array $options): void
    {
        $options = self::normalize($options);
        $dir = self::directory($uploadId);

        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        File::put(
            self::path($uploadId),
            json_encode($options, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array{allow_create: bool, updatable_fields: list<string>}
     */
    public static function load(string $uploadId): array
    {
        $path = self::path($uploadId);

        if (! File::exists($path)) {
            return self::defaults();
        }

        try {
            $decoded = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return self::defaults();
        }

        return self::normalize(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param  array<string, mixed>|null  $fromJob
     * @return array{allow_create: bool, updatable_fields: list<string>}
     */
    public static function resolve(?array $fromJob, ?string $uploadId = null): array
    {
        if (is_array($fromJob) && $fromJob !== []) {
            return self::normalize($fromJob);
        }

        if ($uploadId !== null && $uploadId !== '') {
            return self::load($uploadId);
        }

        return self::defaults();
    }

    public static function allowsField(array $options, string $field): bool
    {
        return in_array($field, $options['updatable_fields'] ?? [], true);
    }

    /**
     * @return list<array{field: string, label: string}>
     */
    public static function fieldDefinitions(): array
    {
        $definitions = [];

        foreach (self::UPDATABLE_FIELDS as $field) {
            $meta = MaeprodImportColumnMapping::FIELDS[$field] ?? null;
            if ($meta === null) {
                continue;
            }

            $definitions[] = [
                'field' => $field,
                'label' => $meta['label'],
            ];
        }

        return $definitions;
    }

    protected static function directory(string $uploadId): string
    {
        return storage_path('app/imports/jobs/'.$uploadId);
    }

    protected static function path(string $uploadId): string
    {
        return self::directory($uploadId).'/import-options.json';
    }
}
