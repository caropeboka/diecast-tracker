<?php

namespace App\Services;

/**
 * Turns listings read from marketplace screenshots into a market price: which listings count, the median and
 * spread, and how far the result can be trusted. Pure arithmetic — no AI here, so the numbers are exact.
 */
class PriceAnalyzer
{
    /** Why a listing was left out, in the words shown to the user. */
    public const MATCH_REASONS = [
        'different_variant' => 'Varian berbeda',
        'different_condition' => 'Kondisi berbeda',
        'bundle' => 'Paket / borongan',
        'different_item' => 'Barang lain',
        'unclear' => 'Tidak jelas',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $listings  as read by the AI (price_idr, match, sold, ...)
     * @return array{listings: array<int, array<string, mixed>>, stats: array<string, int|float>|null, accuracy: string, reasons: array<int, string>, tips: array<int, string>}
     */
    public function analyze(array $listings): array
    {
        $listings = $this->dedupe($listings);

        foreach ($listings as &$listing) {
            $usable = ($listing['match'] ?? null) === 'match' && (int) ($listing['price_idr'] ?? 0) > 0;
            $listing['status'] = $usable ? 'included' : 'excluded';
            $listing['excluded_reason'] = $usable ? null : (self::MATCH_REASONS[$listing['match'] ?? 'unclear'] ?? 'Tidak jelas');
        }
        unset($listing);

        $this->markOutliers($listings);

        $prices = collect($listings)->where('status', 'included')->pluck('price_idr')->map(fn ($p) => (int) $p)->sort()->values()->all();
        $stats = $prices === [] ? null : $this->stats($prices);

        [$accuracy, $reasons, $tips] = $this->judge($listings, $stats);

        // Included first (cheapest first), then outliers, then the rest.
        $order = ['included' => 0, 'outlier' => 1, 'excluded' => 2];
        usort($listings, fn ($a, $b) => [$order[$a['status']], (int) $a['price_idr']] <=> [$order[$b['status']], (int) $b['price_idr']]);

        return compact('listings', 'stats', 'accuracy', 'reasons', 'tips');
    }

    /**
     * Overlapping screenshots (scrolling) show the same listing twice.
     *
     * @param  array<int, array<string, mixed>>  $listings
     * @return array<int, array<string, mixed>>
     */
    private function dedupe(array $listings): array
    {
        return collect($listings)
            ->filter(fn ($listing) => is_array($listing) && filled($listing['title'] ?? null))
            ->unique(fn (array $listing) => implode('|', [
                mb_substr(mb_strtolower(preg_replace('/\s+/', ' ', (string) $listing['title'])), 0, 40),
                mb_strtolower((string) ($listing['shop'] ?? '')),
                (int) ($listing['price_idr'] ?? 0),
            ]))
            ->values()
            ->all();
    }

    /**
     * Tukey fences: with enough listings, prices far outside the middle half are typos, wrong items or wishful sellers.
     *
     * @param  array<int, array<string, mixed>>  $listings
     */
    private function markOutliers(array &$listings): void
    {
        $prices = collect($listings)->where('status', 'included')->pluck('price_idr')->map(fn ($p) => (int) $p)->sort()->values()->all();

        if (count($prices) < 5) {
            return;
        }

        $q1 = $this->percentile($prices, 25);
        $q3 = $this->percentile($prices, 75);
        $fence = 1.5 * ($q3 - $q1);

        foreach ($listings as &$listing) {
            $price = (int) $listing['price_idr'];

            if ($listing['status'] === 'included' && ($price < $q1 - $fence || $price > $q3 + $fence)) {
                $listing['status'] = 'outlier';
                $listing['excluded_reason'] = $price > $q3 ? 'Harga jauh di atas yang lain' : 'Harga jauh di bawah yang lain';
            }
        }
    }

    /**
     * @param  array<int, int>  $prices  sorted ascending
     * @return array<string, int|float>
     */
    private function stats(array $prices): array
    {
        $median = $this->percentile($prices, 50);
        $q1 = $this->percentile($prices, 25);
        $q3 = $this->percentile($prices, 75);

        return [
            'count' => count($prices),
            'min' => $prices[0],
            'q1' => (int) round($q1),
            'median' => (int) round($median),
            // What we suggest saving: the median rounded to a tidy Rp 500.
            'suggested' => (int) (round($median / 500) * 500),
            'q3' => (int) round($q3),
            'max' => end($prices),
            'spread' => $median > 0 ? round(($q3 - $q1) / $median, 2) : 0,
        ];
    }

    /**
     * @param  array<int, int>  $sorted
     */
    private function percentile(array $sorted, float $percent): float
    {
        $index = ($percent / 100) * (count($sorted) - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        return $sorted[$lower] + ($sorted[$upper] - $sorted[$lower]) * ($index - $lower);
    }

    /**
     * Score how much to trust the median, and say why — in plain Indonesian.
     *
     * @param  array<int, array<string, mixed>>  $listings
     * @param  array<string, int|float>|null  $stats
     * @return array{0: string, 1: array<int, string>, 2: array<int, string>}
     */
    private function judge(array $listings, ?array $stats): array
    {
        $count = $stats['count'] ?? 0;
        $reasons = [];
        $tips = [];

        $matches = collect($listings)->groupBy('match')->map->count();
        $otherCondition = $matches['different_condition'] ?? 0;
        $unclear = $matches['unclear'] ?? 0;
        $sold = collect($listings)->where('status', 'included')->filter(fn ($l) => (int) ($l['sold'] ?? 0) > 0)->count();

        if ($count < 2) {
            $reasons[] = $count === 1
                ? 'Hanya 1 listing yang cocok — belum bisa disebut harga pasaran.'
                : 'Tidak ada listing yang cocok dengan diecast ini.';
            $tips[] = 'Coba kata kunci lain (misalnya tanpa nomor seri), lalu screenshot lagi.';

            return ['insufficient', $reasons, $tips];
        }

        $score = match (true) {
            $count >= 8 => 3,
            $count >= 5 => 2,
            $count >= 3 => 1,
            default => 0,
        };
        $reasons[] = "{$count} listing cocok dihitung".($score >= 2 ? ' — cukup banyak.' : ' — masih sedikit.');

        $spreadPercent = (int) round($stats['spread'] * 100);

        if ($stats['spread'] <= 0.25) {
            $score += 2;
            $reasons[] = "Harga cukup seragam (selisih tengah {$spreadPercent}% dari median).";
        } elseif ($stats['spread'] <= 0.5) {
            $score += 1;
            $reasons[] = "Harga agak bervariasi (selisih tengah {$spreadPercent}% dari median).";
        } else {
            $reasons[] = "Harga sangat bervariasi (selisih tengah {$spreadPercent}% dari median).";
            $tips[] = 'Harga sangat beragam — pastikan varian dan kondisinya sama (misalnya STH vs biasa, carded vs loose).';
        }

        if ($sold >= 3) {
            $score += 1;
            $reasons[] = "{$sold} listing menunjukkan jumlah terjual — ada bukti harga yang laku.";
        } else {
            $tips[] = 'Urutkan hasil pencarian berdasarkan "Terlaris" supaya terlihat harga yang benar-benar laku.';
        }

        if ($unclear > 0 && $unclear / max(1, count($listings)) > 0.4) {
            $score -= 1;
            $reasons[] = 'Banyak listing yang tidak jelas cocok atau tidak.';
        }

        if ($count < 5) {
            $tips[] = 'Tambah screenshot (scroll ke bawah) supaya lebih banyak listing terbaca.';
        }

        if ($otherCondition >= 3) {
            $tips[] = 'Banyak listing beda kondisi — tambahkan kata "carded" atau "loose" di pencarian.';
        }

        $accuracy = match (true) {
            $score >= 5 => 'high',
            $score >= 3 => 'medium',
            default => 'low',
        };

        return [$accuracy, $reasons, $tips];
    }
}
