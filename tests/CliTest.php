<?php

declare(strict_types=1);

namespace Sluis\Tests;

use PHPUnit\Framework\TestCase;
use Sluis\Infrastructure\Cli\Console;

/**
 * The command, which is how Sluis is used in a pipeline: mask on the way into an
 * AI system, restore on the way out, with the vault the only thing that travels
 * between the two halves.
 */
class CliTest extends TestCase
{
    private const MAIL = 'Hey Karel, te bezichtigen aan de Maanstraat 123. Mvg, Bob';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/sluis-cli-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function test_it_masks_what_it_is_given_and_writes_the_vault(): void
    {
        [$code, $out] = $this->sluis(['--raw='.self::MAIL, '--vault='.$vault = $this->dir.'/v.json']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Hey voornaam1mask,', $out);
        $this->assertFileExists($vault);
    }

    /** The two halves of the pipeline, which is the only use that matters. */
    public function test_it_masks_from_stdin_and_restores_from_stdin(): void
    {
        $path = $this->dir.'/v.json';

        [, $masked] = $this->sluis(['--vault='.$path], self::MAIL);
        [$code, $restored] = $this->sluis(['--reverse', '--vault='.$path], $masked);

        $this->assertSame(0, $code);
        $this->assertSame(self::MAIL, rtrim($restored, "\n"));
    }

    public function test_it_answers_json_when_asked_so_a_caller_can_hold_the_vault_itself(): void
    {
        [$code, $out] = $this->sluis(['--json', '--raw='.self::MAIL]);

        $this->assertSame(0, $code);
        $answer = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($answer);
        $this->assertIsString($answer['text']);
        $this->assertStringContainsString('voornaam1mask', $answer['text']);
        $this->assertIsArray($answer['vault']);
        $this->assertArrayHasKey('entries', $answer['vault']);
    }

    /** No vault file is written when the caller did not ask for one. */
    public function test_json_alone_leaves_nothing_on_disk(): void
    {
        $this->sluis(['--json', '--raw='.self::MAIL]);

        $this->assertSame([], glob($this->dir.'/*'));
    }

    /**
     * A token the vault cannot place is said out loud and fails the command: a
     * pipeline that quietly hands on text with `voornaam1mask` still in it has
     * published a mask instead of a name, and nobody reading the output notices.
     */
    public function test_it_fails_loudly_on_a_token_it_cannot_restore(): void
    {
        $path = $this->dir.'/v.json';
        $this->sluis(['--raw=Hey Karel, tot morgen.', '--vault='.$path]);

        [$code, $out, $err] = $this->sluis(['--reverse', '--vault='.$path], 'Hey voornaam1mask en voornaam9mask.');

        $this->assertSame(4, $code);
        $this->assertStringContainsString('Hey Karel en voornaam9mask.', $out);
        $this->assertStringContainsString('voornaam9mask', $err);
    }

    public function test_it_refuses_to_reverse_without_a_vault(): void
    {
        [$code, , $err] = $this->sluis(['--reverse', '--vault='.$this->dir.'/missing.json'], 'voornaam1mask');

        $this->assertSame(3, $code);
        $this->assertStringContainsString('vault', $err);
    }

    public function test_it_says_what_it_takes_when_asked_for_help(): void
    {
        [$code, $out, $err] = $this->sluis(['--help']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('--reverse', $out);
        $this->assertSame('', $err);
    }

    public function test_it_says_what_it_takes_when_asked_wrongly(): void
    {
        [$code, , $err] = $this->sluis(['--wat']);

        $this->assertSame(2, $code);
        $this->assertStringContainsString('--reverse', $err);
    }

    /** A sealed vault takes its key from the environment, never from an argument a `ps` shows. */
    public function test_it_seals_the_vault_when_a_key_is_in_the_environment(): void
    {
        $path = $this->dir.'/v.sealed';
        putenv('SLUIS_VAULT_KEY=een lange wachtzin voor de kluis');

        try {
            [, $masked] = $this->sluis(['--raw='.self::MAIL, '--vault='.$path]);

            $this->assertStringNotContainsString('Karel', (string) file_get_contents($path));

            [$code, $restored] = $this->sluis(['--reverse', '--vault='.$path], $masked);
            $this->assertSame(0, $code);
            $this->assertSame(self::MAIL, rtrim($restored, "\n"));
        } finally {
            putenv('SLUIS_VAULT_KEY');
        }
    }

    /**
     * A file that ends in a newline comes back with one newline, not two: the
     * text that leaves the pipeline has to be the text that entered it.
     */
    public function test_it_adds_no_newline_the_text_did_not_have(): void
    {
        $path = $this->dir.'/v.json';
        $mail = self::MAIL."\n";

        [, $masked] = $this->sluis(['--vault='.$path], $mail);
        [, $restored] = $this->sluis(['--reverse', '--vault='.$path], $masked);

        $this->assertSame($mail, $restored);
    }

    /**
     * @param  list<string>  $argv
     * @return array{0: int, 1: string, 2: string}
     */
    private function sluis(array $argv, string $stdin = ''): array
    {
        $in = $this->memory();
        fwrite($in, $stdin);
        rewind($in);
        $out = $this->memory();
        $err = $this->memory();

        $code = (new Console)->run(['sluis', ...$argv], $in, $out, $err);

        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    /** @return resource */
    private function memory()
    {
        $stream = fopen('php://memory', 'r+');
        $this->assertIsResource($stream);

        return $stream;
    }
}
