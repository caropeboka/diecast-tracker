<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A "dex": the full list of releases for a line (e.g. Hot Wheels mainline 2026), from a wiki or built by hand.
 *
 * @property int $id
 * @property int $user_id
 * @property string $brand
 * @property string $name
 * @property int|null $year
 * @property string $source hotwheels_wiki|matchbox_wiki|custom
 * @property string|null $source_page
 * @property Carbon|null $synced_at
 * @property-read Collection<int, CatalogItem> $items
 */
#[Fillable(['brand', 'name', 'year', 'source', 'source_page', 'synced_at'])]
class Catalog extends Model
{
    public const SOURCES = ['hotwheels_wiki', 'matchbox_wiki', 'custom'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<CatalogItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CatalogItem::class)->orderBy('sort')->orderBy('variant')->orderBy('id');
    }

    public function isCustom(): bool
    {
        return $this->source === 'custom';
    }
}
