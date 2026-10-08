<?php

declare(strict_types=1);

namespace Sluis\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Sluis\Domain\PiiType;
use Sluis\Domain\Unreadable;
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
     * A kind that is left out is as if Sluis had no rule for it, so what it would
     * have covered is open to the kinds that remain. That costs a mask in the
     * middle of a street, and it is the only reading under which nothing the
     * caller asked for is left standing: see the three tests below.
     */
    public function test_a_kind_that_is_left_out_gives_up_the_words_it_outranked(): void
    {
        $masked = Sluis::nederlands()->without(PiiType::Adres)->mask('Wij zitten aan de Jan Steenlaan 4. Groet, Jan');

        $this->assertSame('Wij zitten aan de voornaam1mask Steenlaan 4. Groet, voornaam1mask', $masked->text);
    }

    /**
     * `naar Jeroen` reads as a town to the rule that looks for a cue, and a town
     * outranks a first name. With towns left out, that claim won the sign-off
     * and was then dropped, and the name stayed in both places.
     */
    public function test_a_name_a_kind_that_is_left_out_also_claimed_is_still_masked(): void
    {
        $masked = Sluis::nederlands()
            ->without(PiiType::Url, PiiType::Stad)
            ->mask('Hoi Sanne, stuur de offerte maar naar Jeroen. Groet, Jeroen');

        $this->assertStringNotContainsString('Jeroen', $masked->text);
        $this->assertStringNotContainsString('Sanne', $masked->text);
    }

    /**
     * One telephone number in eleven passes the elfproef, and a bsn outranks a
     * telephone number. Asked for telephone numbers only, Sluis found a bsn,
     * left it out, and returned the number untouched.
     */
    public function test_a_number_that_reads_as_two_kinds_is_masked_as_the_one_that_was_asked_for(): void
    {
        $text = 'Bel mij op +31 612345671 of mail.';

        $this->assertSame('Bel mij op telefoon1mask of mail.', Sluis::nederlands()->only(PiiType::Telefoon)->mask($text)->text);
        $this->assertSame('Bel mij op telefoon1mask of mail.', Sluis::nederlands()->without(PiiType::Bsn)->mask($text)->text);
    }

    /**
     * What the vault took out is not readable in what comes back, with a choice
     * as without one. An address that swallowed the line above it, and a full
     * name that outranked the first name in it, both used to leave the name.
     */
    public function test_nothing_the_vault_holds_is_readable_when_kinds_are_left_out(): void
    {
        $signed = Sluis::nederlands()->without(PiiType::Adres)->mask("Met vriendelijke groet,\n\nBob de Vries\nKerkweg 12\n1234 AB Utrecht");
        $greeted = Sluis::nederlands()->only(PiiType::Voornaam)->mask('Hey Karel Jansen, bel 0612345678. Mvg, Karel');

        $this->assertStringNotContainsString('Bob', $signed->text);
        $this->assertStringNotContainsString('Karel', $greeted->text);
        $this->assertStringContainsString('0612345678', $greeted->text);
    }

    /** `without(Stad)->only(Stad, Telefoon)` used to mask telephone numbers and say nothing about the towns. */
    public function test_only_cannot_bring_back_a_kind_that_was_already_left_out(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('stad');

        Sluis::nederlands()->without(PiiType::Stad)->only(PiiType::Stad, PiiType::Telefoon);
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

    /**
     * The reverse path reads a failed pattern the way the forward path did: as
     * nothing to do. An answer with one bad byte in it came back with every mask
     * still standing and no stray reported.
     */
    public function test_an_answer_that_cannot_be_read_is_not_handed_back_as_restored(): void
    {
        $sluis = Sluis::nederlands();
        $masked = $sluis->mask(self::MAIL);

        $this->expectException(Unreadable::class);

        (void) $sluis->unmask("Beste voornaam1mask, het caf\xE9 is open.", $masked->vault);
    }

    /**
     * A name that signs every mail of a long thread is found once for every mail,
     * and each of those used to ask where the name stood: a thread of 200 kB ran
     * out of memory before it was masked. This one is far smaller and would
     * still have been thousands of spans where there are hundreds.
     */
    public function test_a_long_thread_is_masked_and_comes_back(): void
    {
        $thread = str_repeat("Hey Karel,\n\nBel 0612345678 of mail bob@voorbeeld.nl.\n\nMet vriendelijke groet,\nBob\n\n", 300);
        $sluis = Sluis::nederlands();

        $masked = $sluis->mask($thread);

        $this->assertStringNotContainsString('Karel', $masked->text);
        $this->assertSame(['voornaam' => 600, 'telefoon' => 300, 'email' => 300], $masked->found);
        $this->assertSame($thread, $sluis->unmask($masked->text, $masked->vault)->text);
    }
}
