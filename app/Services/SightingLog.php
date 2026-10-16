<?php

namespace App\Services;

use App\Models\Diecast;
use App\Models\Sighting;

/**
 * Records "Ditemui" sightings: where a diecast was seen, when, and for how much.
 */
class SightingLog
{
    public function __construct(private PhotoStorage $photos) {}

    /**
     * @param  array{place?: ?string, latitude?: mixed, longitude?: mixed, seen_on?: ?string, price?: mixed, notes?: ?string}  $data
     * @param  string|null  $pendingPhoto  an uploaded tmp/inbox photo to keep with this sighting
     */
    public function record(Diecast $diecast, array $data, ?string $pendingPhoto = null): Sighting
    {
        $hasCoordinates = is_numeric($data['latitude'] ?? null) && is_numeric($data['longitude'] ?? null);

        return $diecast->sightings()->create([
            'user_id' => $diecast->user_id,
            'place' => filled($data['place'] ?? null) ? trim($data['place']) : null,
            'latitude' => $hasCoordinates ? $data['latitude'] : null,
            'longitude' => $hasCoordinates ? $data['longitude'] : null,
            'seen_on' => $data['seen_on'] ?? now()->toDateString(),
            'price' => filled($data['price'] ?? null) ? $data['price'] : null,
            'notes' => $data['notes'] ?? null,
            'photo_path' => $this->photos->isPending($pendingPhoto) ? $this->photos->promote($pendingPhoto) : null,
        ]);
    }

    /**
     * True when the form/defaults carry anything worth recording.
     *
     * @param  array<string, mixed>|null  $data
     */
    public function hasDetails(?array $data): bool
    {
        return collect($data ?? [])->only(['place', 'price', 'notes'])->filter(fn ($value) => filled($value))->isNotEmpty();
    }

    public function delete(Sighting $sighting): void
    {
        $this->photos->delete($sighting->photo_path);
        $sighting->delete();
    }

    /**
     * Rules for a sighting submitted with a form (keys prefixed, e.g. "sighting.").
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(string $prefix = ''): array
    {
        return [
            "{$prefix}place" => ['nullable', 'string', 'max:255'],
            "{$prefix}latitude" => ['nullable', 'numeric', 'between:-90,90', "required_with:{$prefix}longitude"],
            "{$prefix}longitude" => ['nullable', 'numeric', 'between:-180,180', "required_with:{$prefix}latitude"],
            "{$prefix}seen_on" => ['nullable', 'date', 'before_or_equal:today'],
            "{$prefix}price" => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            "{$prefix}notes" => ['nullable', 'string', 'max:500'],
        ];
    }
}
