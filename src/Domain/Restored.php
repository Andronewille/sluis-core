<?php

namespace Sluis\Domain;

/**
 * The text with the people back in it, and two lists that have to be said out
 * loud rather than swallowed.
 *
 * `unrestored` is a token the vault holds that the text did not use — ordinary
 * when the text is a fragment or the model dropped a sentence. `stray` is the
 * dangerous one: a mask still standing in text that is on its way out, which
 * reads as a word and is not one.
 */
final readonly class Restored
{
    /**
     * @param  list<string>  $unrestored
     * @param  list<string>  $stray
     */
    public function __construct(
        public string $text,
        public array $unrestored = [],
        public array $stray = [],
    ) {}
}
