<?php

declare(strict_types=1);

namespace Sluis\Infrastructure\Recognisers;

use Sluis\Application\Ports\Recogniser;
use Sluis\Domain\PiiType;
use Sluis\Domain\Span;
use Sluis\Domain\Spans;
use Sluis\Domain\Unreadable;

/**
 * A Dutch street says what it is in its own last syllable. `Maanstraat`,
 * `Kerkweg`, `Waterlooplein`, `Oudegracht` — the suffix is the recogniser, and it
 * needs no list of streets and no model, which is why an address in a Dutch mail
 * is the one piece of prose the core reads as reliably as a format.
 *
 * The article in front is left alone: `aan de adres1mask` still reads as Dutch,
 * where swallowing `de` leaves the model a sentence it has to repair.
 */
final readonly class Addresses implements Recogniser
{
    /**
     * Suffixes that are a street and hardly ever a word. `dam` is not here —
     * Amsterdam — and neither are `veld`, `berg` or `bos`, which are towns as
     * often as streets and ordinary words more often than either.
     */
    private const SUFFIXES = [
        'straat', 'laan', 'weg', 'kade', 'plein', 'dijk', 'gracht', 'hof', 'pad', 'singel',
        'baan', 'dreef', 'park', 'steeg', 'boulevard', 'erf', 'plantsoen', 'markt', 'wal',
        'kanaal', 'tunnel', 'viaduct', 'brug', 'sloot', 'straatje', 'hofje', 'kai',
    ];

    /**
     * A capitalised word, or a run of them held together by a Dutch tussenvoegsel,
     * optionally followed by a house number. The number may carry a letter glued
     * to it (`12a`) or a toevoeging after a space (`12 bis`), and nothing else: a
     * greedy number rule reads `Maanstraat 123 in` as a house number ending in
     * `i`, which is how a masked address takes the next word with it.
     */
    private const PATTERN = '/(?<![\p{L}\p{N}])'
        .'(\p{Lu}[\p{L}\-\']*(?:\s(?:van|de|der|den|op|ten|ter|aan|het)\s\p{Lu}[\p{L}\-\']*|\s\p{Lu}[\p{L}\-\']*)*)'
        .'(?:\s(\d+[a-zA-Z]?(?:\s(?:bis|hs|hoog|zw|rd|BG|bg))?)(?![\p{L}\p{N}]))?/u';

    public function recognise(string $text): Spans
    {
        if (preg_match_all(self::PATTERN, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
            throw Unreadable::text();
        }

        $spans = Spans::none();

        foreach ($matches as $match) {
            [$whole, $at] = $match[0];
            [$name] = $match[1];

            if (! $this->endsInAStreet($name)) {
                continue;
            }

            $spans = $spans->with(new Span(PiiType::Adres, $at, $whole, 'adres'));
        }

        return $spans;
    }

    private function endsInAStreet(string $name): bool
    {
        $words = preg_split('/\s+/u', mb_strtolower($name)) ?: [];
        $last = (string) end($words);

        foreach (self::SUFFIXES as $suffix) {
            if (str_ends_with($last, $suffix) && mb_strlen($last) > mb_strlen($suffix)) {
                return true;
            }
        }

        return false;
    }
}
