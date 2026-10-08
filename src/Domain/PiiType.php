<?php

declare(strict_types=1);

namespace Sluis\Domain;

/**
 * What Sluis takes out, named in Dutch because the name ends up in the text the
 * model reads: `voornaam1mask` tells a Dutch model a first name stood there, and
 * a model that knows what stood there writes a reply that still fits around it.
 *
 * The order is what wins when two rules claim the same words. An iban beats a
 * telephone number because the digits of one contain the shape of the other, and
 * an address beats the first name inside it: `Jan Steenlaan 4` is a place, and
 * masking `Jan` out of the middle of it leaves a street nobody can read.
 */
enum PiiType: string
{
    case Iban = 'iban';
    case Bsn = 'bsn';
    case Kvk = 'kvk';
    case Email = 'email';
    case Url = 'url';
    case Telefoon = 'telefoon';
    case Postcode = 'postcode';
    case Adres = 'adres';
    case Stad = 'stad';
    case Organisatie = 'organisatie';
    case Naam = 'naam';
    case Achternaam = 'achternaam';
    case Voornaam = 'voornaam';

    /**
     * Something a recogniser is sure about and Sluis has no name for: a label a
     * model answers with that this version does not map. It exists so that an
     * unknown label is masked rather than dropped — a recogniser that quietly
     * skips what it cannot classify leaves exactly the value it just recognised.
     */
    case Onbekend = 'onbekend';

    /** Higher wins where two rules overlap. */
    public function precedence(): int
    {
        return match ($this) {
            self::Iban => 100,
            self::Bsn => 95,
            self::Kvk => 90,
            self::Email => 85,
            self::Url => 80,
            self::Telefoon => 75,
            self::Postcode => 70,
            self::Adres => 60,
            self::Stad => 50,
            self::Organisatie => 45,
            self::Naam => 40,
            self::Achternaam => 35,
            self::Voornaam => 30,
            self::Onbekend => 25,
        };
    }
}
