<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string|null $brand
 * @property string|null $make
 * @property string|null $series
 * @property string|null $collection_number
 * @property int|null $year
 * @property string|null $scale
 * @property string|null $color
 * @property string $rarity
 * @property string $packaging
 * @property string|null $condition
 * @property string $status
 * @property int $quantity
 * @property string|null $purchase_price
 * @property string|null $market_value
 * @property string|null $market_value_source manual|price_check
 * @property Carbon|null $market_value_updated_at
 * @property string|null $target_price
 * @property Carbon|null $purchase_date
 * @property string|null $purchase_place
 * @property string|null $storage_location
 * @property string|null $notes
 * @property bool $ai_scanned
 * @property-read string|null $photo_url
 * @property-read Collection<int, DiecastPhoto> $photos
 * @property-read Collection<int, Tag> $tags
 */
#[Fillable([
    'name', 'brand', 'make', 'series', 'collection_number', 'year', 'scale', 'color',
    'rarity', 'packaging', 'condition', 'status', 'quantity', 'purchase_price', 'market_value',
    'target_price', 'purchase_date', 'purchase_place', 'storage_location', 'notes', 'ai_scanned',
])]
class Diecast extends Model
{
    public const RARITIES = ['regular', 'treasure_hunt', 'super_treasure_hunt', 'chase', 'limited', 'exclusive'];

    public const PACKAGINGS = ['carded', 'boxed', 'loose'];

    public const CONDITIONS = ['mint', 'near_mint', 'good', 'fair', 'damaged'];

    public const STATUSES = ['owned', 'wishlist', 'sold'];

    public const SCALES = ['1:64', '1:43', '1:32', '1:24', '1:18', '1:87', '1:100'];

    /** Fields the AI scan can fill in. */
    public const SCAN_FIELDS = [
        'name', 'brand', 'make', 'series', 'collection_number', 'year', 'scale',
        'color', 'rarity', 'packaging', 'condition', 'notes',
    ];

    protected $with = ['photos'];

    protected $appends = ['photo_url'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'quantity' => 'integer',
            'purchase_price' => 'decimal:2',
            'market_value' => 'decimal:2',
            'market_value_updated_at' => 'datetime',
            'target_price' => 'decimal:2',
            'purchase_date' => 'date:Y-m-d',
            'ai_scanned' => 'boolean',
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
     * @return HasMany<DiecastPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(DiecastPhoto::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->orderBy('name');
    }

    /**
     * @return HasMany<PriceCheck, $this>
     */
    public function priceChecks(): HasMany
    {
        return $this->hasMany(PriceCheck::class)->latest('id');
    }

    /**
     * Record that a market value was typed in by hand (vs. taken from a price check).
     */
    protected static function booted(): void
    {
        static::saving(function (Diecast $diecast) {
            if ($diecast->isDirty('market_value') && ! $diecast->isDirty('market_value_source')) {
                $diecast->market_value_source = $diecast->market_value === null ? null : 'manual';
                $diecast->market_value_updated_at = $diecast->market_value === null ? null : now();
            }
        });
    }

    /**
     * Cover photo (the first one), as a relative URL so images work on any host/IP.
     */
    protected function photoUrl(): Attribute
    {
        return Attribute::get(fn () => $this->photos->first()?->url);
    }
}
