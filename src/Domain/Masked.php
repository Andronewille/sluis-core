<?php

namespace Sluis\Domain;

/** What went in, with the people taken out, and the vault that can put them back. */
final readonly class Masked
{
    /** @param array<string, int> $found how many of each type were taken out */
    public function __construct(
        public string $text,
        public Vault $vault,
        public array $found = [],
    ) {}
}
