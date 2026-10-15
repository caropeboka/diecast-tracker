<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photo waiting for the AI and/or the user's review.
 * source: "bulk" (bulk scan queue) or "check" (in-store collection check).
 * status: pending → done | failed → saved.
 *
 * @property int $id
 * @property int $user_id
 * @property string $source
 * @property string $photo_path
 * @property string $status
 * @property array<string, mixed>|null $fields
 * @property string|null $error
 * @property int|null $diecast_id
 * @property-read string $photo_url
 */
#[Fillable(['source', 'photo_path', 'status', 'fields', 'error', 'diecast_id'])]
class ScanItem extends Model
{
    protected $appends = ['photo_url'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fields' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function photoUrl(): Attribute
    {
        return Attribute::get(fn () => '/storage/'.$this->photo_path);
    }
}
