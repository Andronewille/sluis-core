<?php

declare(strict_types=1);

namespace Sluis\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sluis\Domain\PiiType;
use Sluis\Infrastructure\Recognisers\Addresses;
use Sluis\Infrastructure\Recognisers\Frames;
use Sluis\Infrastructure\Recognisers\Gazetteer;
use Sluis\Infrastructure\Recognisers\Patterns;
use Sluis\Infrastructure\Recognisers\Places;

/**
 * What the core finds without a model. Every rule here is exact or nearly so:
 * a format with a check digit, a Dutch street suffix, the frame a letter is
 * written in. The prose a model is needed for is the ONNX adapter's business.
 */
class RecognisersTest extends TestCase
{
    /** @return iterable<string, array{string, PiiType, string}> */
    public static function patterns(): iterable
    {
        yield 'email' => ['Mail naar jan@voorbeeld.nl graag.', PiiType::Email, 'jan@voorbeeld.nl'];
        yield 'mobile, grouped' => ['Bel 06-12345678 vanavond.', PiiType::Telefoon, '06-12345678'];
        yield 'mobile, plain' => ['Bel 0612345678 vanavond.', PiiType::Telefoon, '0612345678'];
        yield 'mobile, international' => ['Bel +31 6 12345678 vanavond.', PiiType::Telefoon, '+31 6 12345678'];
        yield 'mobile, international with the trunk zero kept' => ['Bel +31 (0)6 12345678 vanavond.', PiiType::Telefoon, '+31 (0)6 12345678'];
        yield 'mobile, spaced in pairs' => ['Bel 06 12 34 56 78 vanavond.', PiiType::Telefoon, '06 12 34 56 78'];
        yield 'mobile, spaced in threes' => ['Bel 06 123 456 78 vanavond.', PiiType::Telefoon, '06 123 456 78'];
        yield 'mobile, dotted' => ['Bel 06.12345678 vanavond.', PiiType::Telefoon, '06.12345678'];
        yield 'landline' => ['Bel 030-1234567 vanavond.', PiiType::Telefoon, '030-1234567'];
        yield 'landline, area code in brackets' => ['Bel (030) 123 45 67 vanavond.', PiiType::Telefoon, '(030) 123 45 67'];
        yield 'iban, lower case' => ['Op nl91abna0417164300 gestort.', PiiType::Iban, 'nl91abna0417164300'];
        yield 'postcode with space' => ['Het is 3512 JE hier.', PiiType::Postcode, '3512 JE'];
        yield 'postcode without space' => ['Het is 3512JE hier.', PiiType::Postcode, '3512JE'];
        yield 'iban' => ['Op NL91ABNA0417164300 gestort.', PiiType::Iban, 'NL91ABNA0417164300'];
        yield 'iban, grouped' => ['Op NL91 ABNA 0417 1643 00 gestort.', PiiType::Iban, 'NL91 ABNA 0417 1643 00'];
        yield 'bsn' => ['Mijn bsn is 111222333, klopt dat?', PiiType::Bsn, '111222333'];
        yield 'url' => ['Zie https://voorbeeld.nl/boot voor fotos.', PiiType::Url, 'https://voorbeeld.nl/boot'];
    }

    #[DataProvider('patterns')]
    public function test_it_finds_a_format(string $text, PiiType $type, string $expected): void
    {
        $spans = (new Patterns)->recognise($text)->resolved();

        $this->assertCount(1, $spans, 'found '.count($spans)." spans in: {$text}");
        $this->assertSame($type, $spans->first()->type);
        $this->assertSame($expected, $spans->first()->text);
    }

    /**
     * A check digit is the whole point of reading these as formats rather than as
     * digits: nine digits that fail the elfproef are a customer number, not a bsn,
     * and an iban that fails mod-97 is a typo someone still has to read.
     */
    public function test_it_leaves_a_number_that_fails_its_own_check_digit(): void
    {
        $this->assertCount(0, (new Patterns)->recognise('Ordernummer 123456789 is verzonden.'));
        $this->assertCount(0, (new Patterns)->recognise('Rekening NL91ABNA0417164301 bestaat niet.'));
    }

    /**
     * A date is eight digits and a telephone number is ten, which is the only thing
     * telling them apart once a rule allows a separator between any two digits.
     */
    public function test_it_leaves_a_date_that_looks_like_a_number(): void
    {
        $this->assertCount(0, (new Patterns)->recognise('Op 01-02-2024 geleverd, en op 1-2-2024 besteld.'));
    }

    /**
     * A rule that may end on a space can eat the word after it. This one did: it
     * read `nl91abna0417164300 gestort` as an iban and two syllables, failed mod-97
     * on all of it, and reported no iban at all.
     */
    public function test_a_format_does_not_swallow_the_word_after_it(): void
    {
        foreach (['Op NL91ABNA0417164300 gestort.', 'Op nl91 abna 0417 1643 00 euro gestort.'] as $text) {
            $spans = (new Patterns)->recognise($text)->resolved();

            $this->assertCount(1, $spans, 'found '.count($spans)." spans in: {$text}");
            $this->assertSame(PiiType::Iban, $spans->first()->type);
            $this->assertStringNotContainsString('gestort', $spans->first()->text);
        }
    }

    /** Money is not personal data, and a masked amount makes the reply nonsense. */
    public function test_it_leaves_money_and_plain_numbers_alone(): void
    {
        $this->assertCount(0, (new Patterns)->recognise('De boot kost 250 euro, inclusief 21% btw over 1250.'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function addresses(): iterable
    {
        yield 'street and number' => ['te bezichtigen aan de Maanstraat 123 in', 'Maanstraat 123'];
        yield 'number with a letter' => ['woont op Kerkweg 12a nu', 'Kerkweg 12a'];
        yield 'number with a toevoeging' => ['woont op Kerkweg 12 bis nu', 'Kerkweg 12 bis'];
        yield 'two-word street' => ['aan de Jan Steenlaan 4 gezien', 'Jan Steenlaan 4'];
        yield 'plein' => ['op het Waterlooplein 8 gezien', 'Waterlooplein 8'];
        yield 'singel without a number' => ['ergens aan de Oudegracht gezien', 'Oudegracht'];
    }

    #[DataProvider('addresses')]
    public function test_it_finds_a_dutch_address(string $text, string $expected): void
    {
        $spans = (new Addresses)->recognise($text);

        $this->assertCount(1, $spans, 'found '.count($spans)." spans in: {$text}");
        $this->assertSame(PiiType::Adres, $spans->first()->type);
        $this->assertSame($expected, $spans->first()->text);
    }

    /** The article in front of a street is not part of the address, and a reply needs it. */
    public function test_it_does_not_swallow_the_words_around_the_address(): void
    {
        $spans = (new Addresses)->recognise('te bezichtigen aan de Maanstraat 123 in Haasterdam');

        $this->assertSame('Maanstraat 123', $spans->first()->text);
    }

    /**
     * How a letter opens and closes gives away a name without any list: a word
     * after "Hey" and a word after "Mvg," are a person, whatever the word is.
     * It is the rule that catches the name no gazetteer has.
     */
    public function test_it_reads_a_name_out_of_the_frame_of_a_letter(): void
    {
        $spans = (new Frames)->recognise('Hey Karel, alles goed? Mvg, Bob');

        $this->assertCount(2, $spans);
        $this->assertSame(['Karel', 'Bob'], array_map(fn ($s) => $s->text, iterator_to_array($spans)));
        $this->assertSame([PiiType::Voornaam, PiiType::Voornaam], array_map(fn ($s) => $s->type, iterator_to_array($spans)));
    }

    /** @return iterable<string, array{string, string, PiiType}> */
    public static function frames(): iterable
    {
        yield 'hoi' => ['Hoi Sanne, even dit.', 'Sanne', PiiType::Voornaam];
        yield 'beste' => ["Beste Willem,\nHierbij.", 'Willem', PiiType::Voornaam];
        yield 'geachte heer' => ['Geachte heer De Vries, hierbij.', 'De Vries', PiiType::Achternaam];
        yield 'full name signed off' => ["Met vriendelijke groet,\nBob de Vries", 'Bob de Vries', PiiType::Naam];
        yield 'groetjes' => ['Groetjes, Fleur', 'Fleur', PiiType::Voornaam];
        yield 'signed off on the next line' => ["Mvg,\nKarel", 'Karel', PiiType::Voornaam];
    }

    #[DataProvider('frames')]
    public function test_it_reads_the_frames_a_dutch_letter_uses(string $text, string $expected, PiiType $type): void
    {
        $spans = (new Frames)->recognise($text);

        $this->assertCount(1, $spans, 'found '.count($spans)." spans in: {$text}");
        $this->assertSame($expected, $spans->first()->text);
        $this->assertSame($type, $spans->first()->type);
    }

    /** "Hey" at the start of a sentence in the middle of a mail is not a salutation. */
    /**
     * A mail is addressed to everyone it names. Requiring the comma straight after
     * one name made this rule match nothing at all here, and mask neither person.
     */
    public function test_it_reads_every_name_in_the_salutation(): void
    {
        $spans = (new Frames)->recognise('Hoi Sietske en Bouwmeester, tot morgen.');

        $this->assertCount(2, $spans);
        $this->assertSame(['Sietske', 'Bouwmeester'], array_map(fn ($s) => $s->text, iterator_to_array($spans)));
    }

    /** @return iterable<string, array{string}> */
    public static function realLineEndings(): iterable
    {
        yield 'crlf, on the next line' => ["Mvg,\r\nSietske"];
        yield 'lf, under a blank line' => ["Met vriendelijke groet,\n\nSietske"];
        yield 'crlf, under a blank line' => ["Met vriendelijke groet,\r\n\r\nSietske"];
        yield 'on the same line' => ['Mvg, Sietske'];
    }

    /**
     * Mail out of a mailbox has `\r\n` endings and a blank line under the sign-off.
     * A rule that allowed one `\n` and no `\r` found the name in a fixture and in
     * nothing that came out of an inbox.
     */
    #[DataProvider('realLineEndings')]
    public function test_it_reads_the_name_under_a_sign_off_however_the_lines_end(string $text): void
    {
        $spans = (new Frames)->recognise($text);

        $this->assertCount(1, $spans, 'found '.count($spans).' spans');
        $this->assertSame('Sietske', $spans->first()->text);
    }

    /**
     * A name stops at the end of its line. `\s` between the words of a name crosses
     * a newline, so the company under the signature became part of the surname —
     * reversibly, and with the shape of the mail quietly gone.
     */
    public function test_a_name_does_not_run_on_into_the_next_line(): void
    {
        $spans = (new Frames)->recognise("Met vriendelijke groet,\n\nSietske Bouwmeester\nBootverhuur Haasterdam");

        $this->assertCount(1, $spans);
        $this->assertSame('Sietske Bouwmeester', $spans->first()->text);
    }

    /** The gap under a sign-off must not reach into the next sentence. */
    public function test_it_does_not_read_the_next_sentence_as_a_name(): void
    {
        $this->assertCount(0, (new Frames)->recognise("Met vriendelijke groet,\n\nDe monteur komt morgen."));
    }

    public function test_it_does_not_read_a_name_where_there_is_no_frame(): void
    {
        $this->assertCount(0, (new Frames)->recognise('De monteur komt morgen langs om te kijken.'));
    }

    /**
     * A place the list has not heard of is the case that matters: Haasterdam is
     * not a Dutch town, and the mail still has to leave without it. "in" plus a
     * capitalised word, where the sentence is about where something is, is the
     * cue — deliberately shallow, and the reason the ONNX adapter exists.
     */
    public function test_it_reads_a_place_it_has_never_heard_of_from_the_cue_in_front_of_it(): void
    {
        $spans = (new Places)->recognise('te bezichtigen in Haasterdam vanmiddag');

        $this->assertCount(1, $spans);
        $this->assertSame('Haasterdam', $spans->first()->text);
        $this->assertSame(PiiType::Stad, $spans->first()->type);
    }

    /** A Dutch address writes the town straight after the postcode, in whatever case. */
    public function test_it_reads_the_town_after_a_postcode(): void
    {
        foreach (['Adres: 3512 JE Utrecht', 'Adres: 3512 JE utrecht'] as $text) {
            $spans = (new Places)->recognise($text);

            $this->assertCount(1, $spans, 'found '.count($spans)." spans in: {$text}");
            $this->assertSame(PiiType::Stad, $spans->first()->type);
        }
    }

    public function test_it_does_not_read_a_month_or_a_weekday_as_a_place(): void
    {
        $this->assertCount(0, (new Places)->recognise('Het kan in Januari, of in Maart.'));
        $this->assertCount(0, (new Places)->recognise('Het kan in Nederland overal.'));
    }

    public function test_a_gazetteer_finds_what_it_was_given(): void
    {
        $spans = (new Gazetteer(PiiType::Stad, ['Haasterdam', 'Utrecht']))->recognise('van Utrecht naar Haasterdam')->resolved();

        $this->assertCount(2, $spans);
        $this->assertSame(['Utrecht', 'Haasterdam'], array_map(fn ($s) => $s->text, iterator_to_array($spans)));
    }

    /** A gazetteer matches words, never parts of them: Ede is not in Edelweiss. */
    public function test_a_gazetteer_does_not_match_inside_a_word(): void
    {
        $this->assertCount(0, (new Gazetteer(PiiType::Stad, ['Ede']))->recognise('Een bos Edelweiss meegenomen.'));
    }

    /**
     * A list that is not there used to read as an empty one, which finds nothing
     * and reports success: one wrong letter in the path to a list of surnames and
     * every one of them goes to the model.
     */
    public function test_a_gazetteer_refuses_a_list_that_is_not_there(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('word list');

        Gazetteer::fromFile(PiiType::Achternaam, __DIR__.'/achternamen-die-er-niet-zijn.txt');
    }

    /**
     * A file that is there and holds no word finds as little as one that is not
     * there: a download that stopped, a list that is all comments, a file saved
     * as UTF-16.
     */
    public function test_a_gazetteer_refuses_a_list_with_no_words_in_it(): void
    {
        $path = sys_get_temp_dir().'/sluis-lijst-'.bin2hex(random_bytes(6)).'.txt';

        try {
            foreach (['', "# achternamen\n# nog te vullen\n", "\xFF\xFEd\x00e\x00 \x00V\x00r\x00i\x00e\x00s\x00"] as $contents) {
                file_put_contents($path, $contents);

                try {
                    Gazetteer::fromFile(PiiType::Achternaam, $path);
                    $refused = false;
                } catch (RuntimeException) {
                    $refused = true;
                }

                $this->assertTrue($refused);
            }
        } finally {
            unlink($path);
        }
    }

    /** The byte order mark some editors write made the first name on the list one that no text contains. */
    public function test_a_gazetteer_reads_past_a_byte_order_mark(): void
    {
        $path = sys_get_temp_dir().'/sluis-lijst-'.bin2hex(random_bytes(6)).'.txt';
        file_put_contents($path, "\u{FEFF}de Vries\nBouwmeester\n");

        try {
            $spans = Gazetteer::fromFile(PiiType::Achternaam, $path)->recognise('Bel Sanne de Vries of Petra Bouwmeester.');
        } finally {
            unlink($path);
        }

        $this->assertCount(2, $spans);
    }
}
