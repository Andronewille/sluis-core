<?php

declare(strict_types=1);

namespace Sluis\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sluis\Domain\PiiType;
use Sluis\Domain\Vault;
use Sluis\Infrastructure\Vaults\JsonFile;
use Sluis\Infrastructure\Vaults\Sealed;
use Sluis\Sluis;

/**
 * The vault is the whole trust boundary: it is the one thing that holds what was
 * taken out, and everything else in Sluis works on text that no longer has it.
 * So what is tested here is not convenience, it is who may read it and what it
 * leaves on disk.
 */
class VaultTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/sluis-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function test_the_same_value_mints_one_token_and_a_different_one_mints_the_next(): void
    {
        $vault = Vault::empty();
        $taken = fn (string $candidate) => false;

        $this->assertSame('voornaam1mask', $vault->mint(PiiType::Voornaam, 'Karel', $taken));
        $this->assertSame('voornaam1mask', $vault->mint(PiiType::Voornaam, 'Karel', $taken));
        $this->assertSame('voornaam2mask', $vault->mint(PiiType::Voornaam, 'Bob', $taken));
        $this->assertSame('stad1mask', $vault->mint(PiiType::Stad, 'Haasterdam', $taken));
    }

    /** One person written two ways is one person, or the model answers a stranger. */
    public function test_the_same_value_in_another_case_is_the_same_token(): void
    {
        $vault = Vault::empty();
        $taken = fn (string $candidate) => false;

        $this->assertSame('voornaam1mask', $vault->mint(PiiType::Voornaam, 'Karel', $taken));
        $this->assertSame('voornaam1mask', $vault->mint(PiiType::Voornaam, 'KAREL', $taken));
        $this->assertSame('Karel', $vault->value('voornaam1mask'));
    }

    /**
     * Strict grouping is for text that has to come back byte for byte: every
     * spelling gets its own token, and KAREL comes back as KAREL.
     */
    public function test_strict_grouping_keeps_every_spelling_apart(): void
    {
        $vault = Vault::empty(strict: true);
        $taken = fn (string $candidate) => false;

        $this->assertSame('voornaam1mask', $vault->mint(PiiType::Voornaam, 'Karel', $taken));
        $this->assertSame('voornaam2mask', $vault->mint(PiiType::Voornaam, 'KAREL', $taken));
    }

    public function test_it_skips_a_token_the_document_already_uses(): void
    {
        $vault = Vault::empty();
        $taken = fn (string $candidate) => $candidate === 'voornaam1mask';

        $this->assertSame('voornaam2mask', $vault->mint(PiiType::Voornaam, 'Karel', $taken));
    }

    public function test_it_survives_a_round_trip_through_json(): void
    {
        $vault = Vault::empty();
        $vault->mint(PiiType::Voornaam, 'Karel', fn () => false);

        $again = Vault::fromArray($vault->toArray());

        $this->assertSame('Karel', $again->value('voornaam1mask'));
        $this->assertSame([PiiType::Voornaam], array_values($again->entries()));
    }

    public function test_a_file_vault_is_readable_by_nobody_else(): void
    {
        $store = new JsonFile;
        $vault = Vault::empty();
        $vault->mint(PiiType::Voornaam, 'Karel', fn () => false);

        $store->write($path = $this->dir.'/v.json', $vault);

        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        $this->assertSame('Karel', $store->read($path)->value('voornaam1mask'));
    }

    /**
     * A sealed vault is what makes the file safe to keep beside the text it goes
     * with: without the key it is bytes, and the key never goes in the file.
     */
    public function test_a_sealed_vault_holds_nothing_readable_without_the_key(): void
    {
        $store = new Sealed('een lange wachtzin voor de kluis');
        $vault = Vault::empty();
        $vault->mint(PiiType::Voornaam, 'Karel', fn () => false);

        $store->write($path = $this->dir.'/v.sealed', $vault);

        $this->assertStringNotContainsString('Karel', (string) file_get_contents($path));
        $this->assertSame('Karel', $store->read($path)->value('voornaam1mask'));
    }

    /**
     * The masked text is already out by the time the vault is written. A write that
     * failed quietly means nothing can ever be restored, and the run said it worked.
     */
    public function test_a_vault_that_cannot_be_written_says_so(): void
    {
        $this->expectExceptionMessage('nothing can be restored without it');

        (new JsonFile)->write($this->dir.'/bestaat/niet/v.json', Vault::empty());
    }

    public function test_a_sealed_vault_that_cannot_be_written_says_so(): void
    {
        $this->expectExceptionMessage('nothing can be restored without it');

        (new Sealed('een lange wachtzin voor de kluis'))->write($this->dir.'/bestaat/niet/v.sealed', Vault::empty());
    }

    public function test_a_sealed_vault_refuses_the_wrong_key(): void
    {
        (new Sealed('de juiste wachtzin'))->write($path = $this->dir.'/v.sealed', Vault::empty());

        $this->expectExceptionMessage('vault');
        (new Sealed('de verkeerde wachtzin'))->read($path);
    }

    /**
     * The mirror of the guard in `Sealed`, for the mix-up that actually happens:
     * SLUIS_VAULT_KEY was set on the way in and forgotten on the way out. The bytes
     * are then handed to `json_decode`, and the operator who is one environment
     * variable away from their text reads `Syntax error` instead.
     */
    public function test_a_plain_read_of_a_sealed_vault_names_the_key(): void
    {
        (new Sealed('een lange wachtzin voor de kluis'))->write($path = $this->dir.'/v.sealed', Vault::empty());

        $this->expectExceptionMessage('SLUIS_VAULT_KEY');
        (new JsonFile)->read($path);
    }

    /**
     * A vault that loads half of what it holds puts half the people back and says
     * it is done. So an entry that is not a type and a value stops the read, and
     * the message says what is wrong with the file without quoting what is in it.
     */
    public function test_a_vault_with_an_entry_it_cannot_read_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not one Sluis wrote');

        Vault::fromArray(['entries' => ['voornaam1mask' => ['type' => 'voornaam', 'value' => ['Karel']]]]);
    }

    /** Valid JSON is not yet a vault: `"Karel"` decodes without complaint and holds no entries. */
    public function test_a_file_that_is_json_and_not_a_vault_is_refused(): void
    {
        file_put_contents($path = $this->dir.'/v.json', '"Karel"');

        try {
            (new JsonFile)->read($path);
            $this->fail('A file that is not a vault was read as one.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not a vault', $e->getMessage());
            $this->assertStringNotContainsString('Karel', $e->getMessage());
        }
    }

    /**
     * A vault a caller built by hand can have its fields the wrong way round, and
     * then the "type" is somebody's name. The message says a type is unknown and
     * does not say which.
     */
    public function test_an_unknown_type_is_not_quoted_back(): void
    {
        try {
            Vault::fromArray(['entries' => ['voornaam1mask' => ['type' => 'Karel Jansen', 'value' => 'voornaam']]]);
            $this->fail('A type Sluis does not know was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringNotContainsString('Karel', $e->getMessage());
        }
    }

    /** `"false"` is a string, and a string that is not empty is true to a cast. */
    public function test_strict_is_yes_or_no_and_nothing_that_merely_reads_like_it(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Vault::fromArray(['strict' => 'false', 'entries' => []]);
    }

    /** PHP makes the key `12` a number; everything that reads a token expects text. */
    public function test_a_token_that_reads_as_a_number_is_still_a_token(): void
    {
        $vault = Vault::fromArray(['entries' => ['12' => ['type' => 'voornaam', 'value' => 'Karel']]]);

        $this->assertSame(['12'], $vault->tokens());
        $this->assertSame('x Karel y', Sluis::nederlands()->unmask('x 12 y', $vault)->text);
    }
}
