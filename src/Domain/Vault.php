<?php

declare(strict_types=1);

namespace Sluis\Domain;

use InvalidArgumentException;

/**
 * The trust boundary. Everything else in Sluis works on text that no longer holds
 * a person; this is the one object that does, and `value()` is the one way back.
 * Only the reverse path may call it, and `tests/ArchitectureTest.php` is what
 * keeps that true — a progress message or a log line that wanted a value once is
 * how a tool like this leaks.
 *
 * A token is `voornaam1mask`: a Dutch label, a number, and `mask`. It is one word
 * with no punctuation on purpose. Brackets get eaten by markdown, underscores get
 * split by tokenizers, and a model asked to rewrite a sentence will happily
 * reflow `[VOORNAAM_1]` into something no substitution finds again.
 */
final class Vault
{
    /** @var array<string, array{type: PiiType, value: string}> */
    private array $entries = [];

    /** @var array<string, string> the grouping key of a value => the token it got */
    private array $index = [];

    /** @var array<string, int> the label => the last number it handed out */
    private array $counters = [];

    private function __construct(private readonly bool $strict) {}

    /**
     * `strict` keeps every spelling apart. Without it, Karel and KAREL are one
     * person on one token, which is what a model needs to write one reply — and
     * what makes the restored text carry the first spelling in both places. With
     * it, text comes back byte for byte and the model sees two people.
     */
    public static function empty(bool $strict = false): self
    {
        return new self($strict);
    }

    /**
     * What comes in here was read from a file or handed over by a caller, so its
     * shape is checked rather than cast: an entry that is not a type and a value
     * is refused, because a vault that half loads puts half the people back.
     *
     * It has to say it is a vault, too. Read as an empty one, any other JSON a
     * path happens to point at — an answer that was saved, a composer.json — is
     * a vault with nobody in it, and the next run writes over it.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $strict = $data['strict'] ?? false;
        $entries = $data['entries'] ?? null;

        if (($data['version'] ?? null) !== 1 || ! is_bool($strict) || ! is_array($entries)) {
            throw new InvalidArgumentException('That is not a vault this version of Sluis reads: it wants version 1, strict as yes or no, and entries.');
        }

        $vault = new self($strict);

        foreach ($entries as $token => $entry) {
            $type = is_array($entry) ? ($entry['type'] ?? null) : null;
            $value = is_array($entry) ? ($entry['value'] ?? null) : null;

            if (! is_string($type) || ! is_string($value)) {
                throw new InvalidArgumentException('The vault is not one Sluis wrote: an entry has no type or no value.');
            }

            // A token is what `mint()` makes and nothing looser. A list where the
            // entries should be has the tokens 0, 1, 2, and an empty key is a token
            // that stands between every two characters of the text.
            if (preg_match('/^[a-z]+\d+mask$/', (string) $token) !== 1) {
                throw new InvalidArgumentException('The vault is not one Sluis wrote: an entry is not filed under a mask.');
            }

            $vault->put(
                (string) $token,
                PiiType::tryFrom($type) ?? throw new InvalidArgumentException('The vault names a type Sluis does not know.'),
                $value,
            );
        }

        return $vault;
    }

    /**
     * The one place that says whether a piece of JSON is a vault. A file, a
     * sealed file and an answer on stdin all ask here, so all three refuse the
     * same things in the same words.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);

        return self::fromArray(is_array($data) ? $data : []);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => 1,
            'strict' => $this->strict,
            'entries' => array_map(
                fn (array $entry) => ['type' => $entry['type']->value, 'value' => $entry['value']],
                $this->entries,
            ),
        ];
    }

    /**
     * The token for a value, minting one if this is the first time. `$taken` says
     * whether a candidate token is a string the document itself already uses: a
     * text that reads like a masked document must not have its own words
     * overwritten when the vault is played back over it.
     *
     * @param  callable(string): bool  $taken
     *
     * @phpstan-impure
     */
    public function mint(PiiType $type, string $value, callable $taken): string
    {
        $key = $this->keyFor($type, $value);

        if (isset($this->index[$key])) {
            return $this->index[$key];
        }

        $this->counters[$type->value] ??= 0;

        do {
            $number = ++$this->counters[$type->value];
            $token = $type->value.$number.'mask';
        } while (isset($this->entries[$token]) || $taken($token));

        $this->put($token, $type, $value);

        return $token;
    }

    /** The one way back to what was taken out. The reverse path, and nothing else. */
    public function value(string $token): ?string
    {
        return $this->entries[$token]['value'] ?? null;
    }

    /**
     * What is in here, without what it holds: the tokens and what each one stood
     * for. Safe to print, to log and to hand a client.
     *
     * @return array<string, PiiType>
     */
    public function entries(): array
    {
        return array_map(fn (array $entry) => $entry['type'], $this->entries);
    }

    /** @return list<string> */
    public function tokens(): array
    {
        return array_keys($this->entries);
    }

    public function isStrict(): bool
    {
        return $this->strict;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    private function put(string $token, PiiType $type, string $value): void
    {
        $this->entries[$token] = ['type' => $type, 'value' => $value];
        $this->index[$this->keyFor($type, $value)] ??= $token;

        if (preg_match('/^'.preg_quote($type->value, '/').'(\d+)mask$/', $token, $m) === 1) {
            $this->counters[$type->value] = max($this->counters[$type->value] ?? 0, (int) $m[1]);
        } else {
            $this->counters[$type->value] ??= 0;
        }
    }

    private function keyFor(PiiType $type, string $value): string
    {
        if ($this->strict) {
            return $type->value.'|'.$value;
        }

        return $type->value.'|'.mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)) ?? $value);
    }
}
