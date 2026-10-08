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
use Sluis\Domain\Unreadable;
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

        if (! mb_check_encoding($text, 'UTF-8')) {
            throw Unreadable::notUtf8();
        }

        $spans = $this->chosen($this->recogniser->recognise($text))->resolved();

        $this->refuseAWrongOffset($text, $spans);

        $spans = $this->everywhere($text, $spans);

        $tokens = [];
        $found = [];

        // Forward, so the numbering follows the reading order: the first person
        // named in the mail is voornaam1mask, which is what makes a masked text
        // still readable to whoever has to check what the model did with it.
        foreach ($spans as $i => $span) {
            $tokens[$i] = $vault->mint($span->type, $span->text, fn (string $token) => stripos($text, $token) !== false);
            $found[$span->type->value] = ($found[$span->type->value] ?? 0) + 1;
        }

        // Backwards, so every offset still points at what it pointed at.
        $masked = $text;

        foreach (array_reverse([...$spans], preserve_keys: true) as $i => $span) {
            $masked = substr_replace($masked, $tokens[$i], $span->start, strlen($span->text));
        }

        $this->refuseToLeak($masked, $spans);

        return new Masked($masked, $vault, $found);
    }

    /**
     * The caller's choice of what to mask, made before anything else: a kind that
     * is left out is as if no rule for it existed. It cannot be made later.
     * Overlaps are settled by rank, so a claim that is left out would first win
     * its overlap and then be dropped, and take with it the claim the caller did
     * want — a telephone number that happens to pass the elfproef, a first name
     * a place cue also matched. Both stayed readable when this was asked last.
     *
     * The price is the other direction, which is the one to be wrong in: with
     * addresses left alone, the first name in `Jan Steenlaan 4` is a first name
     * again and is masked.
     */
    private function chosen(Spans $claims): Spans
    {
        if ($this->only === null) {
            return $claims;
        }

        return new Spans(...array_filter([...$claims], fn (Span $span) => in_array($span->type, $this->only, true)));
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
        // Each value is looked for once, and a place a span already stands is not
        // claimed again. A name that signs forty mails of one thread is forty
        // spans; asked forty times where it occurs, it was sixteen hundred, and
        // a long thread ran out of memory before it was masked.
        $standing = [];
        $asked = [];
        $extra = [];

        foreach ($spans as $span) {
            $standing[$span->type->value.'|'.$span->start.'|'.strlen($span->text)] = true;
        }

        foreach ($spans as $span) {
            if (isset($asked[$span->type->value.'|'.$span->text])) {
                continue;
            }

            $asked[$span->type->value.'|'.$span->text] = true;
            $pattern = '/(?<![\p{L}\p{N}_])'.preg_quote($span->text, '/').'(?![\p{L}\p{N}_])/iu';

            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
                throw Unreadable::text();
            }

            foreach ($matches[0] as [$found, $at]) {
                $place = $span->type->value.'|'.$at.'|'.strlen($found);

                if (! isset($standing[$place])) {
                    $standing[$place] = true;
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
        $checked = [];

        foreach ($spans as $span) {
            // Once for each value, not once for each place it stood.
            if (isset($checked[$span->text])) {
                continue;
            }

            $checked[$span->text] = true;
            $pattern = '/(?<![\p{L}\p{N}_])'.preg_quote($span->text, '/').'(?![\p{L}\p{N}_])/u';

            $standing = preg_match($pattern, $masked);

            if ($standing === false) {
                throw Unreadable::text();
            }

            if ($standing === 1) {
                throw new Leaked("A {$span->type->value} is still readable in the masked text.");
            }
        }
    }
}
