<?php

declare(strict_types=1);

namespace Sluis\Tests;

use PHPUnit\Framework\TestCase;
use Sluis\Domain\PiiType;
use Sluis\Sluis;

/**
 * The two-sided pipeline, on the mail that started this: text goes into an AI
 * system with the person taken out of it, and comes back with the person put
 * back. Both halves are checked here, because either one alone is useless.
 */
class PipelineTest extends TestCase
{
    private const MAIL = 'Hey Karel, mijn boot kost 250 euro, te bezichtigen aan de Maanstraat 123 in Haasterdam. ik ben te bereiken op 0612345678. Mvg, Bob';

    public function test_it_masks_the_mail_and_leaves_the_money_alone(): void
    {
        $masked = Sluis::nederlands()->mask(self::MAIL);

        $this->assertSame(
            'Hey voornaam1mask, mijn boot kost 250 euro, te bezichtigen aan de adres1mask in stad1mask. '
            .'ik ben te bereiken op telefoon1mask. Mvg, voornaam2mask',
            $masked->text,
        );
    }

    public function test_the_mail_comes_back_exactly_as_it_was(): void
    {
        $sluis = Sluis::nederlands();

        $masked = $sluis->mask(self::MAIL);
        $restored = $sluis->unmask($masked->text, $masked->vault);

        $this->assertSame(self::MAIL, $restored->text);
        $this->assertSame([], $restored->unrestored);
        $this->assertSame([], $restored->stray);
    }

    /** What the AI does to the text between the two halves: it rewrites it. */
    public function test_it_restores_into_text_the_model_rewrote(): void
    {
        $sluis = Sluis::nederlands();
        $masked = $sluis->mask(self::MAIL);

        $rewritten = "Beste voornaam1mask,\n\nDe boot staat op adres1mask in stad1mask en kost 250 euro. "
            ."Bel telefoon1mask.\n\nMet vriendelijke groet,\nvoornaam2mask";

        $restored = $sluis->unmask($rewritten, $masked->vault);

        $this->assertStringContainsString('Beste Karel,', $restored->text);
        $this->assertStringContainsString('op Maanstraat 123 in Haasterdam', $restored->text);
        $this->assertStringContainsString('Bel 0612345678.', $restored->text);
        $this->assertStringContainsString("groet,\nBob", $restored->text);
    }

    /** A model that capitalised a token at the start of a sentence still gets its value back. */
    public function test_a_token_is_restored_whatever_case_the_model_wrote_it_in(): void
    {
        $sluis = Sluis::nederlands();
        $masked = $sluis->mask(self::MAIL);

        $restored = $sluis->unmask('Voornaam1mask belt over adres1mask.', $masked->vault);

        $this->assertSame('Karel belt over Maanstraat 123.', $restored->text);
    }

    /** Nothing the vault holds may still be readable in the text that leaves. */
    public function test_no_value_survives_in_the_masked_text(): void
    {
        $masked = Sluis::nederlands()->mask(self::MAIL);

        foreach (['Karel', 'Bob', 'Maanstraat 123', 'Haasterdam', '0612345678'] as $value) {
            $this->assertStringNotContainsString($value, $masked->text);
        }
    }

    public function test_the_vault_names_what_each_token_is(): void
    {
        $masked = Sluis::nederlands()->mask(self::MAIL);

        $this->assertSame([
            'voornaam1mask' => PiiType::Voornaam,
            'adres1mask' => PiiType::Adres,
            'stad1mask' => PiiType::Stad,
            'telefoon1mask' => PiiType::Telefoon,
            'voornaam2mask' => PiiType::Voornaam,
        ], $masked->vault->entries());
    }

    /**
     * The same person twice is the same token, or the model reads two people
     * where the mail had one — and writes a reply to the wrong one.
     */
    public function test_one_value_is_one_token_however_often_it_appears(): void
    {
        $masked = Sluis::nederlands()->mask('Hey Karel, Karel heeft gebeld. Mvg, Bob');

        $this->assertSame('Hey voornaam1mask, voornaam1mask heeft gebeld. Mvg, voornaam2mask', $masked->text);
        $this->assertCount(2, $masked->vault->entries());
    }

    /**
     * A value found in one place is masked in every place, even where no rule
     * fired: the recogniser only has to notice a name once for the signature at
     * the bottom to stop being a leak.
     */
    public function test_a_value_found_once_is_masked_everywhere(): void
    {
        $masked = Sluis::nederlands()->mask('Hey Karel, de monteur vroeg naar Karel. Groetjes, Bob');

        $this->assertStringNotContainsString('Karel', $masked->text);
    }

    /** A vault carried between documents keeps one person on one token. */
    public function test_a_vault_carried_over_keeps_the_same_token_for_the_same_person(): void
    {
        $sluis = Sluis::nederlands();

        $first = $sluis->mask('Hey Karel, tot morgen. Mvg, Bob');
        $second = $sluis->mask('Hey Karel, het is gelukt. Mvg, Bob', $first->vault);

        $this->assertSame('Hey voornaam1mask, het is gelukt. Mvg, voornaam2mask', $second->text);
        $this->assertCount(2, $second->vault->entries());
    }

    /**
     * Text that already reads like a masked document does not get its own words
     * overwritten: a token is only minted onto a string the text does not use.
     */
    public function test_it_never_mints_a_token_the_text_already_contains(): void
    {
        $sluis = Sluis::nederlands();
        $text = 'Hey Karel, het staat in voornaam1mask. Mvg, Bob';

        $masked = $sluis->mask($text);

        $this->assertStringContainsString('voornaam1mask.', $masked->text);
        $this->assertStringNotContainsString('Karel', $masked->text);
        $this->assertSame($text, $sluis->unmask($masked->text, $masked->vault)->text);
    }
}
