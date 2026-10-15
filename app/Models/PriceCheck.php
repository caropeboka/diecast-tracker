<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Market price check from marketplace screenshots.
 * listings: every listing the AI read, each with "status" (included / outlier / excluded) and a reason.
 * stats: count, min, q1, median, q3, max, spread — only over included listings; null when fewer than 1.
 *
 * @property int $id
 * @property int $user_id
 * @property int $diecast_id
 * @property array<int, string> $screenshots
 * @property array<int, array<string, mixed>> $listings
 * @property array<string, int|float>|null $stats
 * @property string $accuracy high|medium|low|insufficient
 * @property array<int, string> $reasons
 * @property array<int, string> $tips
 * @property string|null $ai_note
 * @property Carbon|null $applied_at
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'screenshots', 'listings', 'stats', 'accuracy', 'reasons', 'tips', 'ai_note', 'applied_at'])]
class PriceCheck extends Model
{
    protected $appends = ['screenshot_urls'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'screenshots' => 'array',
            'listings' => 'array',
            'stats' => 'array',
            'reasons' => 'array',
            'tips' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Diecast, $this>
     */
    public function diecast(): BelongsTo
    {
        return $this->belongsTo(Diecast::class);
    }

    protected function screenshotUrls(): Attribute
    {
        return Attribute::get(fn () => array_map(fn (string $path) => '/storage/'.$path, $this->screenshots ?? []));
    }
}
