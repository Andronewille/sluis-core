<?php

declare(strict_types=1);

namespace Sluis\Infrastructure\Recognisers;

use Sluis\Application\Ports\Recogniser;
use Sluis\Domain\PiiType;
use Sluis\Domain\Span;
use Sluis\Domain\Spans;

/**
 * A recogniser, and of what it finds only the kinds the caller wants masked.
 *
 * The choosing happens after the overlaps are settled, not before. `Jan
 * Steenlaan 4` is an address, and a caller who leaves addresses alone wants the
 * street left alone: choosing first would let the first name through that the
 * address had outranked, and put a mask in the middle of a street.
 */
final readonly class Only implements Recogniser
{
    /** @param list<PiiType> $types */
    public function __construct(
        private Recogniser $recogniser,
        private array $types,
    ) {}

    public function recognise(string $text): Spans
    {
        $spans = $this->recogniser->recognise($text)->resolved();

        return new Spans(...array_filter(
            iterator_to_array($spans, preserve_keys: false),
            fn (Span $span) => in_array($span->type, $this->types, true),
        ));
    }
}
