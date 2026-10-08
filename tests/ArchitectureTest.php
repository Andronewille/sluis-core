<?php

namespace Sluis\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The promises Sluis makes, checked mechanically. Sluis exists to keep personal
 * data out of somewhere it should not go, so its own leaks are the ones nobody
 * would notice: a value in a log line, a value on the wire, a value read by
 * something that had no business reading it. A rule kept by discipline alone
 * drifts the first evening someone is tired.
 */
class ArchitectureTest extends TestCase
{
    /**
     * Fully offline, and provably: nothing in Sluis opens a socket, and no
     * dependency of the core can, because the core has none. This is the promise
     * the whole tool rests on — text goes through Sluis precisely so it does not
     * go anywhere else.
     */
    public function test_nothing_in_the_core_can_reach_the_network(): void
    {
        foreach ($this->files('packages/core/src') as $path => $source) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b(curl_init|curl_exec|fsockopen|socket_create|stream_socket_client|pfsockopen|get_headers|dns_get_record)\s*\(/',
                $source,
                "{$path} can open a connection",
            );
            $this->assertDoesNotMatchRegularExpression(
                '#(file_get_contents|fopen|copy)\s*\(\s*[\'"](https?|ftp|php://input)#i',
                $source,
                "{$path} reads from the network",
            );
        }

        $composer = json_decode(file_get_contents($this->root().'packages/core/composer.json'), true);

        foreach (array_keys($composer['require']) as $requirement) {
            $this->assertMatchesRegularExpression('/^(php|ext-[a-z]+)$/', $requirement, "the core has grown a dependency: {$requirement}");
        }
    }

    /** The domain knows nothing but itself: no framework, no adapter, no port. */
    public function test_the_domain_imports_nothing(): void
    {
        $allowed = '/^(Sluis\\\\Domain\\\\|(Countable|IteratorAggregate|ArrayIterator|Traversable|JsonException|RuntimeException|InvalidArgumentException|Stringable)$)/';
        $foreign = [];

        foreach ($this->files('packages/core/src/Domain') as $path => $source) {
            preg_match_all('/\buse\s+([A-Za-z0-9_\\\\]+)\s*;/', $source, $uses);

            foreach ($uses[1] as $import) {
                if (preg_match($allowed, $import) !== 1) {
                    $foreign[] = "{$path} imports {$import}";
                }
            }
        }

        $this->assertSame([], $foreign);
    }

    /**
     * The application layer does not know its adapters. `Sluis.php` is the one
     * exception on purpose: it is the composition root, the place where a caller
     * who wants the default Dutch setup gets it without wiring anything.
     */
    public function test_the_application_does_not_know_its_adapters(): void
    {
        foreach ($this->files('packages/core/src/Application') as $path => $source) {
            $this->assertStringNotContainsString('Sluis\Infrastructure', $source, "{$path} names an adapter");
        }
    }

    /**
     * The reverse path is pure substitution: it puts values back where tokens
     * are and does nothing else. It cannot recognise, cannot call a model, cannot
     * decide. That is why restoring is safe to run on text a model rewrote.
     */
    public function test_the_reverse_path_only_substitutes(): void
    {
        $source = php_strip_whitespace($this->root().'packages/core/src/Application/Deanonymise.php');

        foreach (['Recogniser', 'Patterns', 'Frames', 'Places', 'Gazetteer', 'Onnx', 'pipeline'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "the reverse path reaches for {$forbidden}");
        }
    }

    /**
     * One reader of plaintext. `value()` is the only way back to what was taken
     * out, and only the reverse path may call it: every other feature that ever
     * wants it — a report, a log line, a progress message — is the leak this
     * test exists to refuse.
     */
    public function test_only_the_reverse_path_reads_a_value(): void
    {
        $allowed = [
            'packages/core/src/Domain/Vault.php',
            'packages/core/src/Application/Deanonymise.php',
        ];

        foreach ($this->files('packages/core/src') as $path => $source) {
            if (in_array($path, $allowed, true)) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression('/->value\s*\(/', $source, "{$path} reads a value out of the vault");
        }
    }

    /**
     * What Sluis took out never reaches a log, a warning or a var_dump. The adapters
     * in other packages hold themselves to this in their own suites: a rule that
     * reaches across a package boundary fails the moment that package is not there,
     * and a test that tolerates a missing directory is a test that checks nothing.
     */
    public function test_nothing_writes_a_value_anywhere_but_the_output(): void
    {
        foreach ($this->files('packages/core/src') as $path => $source) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b(error_log|var_dump|print_r|var_export|syslog|trigger_error)\s*\(/',
                $source,
                "{$path} writes somewhere that is not the caller's output",
            );
        }
    }

    /** The core carries no model: the model is a choice, made in another package. */
    public function test_the_core_names_no_model(): void
    {
        foreach ($this->files('packages/core/src') as $path => $source) {
            $this->assertDoesNotMatchRegularExpression('/Codewithkyrian|onnx|transformers|huggingface/i', $source, "{$path} names a model runtime");
        }
    }

    /**
     * Every rule here is about what the code does, so the code is what it reads:
     * `php_strip_whitespace` drops the comments first. Otherwise a docblock
     * explaining why Sluis does not do something fails the test that checks it
     * does not — and the fix for that is always to delete the explanation.
     *
     * @return iterable<string, string>
     */
    private function files(string $dir): iterable
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root().$dir)) as $file) {
            if ($file->getExtension() === 'php') {
                yield str_replace($this->root(), '', $file->getPathname()) => php_strip_whitespace($file->getPathname());
            }
        }
    }

    private function root(): string
    {
        return dirname(__DIR__, 3).'/';
    }
}
