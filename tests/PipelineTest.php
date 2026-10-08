<?php

declare(strict_types=1);

namespace Sluis\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Sluis\Domain\PiiType;
use Sluis\Infrastructure\Recognisers\Gazetteer;
use Sluis\Infrastructure\Recognisers\Patterns;
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

    /**
     * What is sensitive enough to take out is the caller's decision, and it should
     * not take assembling six recognisers by hand to make it.
     */
    public function test_only_masks_the_kinds_it_was_given(): void
    {
        $masked = Sluis::nederlands()->only(PiiType::Telefoon, PiiType::Stad)->mask(self::MAIL);

        $this->assertSame(
            'Hey Karel, mijn boot kost 250 euro, te bezichtigen aan de Maanstraat 123 in stad1mask. '
            .'ik ben te bereiken op telefoon1mask. Mvg, Bob',
            $masked->text,
        );
        $this->assertSame(['stad' => 1, 'telefoon' => 1], $masked->found);
    }

    public function test_without_masks_everything_but_the_kinds_it_was_given(): void
    {
        $masked = Sluis::nederlands()->without(PiiType::Adres, PiiType::Stad)->mask(self::MAIL);

        $this->assertSame(
            'Hey voornaam1mask, mijn boot kost 250 euro, te bezichtigen aan de Maanstraat 123 in Haasterdam. '
            .'ik ben te bereiken op telefoon1mask. Mvg, voornaam2mask',
            $masked->text,
        );
    }

    /**
     * The choice is made after the overlaps are settled. `Jan Steenlaan 4` is an
     * address; leaving addresses alone and then masking the first name the address
     * had outranked would put a mask in the middle of a street.
     */
    public function test_a_kind_that_is_left_alone_still_keeps_what_it_outranked(): void
    {
        $text = 'Wij zitten aan de Jan Steenlaan 4.';

        $this->assertSame($text, Sluis::nederlands()->without(PiiType::Adres)->mask($text)->text);
    }

    /**
     * The choice belongs to the Sluis and not to the recognisers it had when the
     * choice was made: one added afterwards is held to it as well, or `only()`
     * would mean something different depending on where in the line it stood.
     */
    public function test_the_choice_holds_for_a_recogniser_added_after_it(): void
    {
        $cities = new Gazetteer(PiiType::Stad, ['Haasterdam']);
        $text = 'Haasterdam is mooi, bel 0612345678.';

        $masked = (new Sluis(new Patterns))->only(PiiType::Telefoon)->plus($cities)->mask($text);

        $this->assertSame('Haasterdam is mooi, bel telefoon1mask.', $masked->text);
    }

    /** A Sluis that masks nothing reports success on every mail it lets through. */
    public function test_narrowing_down_to_nothing_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Sluis::nederlands()->only(PiiType::Telefoon)->without(PiiType::Telefoon);
    }

    /**
     * The same, when the name stands somewhere else as well. A value found once is
     * masked everywhere, and that used to reach into the street that was being
     * left alone: the choice was made before the second sighting was looked for.
     */
    public function test_a_kind_that_is_left_alone_is_left_alone_when_its_words_are_masked_elsewhere(): void
    {
        $sluis = Sluis::nederlands();

        $street = $sluis->without(PiiType::Adres)->mask('Wij zitten aan de Jan Steenlaan 4. Groet, Jan');
        $link = $sluis->without(PiiType::Url)->mask('Hey Karel, zie https://voorbeeld.nl/karel/foto. Mvg, Bob');

        $this->assertSame('Wij zitten aan de Jan Steenlaan 4. Groet, voornaam1mask', $street->text);
        $this->assertSame('Hey voornaam1mask, zie https://voorbeeld.nl/karel/foto. Mvg, voornaam2mask', $link->text);
    }

    /**
     * 0.1 took `strict` here. PHP hands a function an argument it no longer
     * declares without complaint, so the caller who still passes it would get
     * one spelling back where two went in, and no error to say so.
     */
    public function test_an_argument_it_no_longer_takes_is_refused_rather_than_dropped(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Vault::empty(strict: true)');

        call_user_func([Sluis::class, 'nederlands'], true);
    }
}
