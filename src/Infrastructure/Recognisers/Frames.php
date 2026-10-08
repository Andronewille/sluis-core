<?php

declare(strict_types=1);

namespace Sluis\Infrastructure\Recognisers;

use Sluis\Application\Ports\Recogniser;
use Sluis\Domain\PiiType;
use Sluis\Domain\Span;
use Sluis\Domain\Spans;

/**
 * A letter gives its people away by its own shape. The word after `Hey` is a
 * person; so is the word under `Met vriendelijke groet,`. It holds for a name no
 * list has ever heard of, in a language no model was trained on, at no cost — and
 * in a mailbox it is where the names almost always are.
 *
 * What the gap between the sign-off and the name is allowed to be decides whether
 * this rule works at all. A mail out of a mailbox has `\r\n` line endings and a
 * blank line under the sign-off, and a rule that allowed one `\n` and no `\r`
 * found the name in a test fixture and in nothing else. It is two newlines' worth
 * of whitespace now, and not more: past that, the next paragraph is not a
 * signature.
 *
 * After `heer` or `mevrouw` the name is a surname; a name of several words is a
 * full name; anything else is a first name. The distinction matters because it is
 * the label the model reads in `voornaam1mask`.
 */
final readonly class Frames implements Recogniser
{
    /**
     * A name is words on one line. The separator is a space and not `\s`, which was
     * the first version: `\s` crosses a newline, so `Bob de Vries` followed by the
     * company on the next line was masked as one eleven-character surname, and a
     * signature of four capitalised lines would have gone the same way. The mask
     * would have been reversible and the text would have lost its shape.
     */
    private const NAME = '\p{Lu}[\p{L}\-\']+(?:[ \t](?:van|de|der|den|ten|ter|op|het)(?=[ \t]))*(?:[ \t]\p{Lu}[\p{L}\-\']+)*';

    /** Spaces, carriage returns, and at most two line breaks. */
    private const GAP = '[ \t\r]*(?:\n[ \t\r]*){0,2}';

    private const SALUTATIONS = 'Hey|Hoi|Hallo|Hi|Dag|Beste|Lieve|Geachte';

    /** Longest first, or `Met` matches and `Met vriendelijke groet` never gets its turn. */
    private const VALEDICTIONS = 'Met vriendelijke groet|Met hartelijke groet|Vriendelijke groeten|Vriendelijke groet'
        .'|Hartelijke groet|Hoogachtend|Groetjes|Groeten|Groet|Mvg|MVG|mvg|Gr';

    /**
     * Words that stand where a name would and are not one. Without this the gap
     * above reaches into the next sentence and `Met vriendelijke groet,` followed
     * by a blank line and `De monteur komt morgen` masks `De` — and then, because a
     * value found once is masked everywhere, every `De` in the mail.
     */
    private const NOT_NAMES = [
        'de', 'het', 'een', 'en', 'of', 'ik', 'wij', 'we', 'u', 'uw', 'je', 'jij', 'dit', 'dat', 'die',
        'als', 'op', 'in', 'aan', 'voor', 'met', 'tot', 'graag', 'alvast', 'bedankt', 'dank', 'hierbij',
        'nogmaals', 'succes', 'fijne', 'prettige', 'groeten', 'groet', 'ps', 'bijlage', 'verzonden',
        'sent', 'from', 'klant', 'heer', 'mevrouw', 'meneer', 'dames', 'heren', 'allen', 'team',
        'collega', 'collegas', 'mensen', 'iedereen', 'allemaal',
    ];

    public function recognise(string $text): Spans
    {
        $spans = Spans::none();

        // A mail is addressed to everyone it is addressed to: `Hoi Sietske en
        // Bouwmeester,`. The names are captured as one run and taken apart after,
        // because requiring the comma straight after a single name made the whole
        // rule miss — and then it masked neither of the two people.
        $salutation = '/(?:^|\n|(?<=[.!?]\s))[ \t\r]*(?:'.self::SALUTATIONS.')'
            .'(\s+(?:heer|mevrouw|mevr\.?|dhr\.?|mw\.?|meneer))?\s+'
            .'('.self::NAME.'(?:[ \t]*(?:,|&|\ben\b)[ \t]*'.self::NAME.'){0,3})(?=[,;:!?.\r\n]|\s*$)/u';

        if (preg_match_all($salutation, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                [$run, $from] = $match[2];
                $formal = trim($match[1][0]) !== '';

                foreach ($this->namesIn($run) as $at => $name) {
                    if ($this->isAName($name)) {
                        $spans = $spans->with(new Span($this->kind($name, $formal), $from + $at, $name, 'aanhef'));
                    }
                }
            }
        }

        $valediction = '/(?:'.self::VALEDICTIONS.')[ \t\r]*[,.]?'.self::GAP.'('.self::NAME.')(?=[\s,.;:!?]|$)/u';

        if (preg_match_all($valediction, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                [$name, $at] = $match[1];

                if (! $this->isAName($name)) {
                    continue;
                }

                $spans = $spans->with(new Span($this->kind($name, formal: false), $at, $name, 'afsluiting'));
            }
        }

        return $spans->resolved();
    }

    /**
     * The names out of a run of them, each with its offset inside the run.
     *
     * @return array<int, string>
     */
    private function namesIn(string $run): array
    {
        if (preg_match_all('/'.self::NAME.'/u', $run, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $names = [];

        foreach ($matches[0] as [$name, $at]) {
            if (! in_array(mb_strtolower($name), ['en'], true)) {
                $names[$at] = $name;
            }
        }

        return $names;
    }

    /**
     * One word that is an ordinary word is not a name. More than one is: `De Vries`
     * after `Geachte heer` opens with a tussenvoegsel and is still somebody, where
     * `De` on its own is the start of the next sentence.
     */
    private function isAName(string $name): bool
    {
        $words = preg_split('/\s+/u', trim($name)) ?: [];

        if (count($words) > 1) {
            return true;
        }

        return ! in_array(mb_strtolower((string) ($words[0] ?? '')), self::NOT_NAMES, true);
    }

    private function kind(string $name, bool $formal): PiiType
    {
        if ($formal) {
            return PiiType::Achternaam;
        }

        return str_contains(trim($name), ' ') ? PiiType::Naam : PiiType::Voornaam;
    }
}
