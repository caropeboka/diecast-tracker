<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 */
#[Fillable(['name'])]
class Tag extends Model
{
    protected $hidden = ['pivot', 'created_at', 'updated_at', 'user_id'];

    /**
     * @return BelongsToMany<Diecast, $this>
     */
    public function diecasts(): BelongsToMany
    {
        return $this->belongsToMany(Diecast::class);
    }
}
