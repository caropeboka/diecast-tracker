<?php

namespace App\Services;

use App\Models\Diecast;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Finds diecasts in a user's collection that look like a scanned one ("do I already have this?").
 */
class CollectionMatcher
{
    /**
     * @param  array<string, mixed>  $fields  scanned fields (name, brand, collection_number, color...)
     * @return Collection<int, Diecast>
     */
    public function find(User $user, array $fields, int $limit = 5): Collection
    {
        $name = $this->normalize($fields['name'] ?? '');

        if ($name === '') {
            return new Collection;
        }

        $brand = $fields['brand'] ?? null;
        $number = $this->normalize($fields['collection_number'] ?? '');

        $scores = $user->diecasts()
            ->setEagerLoads([])
            ->when($brand, fn ($query) => $query->where(fn ($q) => $q->where('brand', $brand)->orWhereNull('brand')))
            ->get(['id', 'name', 'collection_number'])
            ->mapWithKeys(function (Diecast $diecast) use ($name, $number) {
                $score = $this->similarity($name, $this->normalize($diecast->name));

                if ($number !== '' && $number === $this->normalize($diecast->collection_number ?? '')) {
                    $score += 20;
                }

                return [$diecast->id => $score];
            })
            ->filter(fn (float $score) => $score >= 75)
            ->sortDesc()
            ->take($limit);

        if ($scores->isEmpty()) {
            return new Collection;
        }

        return Diecast::query()
            ->whereKey($scores->keys())
            ->withCount('sightings')
            ->withMin('sightings', 'price')
            ->get()
            ->sortByDesc(fn (Diecast $diecast) => $scores[$diecast->id])
            ->values();
    }

    /**
     * 0–100. Containment counts as a strong match ("Datsun 510" vs "'71 Datsun 510").
     */
    private function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0;
        }

        if (str_contains($a, $b) || str_contains($b, $a)) {
            return 90 + 10 * min(strlen($a), strlen($b)) / max(strlen($a), strlen($b));
        }

        similar_text($a, $b, $percent);

        return $percent;
    }

    private function normalize(?string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower((string) $value)) ?? '';
    }
}
