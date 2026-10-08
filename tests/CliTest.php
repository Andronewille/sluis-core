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
        [$code, $restored] = $this->sluis(['unmask', '--vault='.$path], $masked);

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

        [$code, $out, $err] = $this->sluis(['unmask', '--vault='.$path], 'Hey voornaam1mask en voornaam9mask.');

        $this->assertSame(4, $code);
        $this->assertStringContainsString('Hey Karel en voornaam9mask.', $out);
        $this->assertStringContainsString('voornaam9mask', $err);
    }

    public function test_it_refuses_to_unmask_without_a_vault(): void
    {
        [$code, , $err] = $this->sluis(['unmask', '--vault='.$this->dir.'/missing.json'], 'voornaam1mask');

        $this->assertSame(3, $code);
        $this->assertStringContainsString('vault', $err);
    }

    public function test_it_says_what_it_takes_when_asked_for_help(): void
    {
        [$code, $out, $err] = $this->sluis(['--help']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('unmask', $out);
        $this->assertSame('', $err);
    }

    public function test_it_says_what_it_takes_when_asked_wrongly(): void
    {
        [$code, , $err] = $this->sluis(['--wat']);

        $this->assertSame(2, $code);
        $this->assertStringContainsString('unmask', $err);
    }

    /** A sealed vault takes its key from the environment, never from an argument a `ps` shows. */
    public function test_it_seals_the_vault_when_a_key_is_in_the_environment(): void
    {
        $path = $this->dir.'/v.sealed';
        putenv('SLUIS_VAULT_KEY=een lange wachtzin voor de kluis');

        try {
            [, $masked] = $this->sluis(['--raw='.self::MAIL, '--vault='.$path]);

            $this->assertStringNotContainsString('Karel', (string) file_get_contents($path));

            [$code, $restored] = $this->sluis(['unmask', '--vault='.$path], $masked);
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
        [, $restored] = $this->sluis(['unmask', '--vault='.$path], $masked);

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

    /**
     * A caller that holds the vault itself has it as JSON, next to the text. What
     * `mask --json` answers is what `unmask --json` reads, so the two fit together
     * without a file ever being written.
     */
    public function test_what_mask_answers_in_json_is_what_unmask_reads(): void
    {
        [, $masked] = $this->sluis(['mask', '--json'], self::MAIL);
        [$code, $out] = $this->sluis(['unmask', '--json'], $masked);

        $this->assertSame(0, $code);
        $answer = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($answer);
        $this->assertSame(self::MAIL, $answer['text']);
        $this->assertSame([], $answer['stray']);
        $this->assertSame([], glob($this->dir.'/*'));
    }

    public function test_unmask_in_json_says_which_masks_it_could_not_place(): void
    {
        [$code, $out] = $this->sluis(['unmask', '--json'], '{"text":"Hey voornaam9mask.","vault":{"entries":{}}}');

        $this->assertSame(4, $code);
        $answer = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($answer);
        $this->assertSame(['voornaam9mask'], $answer['stray']);
    }

    /** Text that is not the JSON it was announced as is a wrong question, and none of it is quoted back. */
    public function test_unmask_in_json_refuses_what_is_not_json(): void
    {
        [$code, $out, $err] = $this->sluis(['unmask', '--json'], 'Hey Karel, dit is geen json.');

        $this->assertSame(2, $code);
        $this->assertSame('', $out);
        $this->assertStringNotContainsString('Karel', $err);
    }

    public function test_unmask_in_json_without_a_vault_anywhere_has_no_vault(): void
    {
        [$code] = $this->sluis(['unmask', '--json'], '{"text":"Hey voornaam1mask."}');

        $this->assertSame(3, $code);
    }

    /**
     * How a vault groups spellings is decided when it is made. `--strict` against
     * a vault that began without it used to be dropped without a word, and the
     * text came back in one spelling to somebody who had asked for byte for byte.
     */
    public function test_strict_is_refused_on_a_vault_that_did_not_begin_that_way(): void
    {
        $path = $this->dir.'/v.json';
        $this->sluis(['--vault='.$path], 'Hey Karel, tot morgen.');
        $before = file_get_contents($path);

        [$code, $out, $err] = $this->sluis(['--strict', '--vault='.$path], 'Hey KAREL, tot morgen.');

        $this->assertSame(2, $code);
        $this->assertSame('', $out);
        $this->assertStringContainsString('--strict', $err);
        $this->assertSame($before, file_get_contents($path));
    }

    public function test_strict_brings_every_spelling_back_as_it_was_written(): void
    {
        $path = $this->dir.'/v.json';
        $mail = "Hey Karel, of is het KAREL? Mvg, Bob\n";

        [, $masked] = $this->sluis(['--strict', '--vault='.$path], $mail);
        [, $restored] = $this->sluis(['unmask', '--vault='.$path], $masked);

        $this->assertStringContainsString('voornaam2mask', $masked);
        $this->assertSame($mail, $restored);
    }

    /** The flag is gone, and whoever still types it is told where it went rather than that it is unknown. */
    public function test_the_old_flag_points_at_the_command_that_replaced_it(): void
    {
        [$code, , $err] = $this->sluis(['--reverse'], 'voornaam1mask');

        $this->assertSame(2, $code);
        $this->assertStringContainsString('sluis unmask', $err);
    }

    /**
     * The command comes first or not at all. `sluis --json unmask` read as unmask
     * would be a guess about which way personal data should flow.
     */
    public function test_a_command_that_does_not_come_first_is_not_guessed_at(): void
    {
        [$code, $out] = $this->sluis(['--json', 'unmask'], self::MAIL);

        $this->assertSame(2, $code);
        $this->assertSame('', $out);
    }
}
