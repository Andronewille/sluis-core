<?php

declare(strict_types=1);

namespace Sluis\Infrastructure\Recognisers;

use Sluis\Application\Ports\Recogniser;
use Sluis\Domain\PiiType;
use Sluis\Domain\Span;
use Sluis\Domain\Spans;

/**
 * A list of words that are one thing: towns, first names, the streets of one
 * village. It matches whole words and it matches them as written — a proper noun
 * is capitalised, and matching case-insensitively turns `Best` and `Beek` into
 * every `best` and `beek` in the language.
 *
 * A list is a floor, never a ceiling: it finds what it was given and nothing else,
 * which is the difference between it and the model.
 */
final readonly class Gazetteer implements Recogniser
{
    /** @var list<string> longest first, so `Bergen op Zoom` wins over `Bergen` */
    private array $words;

    /** @param iterable<string> $words */
    public function __construct(private PiiType $type, iterable $words)
    {
        $list = [];

        foreach ($words as $word) {
            $word = trim($word);

            if ($word !== '' && ! str_starts_with($word, '#')) {
                $list[] = $word;
            }
        }

        usort($list, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        $this->words = $list;
    }

    public static function fromFile(PiiType $type, string $path): self
    {
        return new self($type, is_readable($path) ? (file($path, FILE_IGNORE_NEW_LINES) ?: []) : []);
    }

    public function recognise(string $text): Spans
    {
        $spans = Spans::none();

        foreach ($this->words as $word) {
            $pattern = '/(?<![\p{L}\p{N}_])'.preg_quote($word, '/').'(?![\p{L}\p{N}_])/u';

            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }

            foreach ($matches[0] as [$found, $at]) {
                $spans = $spans->with(new Span($this->type, $at, $found, 'lijst'));
            }
        }

        return $spans->resolved();
    }

    public function isEmpty(): bool
    {
        return $this->words === [];
    }
}
