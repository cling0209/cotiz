<?php

namespace App\Support;

use Illuminate\Support\Str;

class CategorySlug
{
    /**
     * Normaliza el slug conservando mayúsculas/minúsculas (carpeta Romulo, ej. LIB).
     */
    public static function normalize(string $slug): string
    {
        $slug = trim($slug);

        if ($slug === '') {
            return '';
        }

        $slug = Str::ascii($slug);
        $slug = preg_replace('/\s+/', '-', $slug) ?? '';
        $slug = preg_replace('/[^A-Za-z0-9\-_]+/', '', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug;
    }

    public static function fromName(string $name): string
    {
        return self::normalize($name) ?: 'categoria';
    }
}
