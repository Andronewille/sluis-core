<?php

declare(strict_types=1);

namespace Sluis\Infrastructure\Recognisers;

use RuntimeException;
use Sluis\Application\Ports\Recogniser;
use Sluis\Domain\PiiType;
use Sluis\Domain\Span;
use Sluis\Domain\Spans;
use Sluis\Domain\Unreadable;

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

    /**
     * A list that is not there is refused rather than read as empty. An empty
     * list finds nothing and says so to nobody: one wrong letter in the path to
     * a list of surnames, and every one of them goes to the model. The same goes
     * for a file that is there and holds no word — a download that stopped, a
     * file saved as UTF-16 — and for the byte order mark some editors put in
     * front of the first line, which made the first name on the list one that
     * no text contains.
     */
    public static function fromFile(PiiType $type, string $path): self
    {
        $words = is_file($path) ? @file($path, FILE_IGNORE_NEW_LINES) : false;

        if ($words === false) {
            throw new RuntimeException("There is no word list to read at {$path}.");
        }

        if (! mb_check_encoding(implode("\n", $words), 'UTF-8')) {
            throw new RuntimeException("The word list at {$path} is not UTF-8.");
        }

        if (str_starts_with($words[0] ?? '', "\u{FEFF}")) {
            $words[0] = substr($words[0], 3);
        }

        $list = new self($type, $words);

        if ($list->isEmpty()) {
            throw new RuntimeException("The word list at {$path} holds no words.");
        }

        return $list;
    }

    public function recognise(string $text): Spans
    {
        $spans = Spans::none();

        foreach ($this->words as $word) {
            $pattern = '/(?<![\p{L}\p{N}_])'.preg_quote($word, '/').'(?![\p{L}\p{N}_])/u';

            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
                throw Unreadable::text();
            }

            foreach ($matches[0] as [$found, $at]) {
                $spans = $spans->with(new Span($this->type, $at, $found, 'lijst'));
            }
        }

        return $spans;
    }

    public function isEmpty(): bool
    {
        return $this->words === [];
    }
}
