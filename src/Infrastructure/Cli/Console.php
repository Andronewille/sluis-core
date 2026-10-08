<?php

declare(strict_types=1);

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
 *     cat masked.txt | ai | sluis unmask     # and the people are back
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

      sluis [mask] [--vault=PATH] [--strict] [--json] [--raw=TEXT]
      sluis unmask [--vault=PATH] [--json] [--raw=TEXT]

      mask         take the people out and keep them in the vault; what sluis does
                   when it is not told which
      unmask       put them back; needs the vault that took them out

      --vault=PATH where the vault goes, or comes from (default sluis-vault.json)
      --strict     keep every spelling apart, so the text comes back byte for byte
      --json       mask answers {"text":…,"found":…,"vault":…} and writes no file;
                   unmask reads {"text":…,"vault":…} and answers {"text":…,"stray":…}
      --raw=TEXT   the text, instead of stdin. An argument can be read by everyone
                   on the machine while the command runs: for trying sluis out,
                   not for somebody's mail

    The vault is the only thing that ever holds what was taken out. Set
    SLUIS_VAULT_KEY and it is sealed with that passphrase.

    Exit: 0 done · 1 went wrong · 2 asked wrongly · 3 no vault · 4 a mask reached the output
    TXT;

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

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

        if ($text === false) {
            fwrite($err, "Sluis could not read its input.\n");

            return 1;
        }

        $store = $this->store();
        $path = $options['vault'] ?? ($options['json'] ? null : 'sluis-vault.json');

        try {
            return $options['unmask']
                ? $this->unmask($text, $store, $path, $options['json'], $out, $err)
                : $this->mask($text, $store, $path, $options, $out, $err);
        } catch (Throwable $e) {
            fwrite($err, $e->getMessage()."\n");

            return 1;
        }
    }

    /**
     * @param  array{strict: bool, json: bool}  $options
     * @param  resource  $out
     * @param  resource  $err
     */
    private function mask(string $text, VaultStore $store, ?string $path, array $options, $out, $err): int
    {
        $vault = $path !== null && $store->exists($path) ? $store->read($path) : Vault::empty($options['strict']);

        // How a vault groups spellings is decided when it is made. Carrying on
        // with the vault's own answer would hand back text that is not byte for
        // byte what went in, to somebody who asked for exactly that.
        if ($options['strict'] && ! $vault->isStrict()) {
            fwrite($err, "The vault at {$path} was not made with --strict, and a vault keeps to how it began.\n");

            return 2;
        }

        $masked = Sluis::nederlands()->mask($text, $vault);

        if ($path !== null) {
            $store->write($path, $masked->vault);
        }

        if ($options['json']) {
            fwrite($out, json_encode([
                'text' => $masked->text,
                'found' => $masked->found,
                'vault' => $masked->vault->toArray(),
            ], self::JSON)."\n");

            return 0;
        }

        $this->say($out, $masked->text);

        return 0;
    }

    /**
     * With `--json` the text and the vault arrive together, which is what `mask
     * --json` answers with and what a caller that holds the vault itself has in
     * hand. A vault in the input wins over one on disk: it is the one that came
     * with this text.
     *
     * @param  resource  $out
     * @param  resource  $err
     */
    private function unmask(string $text, VaultStore $store, ?string $path, bool $json, $out, $err): int
    {
        $vault = null;

        if ($json) {
            $given = json_decode($text, true);
            $held = is_array($given) ? ($given['vault'] ?? null) : null;

            if (! is_array($given) || ! is_string($given['text'] ?? null) || ($held !== null && ! is_array($held))) {
                fwrite($err, "With --json, unmask reads {\"text\":…,\"vault\":…}.\n");

                return 2;
            }

            $text = $given['text'];
            $vault = $held === null ? null : Vault::fromArray($held);
        }

        if ($vault === null && $path !== null && $store->exists($path)) {
            $vault = $store->read($path);
        }

        if ($vault === null) {
            fwrite($err, 'There is no vault to read back: pass --vault=PATH'.($json ? " or a vault in the JSON.\n" : ".\n"));

            return 3;
        }

        $restored = Sluis::nederlands()->unmask($text, $vault);

        if ($json) {
            fwrite($out, json_encode([
                'text' => $restored->text,
                'unrestored' => $restored->unrestored,
                'stray' => $restored->stray,
            ], self::JSON)."\n");
        } else {
            $this->say($out, $restored->text);
        }

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
     * @return array{unmask: bool, raw: ?string, vault: ?string, strict: bool, json: bool, help: bool}
     */
    private function options(array $arguments): array
    {
        $options = ['unmask' => false, 'raw' => null, 'vault' => null, 'strict' => false, 'json' => false, 'help' => false];

        // The command comes first or not at all: a bare word further on is a
        // mistake, and guessing that `sluis --json unmask` meant the command
        // would be guessing which way personal data should flow.
        if (in_array($arguments[0] ?? null, ['mask', 'unmask'], true)) {
            $options['unmask'] = array_shift($arguments) === 'unmask';
        }

        foreach ($arguments as $argument) {
            match (true) {
                str_starts_with($argument, '--raw=') => $options['raw'] = substr($argument, 6),
                str_starts_with($argument, '--vault=') => $options['vault'] = substr($argument, 8),
                $argument === '--strict' => $options['strict'] = true,
                $argument === '--json' => $options['json'] = true,
                $argument === '--help' || $argument === '-h' => $options['help'] = true,
                $argument === '--reverse' => throw new RuntimeException('--reverse is now a command: sluis unmask.'),
                default => throw new RuntimeException("Sluis does not know {$argument}."),
            };
        }

        if ($options['unmask'] && $options['strict']) {
            throw new RuntimeException('--strict is decided when a vault is made; unmask reads it from the vault.');
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
