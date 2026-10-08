<?php

namespace Sluis\Infrastructure\Vaults;

use RuntimeException;
use SensitiveParameter;
use Sluis\Application\Ports\VaultStore;
use Sluis\Domain\Vault;

/**
 * A vault that is bytes without the key. This is what makes it safe to keep a
 * vault next to the text it belongs to, in a directory a backup sweeps up, or for
 * longer than the one command that made it.
 *
 * Argon2id over the passphrase with a per-file salt, then a secretbox. The key is
 * never in the file, and the passphrase comes from the environment rather than an
 * argument, because an argument is in every `ps` on the machine.
 */
final readonly class Sealed implements VaultStore
{
    /**
     * The first bytes of every sealed file, and the one thing the two stores have
     * to agree on: `JsonFile` reads it too, so that a sealed vault opened without
     * a key is told what it is instead of failing as broken JSON.
     */
    public const MAGIC = "SLUIS1\n";

    public function __construct(#[SensitiveParameter] private string $passphrase)
    {
        if (! function_exists('sodium_crypto_secretbox')) {
            throw new RuntimeException('A sealed vault needs the sodium extension.');
        }

        if (trim($passphrase) === '') {
            throw new RuntimeException('A sealed vault needs a passphrase.');
        }
    }

    public function exists(string $handle): bool
    {
        return is_file($handle);
    }

    public function read(string $handle): Vault
    {
        $bytes = @file_get_contents($handle);

        if ($bytes === false) {
            throw new RuntimeException("There is no vault at {$handle}.");
        }

        $magic = strlen(self::MAGIC);

        if (! str_starts_with($bytes, self::MAGIC) || strlen($bytes) < $magic + 16 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException("That vault is not a sealed vault: {$handle}.");
        }

        $salt = substr($bytes, $magic, 16);
        $nonce = substr($bytes, $magic + 16, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $sealed = substr($bytes, $magic + 16 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $key = $this->keyFrom($salt);
        $json = sodium_crypto_secretbox_open($sealed, $nonce, $key);
        sodium_memzero($key);

        if ($json === false) {
            throw new RuntimeException('That vault did not open with this key.');
        }

        $vault = Vault::fromArray(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
        sodium_memzero($json);

        return $vault;
    }

    public function write(string $handle, Vault $vault): void
    {
        $salt = random_bytes(16);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $key = $this->keyFrom($salt);
        $json = json_encode($vault->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $sealed = sodium_crypto_secretbox($json, $nonce, $key);
        sodium_memzero($json);
        sodium_memzero($key);

        if (! is_file($handle) && ! @touch($handle)) {
            throw new RuntimeException("The vault could not be written to {$handle}; nothing can be restored without it.");
        }

        chmod($handle, 0600);

        if (@file_put_contents($handle, self::MAGIC.$salt.$nonce.$sealed) === false) {
            throw new RuntimeException("The vault could not be written to {$handle}; nothing can be restored without it.");
        }
    }

    private function keyFrom(string $salt): string
    {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            $this->passphrase,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }
}
