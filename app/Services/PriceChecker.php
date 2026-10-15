<?php

namespace App\Services;

use App\Models\Diecast;
use App\Models\PriceCheck;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Reads marketplace screenshots (Tokopedia, Shopee...) with AI and records a price check for a diecast.
 */
class PriceChecker
{
    public const MAX_SCREENSHOTS = 6;

    private const SYSTEM_PROMPT = <<<'PROMPT'
        You help an Indonesian diecast collector estimate market prices. You are given screenshots of marketplace
        search results or product pages (usually Tokopedia, sometimes Shopee, Bukalapak or Facebook Marketplace)
        and a description of the diecast being priced.

        Extract EVERY product listing visible in the screenshots — also the ones that are not this diecast — exactly
        as shown. Never invent listings or guess prices that aren't visible. If the same listing appears in two
        overlapping screenshots, list it once.

        For each listing:
        - title: the product title as shown (it may be cut off).
        - price_idr: the price a buyer pays now, as a plain integer in rupiah ("Rp45.000" → 45000, "Rp1,2jt" → 1200000).
          If a discount is shown, use the discounted price, not the struck-through one. If a price range is shown
          ("Rp35.000 - Rp60.000"), use the lowest price and set is_price_range to true.
        - shop: the shop / seller name if visible, else null. location: the city if visible, else null.
        - sold: how many sold as an integer ("10+ terjual" → 10, "1rb+ terjual" → 1000, "250 terjual" → 250), null if not shown.
        - rating: the rating number if shown, else null. is_ad: true if marked as an ad ("Ad", "Iklan").
        - marketplace: tokopedia, shopee, bukalapak, facebook or other.
        - match — compare with the target diecast:
          "match": the same casting and the same variant, in the same condition (carded vs loose) as the target.
          "different_variant": same casting but another variant — e.g. Super Treasure Hunt vs regular, another color,
            series, year, premium vs mainline. STH and regular prices differ hugely, so be strict.
          "different_condition": same item but loose when the target is carded, or the other way round.
          "bundle": a lot / set / several cars sold together.
          "different_item": a different car or product.
          "unclear": the title and picture don't let you tell.
          A listing title that doesn't mention "loose" is normally carded.
        - reason: a few words in Indonesian explaining the match decision.

        Also give:
        - note: one or two sentences in Indonesian about what you saw (e.g. many loose listings, prices cluster around X).
        - search_hint: better Tokopedia search keywords for this diecast if the results look off-target, else null.
        PROMPT;

    public function __construct(
        private AiVision $ai,
        private PhotoStorage $photos,
        private PriceAnalyzer $analyzer,
    ) {}

    /**
     * @param  array<int, UploadedFile>  $screenshots
     */
    public function check(Diecast $diecast, array $screenshots): PriceCheck
    {
        $paths = array_map(
            fn (UploadedFile $file) => $this->photos->store($file, PhotoStorage::PRICE_CHECK_DIR, PhotoStorage::SCREENSHOT_MAX_EDGE),
            array_slice($screenshots, 0, self::MAX_SCREENSHOTS),
        );

        try {
            $read = $this->ai->extract($paths, self::SYSTEM_PROMPT, $this->instruction($diecast, count($paths)), $this->schema());
        } catch (Throwable $e) {
            foreach ($paths as $path) {
                $this->photos->delete($path);
            }

            throw $e;
        }

        $analysis = $this->analyzer->analyze(Arr::wrap($read['listings'] ?? []));
        $tips = $analysis['tips'];

        if (filled($read['search_hint'] ?? null)) {
            $tips[] = "Coba cari dengan kata kunci: \"{$read['search_hint']}\".";
        }

        return $diecast->priceChecks()->create([
            'user_id' => $diecast->user_id,
            'screenshots' => $paths,
            'listings' => $analysis['listings'],
            'stats' => $analysis['stats'],
            'accuracy' => $analysis['accuracy'],
            'reasons' => $analysis['reasons'],
            'tips' => $tips,
            'ai_note' => $read['note'] ?? null,
        ]);
    }

    /**
     * Use a price check's (or a chosen) price as the diecast's market value.
     */
    public function apply(PriceCheck $check, ?int $value = null): Diecast
    {
        $diecast = $check->diecast;
        $diecast->forceFill([
            'market_value' => $value ?? $check->stats['suggested'],
            'market_value_source' => 'price_check',
            'market_value_updated_at' => now(),
        ])->save();

        $check->update(['applied_at' => now()]);

        return $diecast;
    }

    public function delete(PriceCheck $check): void
    {
        foreach ($check->screenshots as $path) {
            $this->photos->delete($path);
        }

        $check->delete();
    }

    private function instruction(Diecast $diecast, int $count): string
    {
        $describe = collect([
            'Name' => $diecast->name,
            'Brand' => $diecast->brand,
            'Car maker' => $diecast->make,
            'Series' => $diecast->series,
            'Collection number' => $diecast->collection_number,
            'Release year' => $diecast->year,
            'Color' => $diecast->color,
            'Scale' => $diecast->scale,
            'Variant / rarity' => $diecast->rarity,
            'Condition' => $diecast->packaging,
        ])->filter(fn ($value) => filled($value))->map(fn ($value, $label) => "- {$label}: {$value}")->implode("\n");

        return "Target diecast:\n{$describe}\n\nRead all listings in these {$count} screenshot(s).";
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $nullable = fn (array $type) => ['anyOf' => [$type, ['type' => 'null']]];

        $listing = [
            'title' => ['type' => 'string'],
            'price_idr' => ['type' => 'integer'],
            'is_price_range' => ['type' => 'boolean'],
            'shop' => $nullable(['type' => 'string']),
            'location' => $nullable(['type' => 'string']),
            'sold' => $nullable(['type' => 'integer']),
            'rating' => $nullable(['type' => 'number']),
            'is_ad' => ['type' => 'boolean'],
            'marketplace' => ['type' => 'string', 'enum' => ['tokopedia', 'shopee', 'bukalapak', 'facebook', 'other']],
            'match' => ['type' => 'string', 'enum' => ['match', ...array_keys(PriceAnalyzer::MATCH_REASONS)]],
            'reason' => ['type' => 'string'],
        ];

        return [
            'type' => 'object',
            'properties' => [
                'listings' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => $listing,
                        'required' => array_keys($listing),
                        'additionalProperties' => false,
                    ],
                ],
                'note' => $nullable(['type' => 'string']),
                'search_hint' => $nullable(['type' => 'string']),
            ],
            'required' => ['listings', 'note', 'search_hint'],
            'additionalProperties' => false,
        ];
    }
}
