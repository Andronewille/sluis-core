<?php

declare(strict_types=1);

namespace Sluis\Domain;

use RuntimeException;

/**
 * Thrown when the text cannot be read at all: it is not UTF-8, or it is more
 * than the pattern engine will go through. PHP reports both as `false`, which
 * reads exactly like "found nothing" — and text in which nothing was found goes
 * out as it came in, with success written on it.
 *
 * The message says why and never what: the reason comes from the engine, which
 * knows nothing of the words.
 */
final class Unreadable extends RuntimeException
{
    public static function text(): self
    {
        return new self('The text could not be read: '.preg_last_error_msg().'.');
    }

    public static function notUtf8(): self
    {
        return new self('The text is not UTF-8, and Sluis reads nothing else: convert it first.');
    }
}
