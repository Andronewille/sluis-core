<?php

declare(strict_types=1);

namespace Sluis\Application;

use Sluis\Application\Ports\Recogniser;
use Sluis\Domain\CannotPlace;
use Sluis\Domain\Leaked;
use Sluis\Domain\Masked;
use Sluis\Domain\PiiType;
use Sluis\Domain\Span;
use Sluis\Domain\Spans;
use Sluis\Domain\Vault;

/** The forward half: text in, text with the people taken out and a vault that can put them back. */
final readonly class Anonymise
{
    /**
     * @param  list<PiiType>|null  $only  the kinds to mask; everything that is found, when nothing is said
     */
    public function __construct(
        private Recogniser $recogniser,
        private ?array $only = null,
    ) {}

    public function __invoke(string $text, ?Vault $vault = null): Masked
    {
        $vault ??= Vault::empty();

        $spans = $this->recogniser->recognise($text)->resolved();

        $this->refuseAWrongOffset($text, $spans);

        $spans = $this->everywhere($text, $spans);

        $tokens = [];
        $found = [];

        // Forward, so the numbering follows the reading order: the first person
        // named in the mail is voornaam1mask, which is what makes a masked text
        // still readable to whoever has to check what the model did with it.
        foreach ($spans as $i => $span) {
            if (! $this->wants($span)) {
                continue;
            }

            $tokens[$i] = $vault->mint($span->type, $span->text, fn (string $token) => stripos($text, $token) !== false);
            $found[$span->type->value] = ($found[$span->type->value] ?? 0) + 1;
        }

        // Backwards, so every offset still points at what it pointed at. What the
        // caller chose to leave alone stays in the text and is blanked in the copy
        // the last check reads: a first name inside a street that was left
        // standing was left there on purpose, and is not a leak.
        $masked = $text;
        $checked = $text;
        $taken = [];

        foreach (array_reverse([...$spans], preserve_keys: true) as $i => $span) {
            $masked = isset($tokens[$i]) ? substr_replace($masked, $tokens[$i], $span->start, strlen($span->text)) : $masked;
            $checked = substr_replace($checked, $tokens[$i] ?? ' ', $span->start, strlen($span->text));

            if (isset($tokens[$i])) {
                $taken[] = $span;
            }
        }

        $this->refuseToLeak($checked, new Spans(...$taken));

        return new Masked($masked, $vault, $found);
    }

    /**
     * Asked last, once a value found in one place has been found in all of them
     * and the overlaps are settled. Asked any sooner, a kind that is left alone
     * gives up the words it had outranked: `Jan Steenlaan 4` kept as an address
     * and `Jan` masked out of the middle of it, because a Jan signed the mail.
     */
    private function wants(Span $span): bool
    {
        return $this->only === null || in_array($span->type, $this->only, true);
    }

    /**
     * A span has to point at what it says it points at, and a recogniser is the one
     * thing here that can be wrong about that. The model does not report offsets, so
     * the ONNX adapter works them out, and an offset out by one masks the wrong
     * bytes and leaves the name. Checked at the boundary, where it can still be
     * said whose fault it is.
     */
    private function refuseAWrongOffset(string $text, Spans $spans): void
    {
        foreach ($spans as $span) {
            if (substr($text, $span->start, strlen($span->text)) !== $span->text) {
                throw CannotPlace::misplaced($span->type, $span->by === '' ? 'a recogniser' : $span->by);
            }
        }
    }

    /**
     * A value found once is masked everywhere it occurs, whether or not a rule
     * fired there. The rules are good at the places a name announces itself — the
     * salutation, the sign-off — and blind to the fourth mention halfway down;
     * this is what makes one sighting enough. It costs the odd word that happened
     * to be somebody's name, which is the direction to be wrong in.
     */
    private function everywhere(string $text, Spans $spans): Spans
    {
        $extra = [];

        foreach ($spans as $span) {
            $pattern = '/(?<![\p{L}\p{N}_])'.preg_quote($span->text, '/').'(?![\p{L}\p{N}_])/iu';

            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }

            foreach ($matches[0] as [$found, $at]) {
                if ($at !== $span->start) {
                    $extra[] = new Span($span->type, $at, $found, $span->by.'+elders', $span->confidence);
                }
            }
        }

        return $extra === [] ? $spans : $spans->with(...$extra)->resolved();
    }

    /**
     * The last check before the text leaves: nothing that was taken out is still
     * readable in it. It has caught nothing yet, and the day a rule starts finding
     * the second occurrence but not the third, it is the only thing that will.
     */
    private function refuseToLeak(string $masked, Spans $spans): void
    {
        foreach ($spans as $span) {
            $pattern = '/(?<![\p{L}\p{N}_])'.preg_quote($span->text, '/').'(?![\p{L}\p{N}_])/u';

            if (preg_match($pattern, $masked) === 1) {
                throw new Leaked("A {$span->type->value} is still readable in the masked text.");
            }
        }
    }
}
