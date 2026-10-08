<?php

declare(strict_types=1);

namespace Sluis\Domain;

/**
 * A piece of the text that has to go, where it is and what it is. Offsets are
 * byte offsets, which is what `preg_match` hands back and what `substr_replace`
 * takes: with `/u` on every pattern they always land on a character boundary, and
 * mixing the two kinds of offset is the bug that cuts a name in half.
 *
 * `by` names the rule that found it, for an error that has to say whose span
 * was wrong; it is never the words themselves.
 */
final readonly class Span
{
    public function __construct(
        public PiiType $type,
        public int $start,
        public string $text,
        public string $by = '',
        public float $confidence = 1.0,
    ) {}

    public function end(): int
    {
        return $this->start + strlen($this->text);
    }

    public function overlaps(self $other): bool
    {
        return $this->start < $other->end() && $other->start < $this->end();
    }

    public function isSameAs(self $other): bool
    {
        return $this->start === $other->start && $this->end() === $other->end();
    }
}
