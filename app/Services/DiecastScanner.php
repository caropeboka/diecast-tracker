<?php

namespace App\Services;

use App\Models\Diecast;

/**
 * Identifies a diecast from photos of it and returns the form fields it can fill in.
 */
class DiecastScanner
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are an expert diecast car collector who identifies model cars from photos for a collection tracker.

        Read everything visible: the car itself, the blister card or box, printed text, logos, numbers and stickers.
        Fill in only what you can see or confidently recognize. Use null for anything you cannot determine. A wrong
        value is worse than an empty one, because the collector will trust what you fill in.

        Field guidance:
        - name: the casting name exactly as printed on the package (e.g. "'71 Datsun 510", "Nissan Skyline GT-R (BNR34)").
          For a loose car, use the most specific name you recognize.
        - brand: the diecast maker (Hot Wheels, Matchbox, Tomica, Mini GT, Inno64, Tarmac Works, Pop Race, Kaido House,
          Majorette, Greenlight, Maisto, Johnny Lightning, M2 Machines, Auto World, Bburago, etc.).
        - make: the real-world car manufacturer (Nissan, Toyota, Porsche, ...).
        - series: the line or sub-series printed on the card (e.g. "HW J-Imports", "Car Culture: Modern Classics", "Boulevard").
        - collection_number: numbers as printed, e.g. "123/250" or "3/10"; for Tomica use "No. 23"; for Mini GT "MGT00512".
          If both a mainline number and a series number are visible, give both separated by " · ".
        - year: the release year from the card's copyright/date code if visible. Not the real car's model year.
        - scale: e.g. "1:64". Hot Wheels, Matchbox, Mini GT, Inno64 and Tarmac Works are 1:64 unless the package says otherwise.
        - color: main body color in English, short (e.g. "Metallic Blue", "White with red stripes").
        - rarity: "treasure_hunt" for a Hot Wheels mainline with the low-production circle-flame logo on the card or car;
          "super_treasure_hunt" for Spectraflame paint + Real Riders rubber tires + "TH" tampo / gold flame on the card;
          "chase" for a chase piece (Mini GT/Matchbox/Greenlight chase stickers, green machines, raw/zamac chase);
          "limited" for numbered limited runs; "exclusive" for store/event exclusives (e.g. RLC, convention, Target red);
          otherwise "regular".
        - packaging: "carded" (blister card), "boxed" (window box / box), or "loose".
        - condition: judge the packaging and car: "mint" (perfect), "near_mint" (tiny flaws), "good" (visible wear,
          soft corners, small blister dents), "fair" (creases, cracked blister), "damaged". null if you can't tell.
        - notes: one or two short sentences in Indonesian about notable details (variations, tampo, wheel type, card
          damage). null if nothing notable.
        - confidence: your overall confidence in the identification.
        PROMPT;

    /** Photos sent per scan; front + back of card + loose car is plenty. */
    public const MAX_PHOTOS = 4;

    public function __construct(private AiVision $ai) {}

    public function isEnabled(): bool
    {
        return $this->ai->isEnabled();
    }

    /**
     * Identify a diecast from one or more photos of it (e.g. front and back of the card).
     *
     * @param  array<int, string>  $photoPaths
     * @return array<string, mixed>
     */
    public function scan(array $photoPaths): array
    {
        $photoPaths = array_slice(array_values($photoPaths), 0, self::MAX_PHOTOS);

        return $this->clean($this->ai->extract($photoPaths, self::SYSTEM_PROMPT, $this->instruction(count($photoPaths)), $this->schema()));
    }

    private function instruction(int $photoCount): string
    {
        return $photoCount > 1
            ? "These {$photoCount} photos all show the same diecast (for example the front and back of the card, or the car out of the package). Combine what you can read from all of them, identify it and fill in the fields."
            : 'Identify this diecast and fill in the fields.';
    }

    /**
     * Drop values outside the allowed options so the form never receives junk.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clean(array $data): array
    {
        $fields = array_intersect_key($data, array_flip([
            'name', 'brand', 'make', 'series', 'collection_number', 'year', 'scale',
            'color', 'rarity', 'packaging', 'condition', 'notes', 'confidence',
        ]));

        foreach (['rarity' => Diecast::RARITIES, 'packaging' => Diecast::PACKAGINGS, 'condition' => Diecast::CONDITIONS] as $key => $allowed) {
            if (isset($fields[$key]) && ! in_array($fields[$key], $allowed, true)) {
                $fields[$key] = null;
            }
        }

        if (isset($fields['year']) && ($fields['year'] < 1950 || $fields['year'] > (int) date('Y') + 1)) {
            $fields['year'] = null;
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $nullable = fn (array $type) => ['anyOf' => [$type, ['type' => 'null']]];
        $string = $nullable(['type' => 'string']);

        $properties = [
            'name' => $string,
            'brand' => $string,
            'make' => $string,
            'series' => $string,
            'collection_number' => $string,
            'year' => $nullable(['type' => 'integer']),
            'scale' => $string,
            'color' => $string,
            'rarity' => ['type' => 'string', 'enum' => Diecast::RARITIES],
            'packaging' => ['type' => 'string', 'enum' => Diecast::PACKAGINGS],
            'condition' => $nullable(['type' => 'string', 'enum' => Diecast::CONDITIONS]),
            'notes' => $string,
            'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
        ];

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }
}
