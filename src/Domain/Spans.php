<?php

declare(strict_types=1);

namespace Sluis\Domain;

use ArrayIterator;
use Countable;
use IteratorAggregate;

/**
 * What the recognisers found, and the one place where they are made to agree.
 *
 * @implements IteratorAggregate<int, Span>
 */
final readonly class Spans implements Countable, IteratorAggregate
{
    /** @var list<Span> */
    private array $spans;

    public function __construct(Span ...$spans)
    {
        $this->spans = array_values($spans);
    }

    public static function none(): self
    {
        return new self;
    }

    public function with(Span ...$more): self
    {
        return new self(...$this->spans, ...$more);
    }

    public function merge(self $other): self
    {
        return new self(...$this->spans, ...$other->spans);
    }

    public function first(): Span
    {
        return $this->spans[0];
    }

    /**
     * One text, one answer. Two rules that claim overlapping words cannot both be
     * right, so the stronger claim wins: the type that outranks the other, then the
     * longer match, then the one that starts first.
     *
     * What the loser still covers on its own is kept, and that part is not a nicety.
     * `Maanstraat 1234 AB` is an address claiming `Maanstraat 1234` and a postcode
     * claiming `1234 AB`; the postcode outranks it, and dropping the address whole
     * left the street name standing in the middle of masked text. Nothing said so,
     * because every rule had fired and every span that survived had been replaced.
     *
     * A remainder is only kept when it still reads as something: two characters or
     * more, and at least one letter. Otherwise a telephone number that lost its
     * digits to a bsn comes back as a span over `0`, and one stray zero in the mail
     * becomes a mask everywhere.
     */
    public function resolved(): self
    {
        $spans = $this->spans;

        usort($spans, fn (Span $a, Span $b) => [$b->type->precedence(), strlen($b->text), $a->start]
            <=> [$a->type->precedence(), strlen($a->text), $b->start]);

        $kept = [];
        $rejected = [];

        foreach ($spans as $span) {
            $this->clashes($span, $kept) ? $rejected[] = $span : $kept[] = $span;
        }

        foreach ($rejected as $span) {
            $remainder = $this->whatIsLeftOf($span, $kept);

            if ($remainder !== null && ! $this->clashes($remainder, $kept)) {
                $kept[] = $remainder;
            }
        }

        usort($kept, fn (Span $a, Span $b) => $a->start <=> $b->start);

        return new self(...$kept);
    }

    /** @param list<Span> $kept */
    private function clashes(Span $span, array $kept): bool
    {
        foreach ($kept as $already) {
            if ($span->overlaps($already)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The longest run of a rejected span that nothing else covers, trimmed of the
     * whitespace and punctuation the cut left behind.
     *
     * @param  list<Span>  $kept
     */
    private function whatIsLeftOf(Span $span, array $kept): ?Span
    {
        $covered = array_fill(0, max(1, strlen($span->text)), false);

        foreach ($kept as $already) {
            for ($at = max($span->start, $already->start); $at < min($span->end(), $already->end()); $at++) {
                $covered[$at - $span->start] = true;
            }
        }

        [$from, $length, $bestFrom, $bestLength] = [null, 0, null, 0];

        foreach ($covered as $at => $isCovered) {
            if ($isCovered) {
                [$from, $length] = [null, 0];

                continue;
            }

            $from ??= $at;
            $length++;

            if ($length > $bestLength) {
                [$bestFrom, $bestLength] = [$from, $length];
            }
        }

        if ($bestFrom === null || $bestLength === strlen($span->text)) {
            return null;
        }

        $piece = substr($span->text, $bestFrom, $bestLength);
        $trimmed = trim($piece, " \t\r\n,.;:!?-–—()[]{}<>/\\'\"");

        if (mb_strlen($trimmed) < 2 || preg_match('/\p{L}/u', $trimmed) !== 1) {
            return null;
        }

        $shift = strpos($piece, $trimmed);

        return new Span(
            $span->type,
            $span->start + $bestFrom + ($shift === false ? 0 : $shift),
            $trimmed,
            $span->found.'+rest',
            $span->confidence,
        );
    }

    /** @return ArrayIterator<int, Span> */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->spans);
    }

    public function count(): int
    {
        return count($this->spans);
    }
}
