<?php

namespace Sluis\Tests;

use PHPUnit\Framework\TestCase;
use Sluis\Domain\PiiType;
use Sluis\Domain\Span;
use Sluis\Domain\Spans;

/** How two rules that claim the same words are made to agree. */
class SpansTest extends TestCase
{
    /** The type that outranks the other wins where one claim sits inside the other. */
    public function test_the_stronger_claim_wins_where_one_contains_the_other(): void
    {
        $spans = (new Spans(
            new Span(PiiType::Voornaam, 0, 'Jan'),
            new Span(PiiType::Adres, 0, 'Jan Steenlaan 4'),
        ))->resolved();

        $this->assertCount(1, $spans);
        $this->assertSame(PiiType::Adres, $spans->first()->type);
    }

    /**
     * Where the claims only partly overlap, what the loser still covers alone is
     * kept. `Maanstraat 1234` loses its digits to the postcode and keeps its street.
     */
    public function test_the_loser_keeps_what_it_still_covers_on_its_own(): void
    {
        $spans = (new Spans(
            new Span(PiiType::Adres, 0, 'Maanstraat 1234'),
            new Span(PiiType::Postcode, 11, '1234 AB'),
        ))->resolved();

        $this->assertCount(2, $spans);
        $this->assertSame(['Maanstraat', '1234 AB'], array_map(fn ($s) => $s->text, iterator_to_array($spans)));
        $this->assertSame([0, 11], array_map(fn ($s) => $s->start, iterator_to_array($spans)));
        $this->assertSame(PiiType::Adres, $spans->first()->type);
    }

    /**
     * A remainder that is not words is dropped. A telephone number that lost its
     * digits to a bsn leaves a single `0`, and a span over `0` masks every stray
     * zero in the mail.
     */
    public function test_a_remainder_that_is_not_words_is_dropped(): void
    {
        $spans = (new Spans(
            new Span(PiiType::Telefoon, 0, '0111222333'),
            new Span(PiiType::Bsn, 1, '111222333'),
        ))->resolved();

        $this->assertCount(1, $spans);
        $this->assertSame(PiiType::Bsn, $spans->first()->type);
    }

    public function test_it_answers_in_reading_order_whatever_order_it_was_given(): void
    {
        $spans = (new Spans(
            new Span(PiiType::Voornaam, 40, 'Bouwmeester'),
            new Span(PiiType::Email, 10, 'a@b.nl'),
            new Span(PiiType::Voornaam, 4, 'Sietske'),
        ))->resolved();

        $this->assertSame([4, 10, 40], array_map(fn ($s) => $s->start, iterator_to_array($spans)));
    }

    public function test_the_same_words_found_twice_are_one_span(): void
    {
        $spans = (new Spans(
            new Span(PiiType::Voornaam, 4, 'Sietske', 'aanhef'),
            new Span(PiiType::Voornaam, 4, 'Sietske', 'lijst'),
        ))->resolved();

        $this->assertCount(1, $spans);
    }
}
