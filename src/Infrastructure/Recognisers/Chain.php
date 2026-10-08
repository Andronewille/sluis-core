<?php

declare(strict_types=1);

namespace Sluis\Infrastructure\Recognisers;

use Sluis\Application\Ports\Recogniser;
use Sluis\Domain\Spans;

/** Several recognisers as one, with their disagreements settled once at the end. */
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

        return $spans->resolved();
    }
}
