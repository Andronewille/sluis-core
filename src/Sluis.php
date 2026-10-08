<?php

declare(strict_types=1);

namespace Sluis;

use InvalidArgumentException;
use NoDiscard;
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
    /** @var list<PiiType> what is masked when it is found; everything, until the caller says otherwise */
    private array $types;

    public function __construct(private Recogniser $recogniser)
    {
        self::refuseWhatIsNoLongerTaken(func_num_args() - 1);

        $this->types = PiiType::cases();
    }

    /**
     * Everything the core can do without a model: the formats, Dutch addresses,
     * the frame of a letter, the cue in front of a place, and whatever word lists
     * are in `data/`. Add the model on top with `plus()`; it is better at prose
     * and it cannot replace a check digit.
     */
    public static function nederlands(): self
    {
        self::refuseWhatIsNoLongerTaken(func_num_args());

        $data = dirname(__DIR__).'/data';

        return new self(new Chain(
            new Patterns,
            new Addresses,
            new Frames,
            new Places,
            Gazetteer::fromFile(PiiType::Voornaam, $data.'/voornamen.txt'),
            Gazetteer::fromFile(PiiType::Stad, $data.'/plaatsnamen.txt'),
        ));
    }

    /** More eyes on the same text; what they disagree about is settled by type. */
    public function plus(Recogniser ...$more): self
    {
        $chain = $this->recogniser instanceof Chain
            ? $this->recogniser->plus(...$more)
            : new Chain($this->recogniser, ...$more);

        return clone ($this, ['recogniser' => $chain]);
    }

    /**
     * Mask these kinds and leave the rest of what is found standing. What is
     * sensitive enough to take out is the caller's decision; this is where it is
     * made, whatever recognisers are added before or after.
     */
    public function only(PiiType $type, PiiType ...$more): self
    {
        return $this->masking(array_filter($this->types, fn (PiiType $kept) => in_array($kept, [$type, ...$more], true)));
    }

    /** Mask everything that is found except these kinds. */
    public function without(PiiType $type, PiiType ...$more): self
    {
        return $this->masking(array_filter($this->types, fn (PiiType $kept) => ! in_array($kept, [$type, ...$more], true)));
    }

    /**
     * Hand in the vault of an earlier mail and one person stays on one token across
     * both. How spellings are grouped is the vault's to say and nobody else's:
     * `Vault::empty(strict: true)` when the text has to come back byte for byte.
     */
    #[NoDiscard('the masked text and the vault that puts the people back are in what mask() returns')]
    public function mask(string $text, ?Vault $vault = null): Masked
    {
        return (new Anonymise($this->recogniser, $this->types))($text, $vault ?? Vault::empty());
    }

    #[NoDiscard('what unmask() returns says which masks could not be put back')]
    public function unmask(string $text, Vault $vault): Restored
    {
        return (new Deanonymise)($text, $vault);
    }

    /**
     * A Sluis that masks nothing would report success on every mail it let
     * through, so narrowing down to nothing is refused where it happens.
     *
     * @param  array<PiiType>  $types
     */
    private function masking(array $types): self
    {
        if ($types === []) {
            throw new InvalidArgumentException('Nothing is left to mask: every kind Sluis finds was excluded.');
        }

        return clone ($this, ['types' => array_values($types)]);
    }

    /**
     * 0.1 took `strict` here, and PHP passes an argument a function no longer
     * declares without a word. A caller who still writes `nederlands(true)` asked
     * for text that comes back byte for byte and would get the other kind.
     */
    private static function refuseWhatIsNoLongerTaken(int $extra): void
    {
        if ($extra > 0) {
            throw new InvalidArgumentException('Strict grouping is the vault\'s to say: pass Vault::empty(strict: true) to mask().');
        }
    }
}
