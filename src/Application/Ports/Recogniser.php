<?php

namespace Sluis\Application\Ports;

use Sluis\Domain\Spans;

/**
 * Something that reads text and says where the people are. A regex, a word list,
 * the frame a letter is written in, a model — the application layer cannot tell
 * them apart, which is the whole reason a model is optional here.
 *
 * A recogniser answers with spans it can point at. If it knows something is there
 * and cannot say where, it throws `CannotPlace`: silence would leave the value in
 * the text, and this is a tool whose only job is that it does not.
 */
interface Recogniser
{
    public function recognise(string $text): Spans;
}
