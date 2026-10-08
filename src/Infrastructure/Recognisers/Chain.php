<?php

declare(strict_types=1);

namespace Sluis\Infrastructure\Recognisers;

use Sluis\Application\Ports\Recogniser;
use Sluis\Domain\Spans;

/**
 * Several recognisers as one. What they disagree about is not settled here: every
 * claim is handed on, because which of two overlapping claims should win depends
 * on which kinds the caller wants masked, and that is asked after this.
 */
final readonly class Chain implements Recogniser
{
    /** @var list<Recogniser> */
    private array $recognisers;

    public function __construct(Recogniser ...$recognisers)
    {
        $this->recognisers = array_values($recognisers);
    }

    public function plus(Recogniser ...$more): self
    {
        return new self(...$this->recognisers, ...$more);
    }

    public function recognise(string $text): Spans
    {
        $spans = Spans::none();

        foreach ($this->recognisers as $recogniser) {
            $spans = $spans->merge($recogniser->recognise($text));
        }

        return $spans;
    }
}
