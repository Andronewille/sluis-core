<?php

namespace Sluis\Application\Ports;

use Sluis\Domain\Vault;

/**
 * Where a vault rests between the two halves of the pipeline. A file, a sealed
 * file, nothing at all when the caller carries it itself — which is how Sluis is
 * used over an API, where the vault goes back in the answer and is never stored.
 */
interface VaultStore
{
    public function exists(string $handle): bool;

    public function read(string $handle): Vault;

    public function write(string $handle, Vault $vault): void;
}
