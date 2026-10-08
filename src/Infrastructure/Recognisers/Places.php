<?php

declare(strict_types=1);

namespace Sluis\Infrastructure\Recognisers;

use Sluis\Application\Ports\Recogniser;
use Sluis\Domain\PiiType;
use Sluis\Domain\Span;
use Sluis\Domain\Spans;

/**
 * Where something is, read from the word in front of it rather than from a list
 * of towns. `in Haasterdam` is a place even though Haasterdam is not one: a list
 * can only ever hold the towns that exist, and the town in a mail is sometimes a
 * village, a district, a misspelling or a place in another country.
 *
 * This is the shallowest rule in Sluis and the one a model most clearly beats. It
 * is here because something has to catch the town when there is no model loaded,
 * and being wrong by masking a word is better than being wrong by sending one.
 */
final readonly class Places implements Recogniser
{
    private const CUES = 'in|te|uit|naar|vanuit|richting|vanaf|nabij|bij';

    /** Capitalised words that follow a cue and are not places. */
    private const NOT_PLACES = [
        'januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus',
        'september', 'oktober', 'november', 'december',
        'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag', 'zondag',
        'nederland', 'belgië', 'belgie', 'duitsland', 'frankrijk', 'engeland', 'spanje',
        'italië', 'italie', 'europa', 'nederlands', 'bijlage', 'cc', 'pdf',
        // Words that can stand where the town does, once lower case is allowed.
        'bij', 'aan', 'naar', 'met', 'voor', 'van', 'het', 'een', 'ons', 'onze', 'zijn', 'haar',
    ];

    /** A Dutch address writes the town straight after the postcode, and nothing else does. */
    private const AFTER_A_POSTCODE = '/(?<![\p{L}\p{N}])[1-9]\d{3}\s?[A-Z]{2}[ \t]+(NAME)(?![\p{L}\p{N}])/u';

    private const AFTER_A_CUE = '/(?<![\p{L}\p{N}])(?:CUES)\s+(NAME)(?![\p{L}\p{N}])/u';

    private const PLACE = '\p{Lu}[\p{Ll}\-\']{2,}(?:\s(?:aan|den|de|op)\s\p{L}[\p{L}\-\']+)*';

    /** After a postcode the context carries the claim, so a town typed in lower case still counts. */
    private const PLACE_ANY_CASE = '\p{L}[\p{Ll}\-\']{2,}(?:\s(?:aan|den|de|op)\s\p{L}[\p{L}\-\']+)*';

    public function recognise(string $text): Spans
    {
        $spans = Spans::none();

        $patterns = [
            'plaatscue' => str_replace(['CUES', 'NAME'], [self::CUES, self::PLACE], self::AFTER_A_CUE),
            'na postcode' => str_replace('NAME', self::PLACE_ANY_CASE, self::AFTER_A_POSTCODE),
        ];

        foreach ($patterns as $found => $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
                continue;
            }

            foreach ($matches as $match) {
                [$place, $at] = $match[1];

                if (in_array(mb_strtolower($place), self::NOT_PLACES, true)) {
                    continue;
                }

                $spans = $spans->with(new Span(PiiType::Stad, $at, $place, $found, confidence: 0.6));
            }
        }

        return $spans->resolved();
    }
}
