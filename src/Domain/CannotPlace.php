<?php

declare(strict_types=1);

namespace Sluis\Domain;

use RuntimeException;

/**
 * Thrown when a recogniser names something it cannot point at. A model that
 * reports the words it found but not where they were — which is what the ONNX
 * pipeline does — has to place them itself, and an adapter that quietly skips
 * what it could not place leaves exactly the value it recognised in the text.
 *
 * The message says the type and the recogniser and never the words themselves:
 * an exception is a thing that gets logged.
 */
final class CannotPlace extends RuntimeException
{
    public static function found(PiiType $type, string $recogniser): self
    {
        return new self("{$recogniser} recognised a {$type->value} it could not place in the text.");
    }

    /** It pointed somewhere, and the text does not read that way there. */
    public static function misplaced(PiiType $type, string $recogniser): self
    {
        return new self("{$recogniser} pointed at a {$type->value} that is not where it said it was.");
    }
}
