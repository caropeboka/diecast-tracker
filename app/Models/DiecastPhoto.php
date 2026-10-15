<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $diecast_id
 * @property string $path
 * @property int $position
 * @property-read string $url
 */
#[Fillable(['path', 'position'])]
class DiecastPhoto extends Model
{
    protected $appends = ['url'];

    protected $hidden = ['created_at', 'updated_at'];

    /**
     * @return BelongsTo<Diecast, $this>
     */
    public function diecast(): BelongsTo
    {
        return $this->belongsTo(Diecast::class);
    }

    protected function url(): Attribute
    {
        return Attribute::get(fn () => '/storage/'.$this->path);
    }
}
