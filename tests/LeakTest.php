<?php

declare(strict_types=1);

namespace Sluis\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sluis\Application\Anonymise;
use Sluis\Application\Ports\Recogniser;
use Sluis\Domain\CannotPlace;
use Sluis\Domain\PiiType;
use Sluis\Domain\Span;
use Sluis\Domain\Spans;
use Sluis\Sluis;

/**
 * The failures that matter, each one found by trying to break the tool rather than
 * by using it. A masker fails quietly: the text still reads, the run still says it
 * worked, and the one word that stayed is the one that mattered. Every case here
 * left something readable at some point, and each is named after what it was.
 */
class LeakTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function mail(): iterable
    {
        // The postcode outranks the address and used to take it with it, leaving
        // the street name standing in the middle of masked text.
        yield 'a street whose number runs into the postcode' => [
            'Wij zitten aan de Maanstraat 1234 AB Haasterdam.',
            ['Maanstraat', '1234 AB', 'Haasterdam'],
        ];

        // A mail out of a mailbox has \r\n endings and a blank line under the
        // sign-off. The frame rule allowed one \n and no \r, and found nothing.
        yield 'a sign-off with crlf endings' => ["Hey Sietske,\r\n\r\nTot morgen.\r\n\r\nMvg,\r\nBouwmeester\r\n", ['Sietske', 'Bouwmeester']];
        yield 'a sign-off with a blank line under it' => ["Met vriendelijke groet,\n\nSietske Bouwmeester", ['Sietske', 'Bouwmeester']];
        yield 'a signature block' => ["Groeten,\nSietske Bouwmeester\n06-12345678\ns.bouwmeester@voorbeeld.nl", ['Sietske', 'Bouwmeester', '06-12345678', 's.bouwmeester@voorbeeld.nl']];
        yield 'a name mentioned again further down' => ['Hey Sietske, de monteur vroeg naar Sietske. Mvg, Bouwmeester', ['Sietske', 'Bouwmeester']];
        yield 'a full address' => ['Kerkweg 12a, 3512 JE Utrecht', ['Kerkweg 12a', '3512 JE', 'Utrecht']];
        yield 'an iban and a bsn in one line' => ['NL91 ABNA 0417 1643 00 en 111222333', ['NL91 ABNA 0417 1643 00', '111222333']];
    }

    /** @param list<string> $values */
    #[DataProvider('mail')]
    public function test_nothing_readable_is_left_in_it(string $text, array $values): void
    {
        $masked = Sluis::nederlands()->mask($text);

        foreach ($values as $value) {
            $this->assertStringNotContainsString($value, $masked->text, "left in: {$value}");
        }

        $this->assertSame($text, Sluis::nederlands()->unmask($masked->text, $masked->vault)->text);
    }

    /**
     * A recogniser that points at the wrong bytes is the one way a span can lie,
     * and the ONNX adapter works its offsets out rather than being told them. It is
     * refused at the boundary: masking on a wrong offset replaces the wrong words
     * and leaves the value, and the run reports success.
     */
    public function test_a_recogniser_that_points_at_the_wrong_words_is_refused(): void
    {
        $liar = new class implements Recogniser
        {
            public function recognise(string $text): Spans
            {
                return new Spans(new Span(PiiType::Voornaam, 3, 'Sietske', 'de leugenaar'));
            }
        };

        $this->expectException(CannotPlace::class);
        $this->expectExceptionMessage('not where it said it was');

        (new Anonymise($liar))('Hey Sietske, tot morgen.');
    }

    /**
     * Text that already reads like a masked document, in any case: restoring is
     * case-insensitive, so a token the document already uses has to be skipped
     * whatever case it is written in, or the document's own word is overwritten.
     */
    public function test_a_token_the_document_already_uses_in_another_case_is_left_alone(): void
    {
        $text = 'Hey Sietske, het staat in Voornaam1mask. Mvg, Bouwmeester';

        $sluis = Sluis::nederlands();
        $masked = $sluis->mask($text);

        $this->assertStringContainsString('Voornaam1mask.', $masked->text);
        $this->assertSame($text, $sluis->unmask($masked->text, $masked->vault)->text);
    }

    /** An ordinary word in the place of a name is not a name, and would be masked everywhere. */
    public function test_it_does_not_read_the_next_sentence_as_a_signature(): void
    {
        $masked = Sluis::nederlands()->mask("Met vriendelijke groet,\n\nDe monteur komt morgen langs. De sleutel ligt klaar.");

        $this->assertStringContainsString('De monteur komt morgen langs. De sleutel', $masked->text);
    }
}
