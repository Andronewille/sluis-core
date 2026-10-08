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

        Vault::fromArray(['version' => 1, 'entries' => ['voornaam1mask' => ['type' => 'voornaam', 'value' => ['Karel']]]]);
    }

    /**
     * Valid JSON is not yet a vault, and neither is a file that is no JSON at all:
     * an empty one, one cut short. Each is refused as what it is, and none of it
     * is quoted in the refusal.
     */
    public function test_a_file_that_is_not_a_vault_is_refused(): void
    {
        foreach (['"Karel"', '', '{"version":1,"entries":{"voornaam1mask":{"type":"voornaam","value":"Kar'] as $contents) {
            file_put_contents($path = $this->dir.'/v.json', $contents);
            $message = 'it was read as a vault';

            try {
                (new JsonFile)->read($path);
            } catch (RuntimeException $e) {
                $message = $e->getMessage();
            }

            $this->assertStringContainsString('not a vault', $message);
            $this->assertStringNotContainsString('Kar', $message);
        }
    }

    /**
     * Any JSON object used to read as a vault with nobody in it, and the next
     * masking run then wrote over the file: an answer somebody had saved, with
     * the only copy of its vault inside. A vault says that it is one.
     */
    public function test_json_that_does_not_say_it_is_a_vault_is_not_read_as_an_empty_one(): void
    {
        foreach ([[], ['name' => 'andronewille/sluis'], ['entries' => []], ['version' => 2, 'entries' => []]] as $data) {
            try {
                Vault::fromArray($data);
                $refused = false;
            } catch (InvalidArgumentException) {
                $refused = true;
            }

            $this->assertTrue($refused, json_encode($data).' was read as a vault');
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
            Vault::fromArray(['version' => 1, 'entries' => ['voornaam1mask' => ['type' => 'Karel Jansen', 'value' => 'voornaam']]]);
            $this->fail('A type Sluis does not know was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('a type Sluis does not know', $e->getMessage());
            $this->assertStringNotContainsString('Karel', $e->getMessage());
        }
    }

    /** `"false"` is a string, and a string that is not empty is true to a cast. */
    public function test_strict_is_yes_or_no_and_nothing_that_merely_reads_like_it(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Vault::fromArray(['version' => 1, 'strict' => 'false', 'entries' => []]);
    }

    /**
     * A token is what Sluis mints. A list where the entries belong has the tokens
     * 0 and 1, which stand in any text with a number in it, and an empty key is
     * a token that stands between every two characters.
     */
    public function test_an_entry_that_is_not_filed_under_a_mask_is_refused(): void
    {
        $entry = ['type' => 'voornaam', 'value' => 'Karel'];

        foreach ([[$entry], ['' => $entry], ['12' => $entry], ['Karel' => $entry]] as $entries) {
            try {
                Vault::fromArray(['version' => 1, 'entries' => $entries]);
                $refused = false;
            } catch (InvalidArgumentException $e) {
                $refused = str_contains($e->getMessage(), 'not filed under a mask');
            }

            $this->assertTrue($refused);
        }
    }

    /**
     * `touch` on a directory succeeds, and the `chmod` that follows it closed the
     * directory to its owner: every vault inside it gone until somebody put the
     * mode back, and a message that did not say so.
     */
    public function test_a_directory_is_never_taken_for_the_vault_file(): void
    {
        mkdir($directory = $this->dir.'/vaults', 0700);

        foreach ([new JsonFile, new Sealed('een lange wachtzin voor de kluis')] as $store) {
            try {
                $store->write($directory, Vault::empty());
                $message = 'it was written';
            } catch (RuntimeException $e) {
                $message = $e->getMessage();
            }

            clearstatcache();
            $this->assertStringContainsString('directory', $message);
            $this->assertSame('0700', substr(sprintf('%o', fileperms($directory)), -4));
        }

        rmdir($directory);
    }

    /**
     * The same refusal behind the seal. What opens with the key is not yet a
     * vault: a file sealed by something else with the same passphrase decodes,
     * and used to be read as a vault with nobody in it.
     */
    public function test_a_sealed_file_that_is_not_a_vault_is_refused(): void
    {
        $passphrase = 'een lange wachtzin voor de kluis';
        $salt = random_bytes(16);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            $passphrase,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );

        foreach (['"Karel"', '{"name":"Karel"}'] as $contents) {
            file_put_contents($path = $this->dir.'/v.sealed', Sealed::MAGIC.$salt.$nonce.sodium_crypto_secretbox($contents, $nonce, $key));
            $message = 'it was read as a vault';

            try {
                (new Sealed($passphrase))->read($path);
            } catch (RuntimeException $e) {
                $message = $e->getMessage();
            }

            $this->assertStringContainsString('not a vault', $message);
            $this->assertStringNotContainsString('Karel', $message);
        }
    }
}
