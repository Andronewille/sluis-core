<?php

namespace Sluis;

use Sluis\Application\Anonymise;
use Sluis\Application\Deanonymise;
use Sluis\Application\Ports\Recogniser;
use Sluis\Domain\Masked;
use Sluis\Domain\PiiType;
use Sluis\Domain\Restored;
use Sluis\Domain\Vault;
use Sluis\Infrastructure\Recognisers\Addresses;
use Sluis\Infrastructure\Recognisers\Chain;
use Sluis\Infrastructure\Recognisers\Frames;
use Sluis\Infrastructure\Recognisers\Gazetteer;
use Sluis\Infrastructure\Recognisers\Patterns;
use Sluis\Infrastructure\Recognisers\Places;

/**
 * The composition root, and the whole of the API most callers need:
 *
 *     $sluis = Sluis::nederlands();
 *     $masked = $sluis->mask($mail);            // hand $masked->text to the model
 *     $back = $sluis->unmask($answer, $masked->vault);
 *
 * It is the one class allowed to know both the use cases and the adapters —
 * somebody has to do the wiring, and a caller who has to assemble six
 * recognisers before masking a mail will assemble five.
 */
final readonly class Sluis
{
    public function __construct(
        private Recogniser $recogniser,
        private bool $strict = false,
    ) {}

    /**
     * Everything the core can do without a model: the formats, Dutch addresses,
     * the frame of a letter, the cue in front of a place, and whatever word lists
     * are in `data/`. Add the model on top with `plus()`; it is better at prose
     * and it cannot replace a check digit.
     */
    public static function nederlands(bool $strict = false): self
    {
        $data = dirname(__DIR__).'/data';

        return new self(new Chain(
            new Patterns,
            new Addresses,
            new Frames,
            new Places,
            Gazetteer::fromFile(PiiType::Voornaam, $data.'/voornamen.txt'),
            Gazetteer::fromFile(PiiType::Stad, $data.'/plaatsnamen.txt'),
        ), $strict);
    }

    public static function with(Recogniser $recogniser, bool $strict = false): self
    {
        return new self($recogniser, $strict);
    }

    /** More eyes on the same text; what they disagree about is settled by type. */
    public function plus(Recogniser ...$more): self
    {
        $chain = $this->recogniser instanceof Chain
            ? $this->recogniser->plus(...$more)
            : new Chain($this->recogniser, ...$more);

        return new self($chain, $this->strict);
    }

    public function mask(string $text, ?Vault $vault = null): Masked
    {
        return (new Anonymise($this->recogniser))($text, $vault ?? Vault::empty($this->strict));
    }

    public function unmask(string $text, Vault $vault): Restored
    {
        return (new Deanonymise)($text, $vault);
    }
}
