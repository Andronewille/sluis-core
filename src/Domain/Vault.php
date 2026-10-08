<?php

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

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $vault = new self((bool) ($data['strict'] ?? false));

        foreach ($data['entries'] ?? [] as $token => $entry) {
            $type = PiiType::tryFrom((string) ($entry['type'] ?? ''))
                ?? throw new InvalidArgumentException("The vault names a type Sluis does not know: {$entry['type']}.");

            $vault->put((string) $token, $type, (string) $entry['value']);
        }

        return $vault;
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
