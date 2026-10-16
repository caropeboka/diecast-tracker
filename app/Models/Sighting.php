<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One time a diecast was seen somewhere ("Ditemui"), optionally with the asking price and a photo.
 *
 * @property int $id
 * @property int $user_id
 * @property int $diecast_id
 * @property string|null $place
 * @property float|null $latitude
 * @property float|null $longitude
 * @property Carbon $seen_on
 * @property string|null $price
 * @property string|null $notes
 * @property string|null $photo_path
 * @property-read string|null $photo_url
 */
#[Fillable(['user_id', 'place', 'latitude', 'longitude', 'seen_on', 'price', 'notes', 'photo_path'])]
class Sighting extends Model
{
    protected $appends = ['photo_url'];

    protected $hidden = ['user_id', 'updated_at'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seen_on' => 'date:Y-m-d',
            'price' => 'decimal:2',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Diecast, $this>
     */
    public function diecast(): BelongsTo
    {
        return $this->belongsTo(Diecast::class);
    }

    protected function photoUrl(): Attribute
    {
        return Attribute::get(fn () => $this->photo_path ? '/storage/'.$this->photo_path : null);
    }
}
