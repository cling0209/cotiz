<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'category_id', 'sku', 'familia', 'image_filename', 'name', 'slug', 'description',
        'price', 'compare_at_price', 'stock', 'weight_kg', 'is_active', 'is_featured', 'metadata',
    ];

    protected $appends = ['image_url'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'weight_kg' => 'decimal:3',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class);
    }

    public function getImageUrlAttribute(): string
    {
        return $this->resolveImageUrl();
    }

    public function resolveImageUrl(): string
    {
        $computed = $this->buildExternalImageUrl();

        if ($computed !== null) {
            return $computed;
        }

        $image = $this->relationLoaded('images')
            ? ($this->images->firstWhere('is_primary', true) ?? $this->images->first())
            : $this->images()->where('is_primary', true)->first()
                ?? $this->images()->orderBy('sort_order')->first();

        if ($image?->url) {
            return $image->url;
        }

        return '';
    }

    public function buildExternalImageUrl(): ?string
    {
        $base = rtrim((string) config('products.image_base_url'), '/');
        $folder = trim((string) $this->familia);
        $filename = trim((string) $this->image_filename);

        if ($base === '' || $folder === '' || $filename === '') {
            return null;
        }

        return $base.'/'.trim($folder, '/').'/'.ltrim($filename, '/');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(function (Builder $q) {
                $q->whereNull('category_id')
                    ->orWhereHas('category');
            });
    }

    public function archive(): void
    {
        $this->update(['is_active' => false]);
        $this->delete();
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'ilike', "%{$term}%")
                ->orWhere('description', 'ilike', "%{$term}%")
                ->orWhere('sku', 'ilike', "%{$term}%")
                ->orWhereHas('category', fn (Builder $cat) => $cat
                    ->where('name', 'ilike', "%{$term}%")
                    ->orWhere('slug', 'ilike', "%{$term}%"));
        });
    }
}
