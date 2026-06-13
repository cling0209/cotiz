<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Maeprod extends Model
{
    protected $table = 'maeprod';

    public $incrementing = false;

    protected $primaryKey = 'prod_item';

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'prod_item', 'prod_nombre', 'prod_imagen', 'prod_valor', 'prod_stock_real',
        'prod_gramaje', 'prod_familia', 'prod_item_softland', 'prod_valor_fecha',
        'prod_item_softland_fecha', 'prod_valor_costo', 'prod_user_upd',
    ];

    protected function casts(): array
    {
        return [
            'prod_valor_fecha' => 'datetime',
            'prod_item_softland_fecha' => 'datetime',
        ];
    }

    public function imageUrl(): string
    {
        return $this->resolveImageUrl();
    }

    public function resolveImageUrl(): string
    {
        return $this->buildExternalImageUrl() ?? '';
    }

    public function buildExternalImageUrl(): ?string
    {
        $base = rtrim((string) config('products.image_base_url'), '/');
        $folder = $this->resolveFamiliaFolder();
        $filename = trim((string) $this->prod_imagen);

        if ($base === '' || $folder === '') {
            return null;
        }

        if ($filename === '') {
            $filename = $this->guessImageFilename();
        }

        if ($filename === '') {
            return null;
        }

        return $base.'/'.trim($folder, '/').'/'.ltrim($filename, '/');
    }

    /**
     * @return list<string>
     */
    public function imageUrlCandidates(): array
    {
        $base = rtrim((string) config('products.image_base_url'), '/');
        $folder = $this->resolveFamiliaFolder();

        if ($base === '' || $folder === '') {
            return [];
        }

        $filenames = $this->imageFilenameCandidates();
        $urls = [];

        foreach ($filenames as $filename) {
            $urls[] = $base.'/'.trim($folder, '/').'/'.ltrim($filename, '/');
        }

        return array_values(array_unique($urls));
    }

    private function resolveFamiliaFolder(): string
    {
        $familia = trim((string) $this->prod_familia);

        if ($familia === '') {
            return '';
        }

        $codigo = Famprod::query()
            ->where('codigo', $familia)
            ->orWhere('nombre', $familia)
            ->value('codigo');

        if ($codigo) {
            return trim((string) $codigo);
        }

        return match (mb_strtoupper($familia)) {
            'PAPELERIA' => 'PAPEL',
            'LIBRERIA' => 'LIBR',
            default => $familia,
        };
    }

    private function guessImageFilename(): string
    {
        $item = trim((string) $this->prod_item);

        if ($item === '') {
            return '';
        }

        return $item.'.jpg';
    }

    /**
     * @return list<string>
     */
    private function imageFilenameCandidates(): array
    {
        $primary = trim((string) $this->prod_imagen);

        if ($primary === '') {
            $primary = $this->guessImageFilename();
        }

        if ($primary === '') {
            return [];
        }

        $candidates = [$primary];

        if (preg_match('/^(.+)_medium(\.[^.]+)$/i', $primary, $matches)) {
            $candidates[] = $matches[1].$matches[2];
        } elseif (preg_match('/^(.+)(\.[^.]+)$/i', $primary, $matches)) {
            $candidates[] = $matches[1].'_medium'.$matches[2];
        }

        return array_values(array_unique($candidates));
    }
}
