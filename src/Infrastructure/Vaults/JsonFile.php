<?php

namespace Sluis\Infrastructure\Vaults;

use RuntimeException;
use Sluis\Application\Ports\VaultStore;
use Sluis\Domain\Vault;

/**
 * A vault as a file, readable by its owner and nobody else. The permissions are
 * set before the bytes go in, not after: a file that is world-readable for the
 * milliseconds between the write and the chmod is world-readable.
 *
 * Plain JSON, on purpose — a vault you can read is a vault you can check, and a
 * pipeline that keeps it for the length of one command has nowhere for it to
 * leak. When it has to rest anywhere longer than that, use `Sealed`.
 */
final readonly class JsonFile implements VaultStore
{
    public function exists(string $handle): bool
    {
        return is_file($handle);
    }

    public function read(string $handle): Vault
    {
        $json = @file_get_contents($handle);

        if ($json === false) {
            throw new RuntimeException("There is no vault at {$handle}.");
        }

        if (str_starts_with($json, Sealed::MAGIC)) {
            throw new RuntimeException("That vault is sealed; SLUIS_VAULT_KEY is what opens it: {$handle}.");
        }

        return Vault::fromArray(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * A write that failed and said nothing is the worst outcome this class has: the
     * masked text is already out, and the only thing that could put the people back
     * was never written. So the return value is read, which `file_put_contents`
     * makes easy to forget.
     */
    public function write(string $handle, Vault $vault): void
    {
        $this->touchPrivately($handle);

        $written = @file_put_contents($handle, json_encode(
            $vault->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        if ($written === false) {
            throw new RuntimeException("The vault could not be written to {$handle}; nothing can be restored without it.");
        }
    }

    private function touchPrivately(string $handle): void
    {
        if (! is_file($handle) && ! @touch($handle)) {
            throw new RuntimeException("The vault could not be written to {$handle}; nothing can be restored without it.");
        }

        chmod($handle, 0600);
    }
}
