<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One release in a dex. Rows sharing a number (color variants, TH/STH, exclusives) form one entry.
 *
 * @property int $id
 * @property int $catalog_id
 * @property string $number
 * @property int $sort
 * @property int $variant 1 = the main release
 * @property string|null $variant_label
 * @property string $name
 * @property string|null $series
 * @property string|null $series_number
 * @property string|null $toy_code
 * @property array<string, bool>|null $flags new, th, sth, exclusive
 * @property string|null $image_file
 * @property string|null $image_url
 * @property string|null $image_path
 * @property-read string|null $photo_url
 * @property-read Catalog $catalog
 * @property-read Collection<int, Diecast> $diecasts
 */
#[Fillable([
    'number', 'sort', 'variant', 'variant_label', 'name', 'series', 'series_number', 'toy_code',
    'flags', 'image_file', 'image_url', 'image_path',
])]
class CatalogItem extends Model
{
    protected $appends = ['photo_url'];

    protected $hidden = ['image_url', 'image_path', 'image_file', 'created_at', 'updated_at'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'variant' => 'integer',
            'flags' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Catalog, $this>
     */
    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class);
    }

    /**
     * @return HasMany<Diecast, $this>
     */
    public function diecasts(): HasMany
    {
        return $this->hasMany(Diecast::class);
    }

    /**
     * The downloaded thumbnail (wiki photos can't be hotlinked), as a relative URL.
     */
    protected function photoUrl(): Attribute
    {
        return Attribute::get(fn () => $this->image_path ? '/storage/'.$this->image_path : null);
    }
}
