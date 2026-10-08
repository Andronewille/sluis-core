<?php

namespace Sluis\Infrastructure\Cli;

use RuntimeException;
use Sluis\Application\Ports\VaultStore;
use Sluis\Domain\Vault;
use Sluis\Infrastructure\Vaults\JsonFile;
use Sluis\Infrastructure\Vaults\Sealed;
use Sluis\Sluis;
use Throwable;

/**
 * Sluis as a pipe, which is the shape the tool was asked for:
 *
 *     sluis < mail.txt > masked.txt          # vault beside it, 0600
 *     cat masked.txt | ai | sluis --reverse  # and the people are back
 *
 * The exit codes are the point of the command rather than an afterthought. A
 * pipeline that hands on text with a mask still standing in it has published a
 * label where a name should be, and nobody reading the output notices; so that is
 * a failure with a message, not a warning in a stream somebody greps later.
 */
final class Console
{
    private const USAGE = <<<'TXT'
    sluis — masks personal data in Dutch text, and puts it back.

      sluis [--raw=TEXT] [--vault=PATH] [--strict] [--json]
      sluis --reverse [--raw=TEXT] --vault=PATH

      --raw=TEXT   the text; without it, sluis reads stdin
      --vault=PATH where the vault goes, or comes from (default sluis-vault.json)
      --reverse    put the values back; needs the vault that took them out
      --strict     keep every spelling apart, so the text comes back byte for byte
      --json       answer {"text":…,"vault":…} and write no file

    The vault is the only thing that ever holds what was taken out. Set
    SLUIS_VAULT_KEY and it is sealed with that passphrase.

    Exit: 0 done · 1 went wrong · 2 asked wrongly · 3 no vault · 4 a mask reached the output
    TXT;

    /**
     * @param  list<string>  $argv
     * @param  resource  $in
     * @param  resource  $out
     * @param  resource  $err
     */
    public function run(array $argv, $in, $out, $err): int
    {
        try {
            $options = $this->options(array_slice($argv, 1));
        } catch (RuntimeException $e) {
            fwrite($err, $e->getMessage()."\n\n".self::USAGE."\n");

            return 2;
        }

        if ($options['help']) {
            fwrite($out, self::USAGE."\n");

            return 0;
        }

        $text = $options['raw'] ?? stream_get_contents($in);
        $store = $this->store();
        $path = $options['vault'] ?? ($options['json'] ? null : 'sluis-vault.json');

        try {
            return $options['reverse']
                ? $this->reverse($text, $store, $path, $out, $err)
                : $this->forward($text, $store, $path, $options, $out, $err);
        } catch (Throwable $e) {
            fwrite($err, $e->getMessage()."\n");

            return 1;
        }
    }

    /** @param array{strict: bool, json: bool, help: bool} $options */
    private function forward(string $text, VaultStore $store, ?string $path, array $options, $out, $err): int
    {
        $sluis = Sluis::nederlands(strict: $options['strict']);

        $vault = $path !== null && $store->exists($path) ? $store->read($path) : Vault::empty($options['strict']);
        $masked = $sluis->mask($text, $vault);

        if ($path !== null) {
            $store->write($path, $masked->vault);
        }

        if ($options['json']) {
            fwrite($out, json_encode([
                'text' => $masked->text,
                'found' => $masked->found,
                'vault' => $masked->vault->toArray(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

            return 0;
        }

        $this->say($out, $masked->text);

        return 0;
    }

    private function reverse(string $text, VaultStore $store, ?string $path, $out, $err): int
    {
        if ($path === null || ! $store->exists($path)) {
            fwrite($err, "There is no vault to read back: pass --vault=PATH.\n");

            return 3;
        }

        $restored = Sluis::nederlands()->unmask($text, $store->read($path));

        $this->say($out, $restored->text);

        if ($restored->stray !== []) {
            fwrite($err, 'A mask reached the output and this vault cannot place it: '
                .implode(', ', $restored->stray)."\n");

            return 4;
        }

        return 0;
    }

    /**
     * A newline at the end, unless the text brought its own. A command that adds
     * one regardless means the text that comes out of the pipeline is not the text
     * that went in, and the first thing anyone checks is whether it came back
     * exactly.
     *
     * @param  resource  $out
     */
    private function say($out, string $text): void
    {
        fwrite($out, str_ends_with($text, "\n") ? $text : $text."\n");
    }

    /**
     * @param  list<string>  $arguments
     * @return array{raw: ?string, vault: ?string, reverse: bool, strict: bool, json: bool, help: bool}
     */
    private function options(array $arguments): array
    {
        $options = ['raw' => null, 'vault' => null, 'reverse' => false, 'strict' => false, 'json' => false, 'help' => false];

        foreach ($arguments as $argument) {
            match (true) {
                str_starts_with($argument, '--raw=') => $options['raw'] = substr($argument, 6),
                str_starts_with($argument, '--vault=') => $options['vault'] = substr($argument, 8),
                $argument === '--reverse' => $options['reverse'] = true,
                $argument === '--strict' => $options['strict'] = true,
                $argument === '--json' => $options['json'] = true,
                $argument === '--help' || $argument === '-h' => $options['help'] = true,
                default => throw new RuntimeException("Sluis does not know {$argument}."),
            };
        }

        return $options;
    }

    /** A passphrase in the environment seals the vault; without one it is a private file. */
    private function store(): VaultStore
    {
        $key = getenv('SLUIS_VAULT_KEY');

        return is_string($key) && trim($key) !== '' ? new Sealed($key) : new JsonFile;
    }
}
