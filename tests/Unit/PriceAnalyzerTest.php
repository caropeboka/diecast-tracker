<?php

namespace Tests\Unit;

use App\Services\PriceAnalyzer;
use PHPUnit\Framework\TestCase;

class PriceAnalyzerTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function listing(int $price, string $match = 'match', array $extra = []): array
    {
        static $n = 0;

        return ['title' => 'Hot Wheels Dodge Dart '.++$n, 'price_idr' => $price, 'shop' => 'Toko '.$n, 'match' => $match, 'sold' => null, ...$extra];
    }

    public function test_median_counts_only_matching_listings()
    {
        $result = (new PriceAnalyzer)->analyze([
            $this->listing(30000),
            $this->listing(40000),
            $this->listing(50000),
            $this->listing(900000, 'different_variant'),
            $this->listing(10000, 'different_condition'),
        ]);

        $this->assertSame(3, $result['stats']['count']);
        $this->assertSame(40000, $result['stats']['median']);
        $this->assertSame(30000, $result['stats']['min']);
        $this->assertSame(50000, $result['stats']['max']);
        $this->assertSame('Varian berbeda', collect($result['listings'])->firstWhere('price_idr', 900000)['excluded_reason']);
    }

    public function test_even_count_median_and_suggested_rounding()
    {
        $result = (new PriceAnalyzer)->analyze([
            $this->listing(35000), $this->listing(36500), $this->listing(38000), $this->listing(40000),
        ]);

        $this->assertSame(37250, $result['stats']['median']);
        $this->assertSame(37500, $result['stats']['suggested']);
    }

    public function test_extreme_prices_are_marked_as_outliers()
    {
        $result = (new PriceAnalyzer)->analyze([
            $this->listing(40000), $this->listing(42000), $this->listing(45000),
            $this->listing(47000), $this->listing(50000), $this->listing(450000),
        ]);

        $outlier = collect($result['listings'])->firstWhere('price_idr', 450000);
        $this->assertSame('outlier', $outlier['status']);
        $this->assertSame(5, $result['stats']['count']);
        $this->assertSame(45000, $result['stats']['median']);
    }

    public function test_duplicates_from_overlapping_screenshots_count_once()
    {
        $same = ['title' => 'Hot Wheels Dodge Dart HW Flames', 'price_idr' => 40000, 'shop' => 'Garasi Kecil', 'match' => 'match'];

        $result = (new PriceAnalyzer)->analyze([$same, $same, $this->listing(45000)]);

        $this->assertCount(2, $result['listings']);
    }

    public function test_many_consistent_sold_listings_give_high_accuracy()
    {
        $listings = array_map(fn ($price) => $this->listing($price, 'match', ['sold' => 25]), [
            40000, 41000, 42000, 42500, 43000, 44000, 45000, 46000,
        ]);

        $result = (new PriceAnalyzer)->analyze($listings);

        $this->assertSame('high', $result['accuracy']);
    }

    public function test_few_scattered_listings_give_low_accuracy_with_tips()
    {
        $result = (new PriceAnalyzer)->analyze([$this->listing(20000), $this->listing(90000)]);

        $this->assertSame('low', $result['accuracy']);
        $this->assertNotEmpty($result['tips']);
    }

    public function test_single_or_no_match_is_insufficient()
    {
        $this->assertSame('insufficient', (new PriceAnalyzer)->analyze([$this->listing(40000)])['accuracy']);

        $none = (new PriceAnalyzer)->analyze([$this->listing(40000, 'different_item')]);
        $this->assertSame('insufficient', $none['accuracy']);
        $this->assertNull($none['stats']);
    }
}
