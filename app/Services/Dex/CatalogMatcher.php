<?php

namespace App\Services\Dex;

use App\Models\CatalogItem;
use App\Models\Diecast;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Finds which dex entry a diecast is: by the numbers printed on the card ("61/250 · 2/10", "MB12",
 * "MGT00512"), the model name, the series and the year — across years, because the year read from
 * a card is often the casting's copyright year rather than the release year.
 */
class CatalogMatcher
{
    /** Link on its own only when this sure… */
    public const AUTO_SCORE = 90;

    /** …and this far ahead of the next entry. */
    public const AUTO_MARGIN = 20;

    /**
     * Best candidates, one per dex entry, best first.
     *
     * @return Collection<int, array{item: CatalogItem, score: int, year_fix: ?int}>
     */
    public function suggest(Diecast $diecast, int $limit = 3): Collection
    {
        $user = $diecast->user;
        $brand = mb_strtolower(trim((string) $diecast->brand));

        if ($brand === '' || ! $user) {
            return collect();
        }

        $catalogIds = $user->catalogs()->whereRaw('lower(brand) = ?', [$brand])->pluck('id');
        $numbers = self::numbers($diecast->collection_number);
        $tokens = self::tokens($diecast->name);
        // The most distinctive words of the name, to find candidates when the numbers don't help.
        $keywords = collect($tokens)->filter(fn (string $t) => mb_strlen($t) >= 3 && ! ctype_digit($t))
            ->sortByDesc(fn (string $t) => mb_strlen($t))->take(2);

        if ($catalogIds->isEmpty() || (! array_filter($numbers) && $keywords->isEmpty())) {
            return collect();
        }

        $candidates = CatalogItem::query()
            ->with('catalog')
            ->whereIn('catalog_id', $catalogIds)
            ->where(function ($query) use ($numbers, $keywords) {
                if ($numbers['main'] !== null) {
                    $query->orWhere('sort', $numbers['main'])->orWhere('number', (string) $numbers['main']);
                }

                if ($numbers['code'] !== null) {
                    $query->orWhere('number', $numbers['code']);
                }

                if ($numbers['series'] !== null) {
                    $query->orWhere('series_number', $numbers['series']);
                }

                foreach ($keywords as $keyword) {
                    $query->orWhere('name', 'like', '%'.$keyword.'%');
                }
            })
            ->limit(500)
            ->get();

        return $candidates
            ->map(fn (CatalogItem $item) => ['item' => $item, 'score' => $this->score($diecast, $item, $numbers, $tokens)])
            ->filter(fn (array $c) => $c['score'] >= 30)
            // Highest score first; on a tie, the main release over its color variants.
            ->sort(fn (array $a, array $b) => [$b['score'], $a['item']->variant] <=> [$a['score'], $b['item']->variant])
            // One suggestion per entry (its best variant).
            ->unique(fn (array $c) => $c['item']->catalog_id.'|'.$c['item']->number)
            ->take($limit)
            ->map(fn (array $c) => [
                ...$c,
                'year_fix' => $c['item']->catalog->year && $c['item']->catalog->year !== $diecast->year
                    ? $c['item']->catalog->year
                    : null,
            ])
            ->values();
    }

    /**
     * Link the diecast when one entry clearly wins; drop an automatic link that no longer holds.
     * Links the user confirmed or rejected are left alone.
     */
    public function autoLink(Diecast $diecast): ?CatalogItem
    {
        if (in_array($diecast->catalog_match, ['confirmed', 'ignored'], true)) {
            return $diecast->catalogItem;
        }

        [$best, $next] = $this->suggest($diecast, 2)->pad(2, null)->all();
        $sure = $best && $best['score'] >= self::AUTO_SCORE
            && (! $next || $best['score'] - $next['score'] >= self::AUTO_MARGIN);

        $item = $sure ? $best['item'] : null;

        if ($diecast->catalog_item_id !== $item?->id) {
            $diecast->forceFill(['catalog_item_id' => $item?->id, 'catalog_match' => $item ? 'auto' : null])->saveQuietly();
        }

        return $item;
    }

    /**
     * Re-run automatic linking over the collection (after a sync). Returns how many are linked.
     */
    public function autoLinkAll(User $user): int
    {
        $linked = 0;

        $user->diecasts()
            ->setEagerLoads([])
            ->whereNotNull('brand')
            ->where(fn ($query) => $query->whereNull('catalog_match')->orWhere('catalog_match', 'auto'))
            ->with('user')
            ->chunkById(100, function ($diecasts) use (&$linked) {
                foreach ($diecasts as $diecast) {
                    $linked += $this->autoLink($diecast) ? 1 : 0;
                }
            });

        return $linked;
    }

    /**
     * A dex entry for the UI.
     *
     * @return array<string, mixed>
     */
    public static function present(CatalogItem $item, ?int $score = null, ?int $yearFix = null): array
    {
        return [
            'item_id' => $item->id,
            'catalog_id' => $item->catalog_id,
            'catalog' => $item->catalog->name,
            'year' => $item->catalog->year,
            'number' => $item->number,
            'name' => $item->name,
            'variant_label' => $item->variant_label,
            'series' => $item->series,
            'series_number' => $item->series_number,
            'flags' => $item->flags ?? (object) [],
            'photo_url' => $item->photo_url,
            'score' => $score,
            'year_fix' => $yearFix,
        ];
    }

    /**
     * Form fields for adding this entry to the collection, written the way the card prints them.
     *
     * @return array<string, mixed>
     */
    public static function prefill(CatalogItem $item): array
    {
        $catalog = $item->catalog;
        $flags = $item->flags ?? [];
        $number = $catalog->source === 'hotwheels_wiki'
            ? $item->sort.'/'.$catalog->items()->max('sort')
            : $item->number;

        return [
            'name' => $item->name,
            'brand' => $catalog->brand,
            'series' => $item->series,
            'collection_number' => $item->series_number ? "{$number} · {$item->series_number}" : $number,
            'year' => $catalog->year,
            'rarity' => match (true) {
                ! empty($flags['sth']) => 'super_treasure_hunt',
                ! empty($flags['th']) => 'treasure_hunt',
                default => 'regular',
            },
            'scale' => in_array($catalog->source, ['hotwheels_wiki', 'matchbox_wiki'], true) ? '1:64' : null,
        ];
    }

    /**
     * Numbers printed on a card: the line number ("61/250", "MB12", "No. 23"), the series number ("2/10")
     * and a product code ("MGT00512").
     *
     * @return array{main: ?int, series: ?string, code: ?string}
     */
    public static function numbers(?string $text): array
    {
        $numbers = ['main' => null, 'series' => null, 'code' => null];
        $text = (string) $text;

        preg_match_all('/(\d{1,3})\s*\/\s*(\d{1,3})/', $text, $pairs, PREG_SET_ORDER);

        foreach ($pairs as [, $number, $of]) {
            // Out of 50+ is the line ("61/250", "12/125"); a small total is the series ("2/10").
            if ((int) $of >= 50) {
                $numbers['main'] ??= (int) $number;
            } else {
                $numbers['series'] ??= ((int) $number).'/'.((int) $of);
            }
        }

        if ($numbers['main'] === null && preg_match('/\b(?:MB|No\.?|#)\s*0*(\d{1,4})\b/i', $text, $match)) {
            $numbers['main'] = (int) $match[1];
        }

        if (preg_match('/\b([A-Z]{2,5}-?\d{3,6})\b/i', $text, $match) && ! preg_match('/^MB\d+$/i', $match[1])) {
            $numbers['code'] = strtoupper($match[1]);
        }

        return $numbers;
    }

    /**
     * Name words for comparison, ignoring years ("'95", "1995") and punctuation.
     *
     * @return list<string>
     */
    public static function tokens(?string $name): array
    {
        $text = mb_strtolower((string) $name);
        $text = preg_replace("/['’`]\d{2}\b/u", ' ', $text);
        $text = preg_replace('/\b(?:19|20)\d{2}\b/', ' ', $text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return array_values(array_unique(array_filter(explode(' ', $text), fn (string $t) => $t !== '')));
    }

    /**
     * Share of words in common (0–1).
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    public static function similarity(array $a, array $b): float
    {
        if (! $a || ! $b) {
            return 0.0;
        }

        return count(array_intersect($a, $b)) / max(count($a), count($b));
    }

    /**
     * @param  array{main: ?int, series: ?string, code: ?string}  $numbers
     * @param  list<string>  $tokens
     */
    private function score(Diecast $diecast, CatalogItem $item, array $numbers, array $tokens): int
    {
        $score = 0;
        $number = strtoupper(ltrim($item->number, '0'));
        $wiki = ! $item->catalog->isCustom();

        if ($numbers['code'] !== null && strtoupper($item->number) === $numbers['code']) {
            $score += 60;
        } elseif ($numbers['main'] !== null && (($wiki && $item->sort === $numbers['main']) || $number === (string) $numbers['main'])) {
            $score += 50;
        }

        if ($numbers['series'] !== null && $item->series_number === $numbers['series']) {
            $score += 15;
        }

        if ($item->series && $diecast->series && self::similarity(self::tokens($item->series), self::tokens($diecast->series)) >= 0.6) {
            $score += 15;
        }

        $score += (int) round(self::similarity($tokens, self::tokens($item->name)) * 40);

        if ($item->catalog->year && $item->catalog->year === $diecast->year) {
            $score += 10;
        }

        // A Treasure Hunt on the card points to the TH variant of that number, and vice versa.
        $flags = $item->flags ?? [];
        $hunt = match ($diecast->rarity) {
            'super_treasure_hunt' => 'sth',
            'treasure_hunt' => 'th',
            default => null,
        };

        if ($hunt && ! empty($flags[$hunt])) {
            $score += 5;
        } elseif (! $hunt && (! empty($flags['th']) || ! empty($flags['sth']))) {
            $score -= 5;
        }

        return $score;
    }
}
