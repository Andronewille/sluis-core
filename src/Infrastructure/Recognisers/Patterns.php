<?php

declare(strict_types=1);

namespace Sluis\Infrastructure\Recognisers;

use Sluis\Application\Ports\Recogniser;
use Sluis\Domain\PiiType;
use Sluis\Domain\Span;
use Sluis\Domain\Spans;

/**
 * The part of personal data that has a format, read as its format. An iban, a
 * bsn and a kvk number carry their own check digit, so these are not guesses: a
 * number that fails its check is a customer number or a typo, and masking it
 * damages a mail for nothing. Money is not here on purpose — an amount is not
 * personal data, and a masked amount makes the reply nonsense.
 */
final readonly class Patterns implements Recogniser
{
    /** @var array<string, array{PiiType, string}> */
    private const RULES = [
        'email' => [PiiType::Email, '/(?<![\p{L}\p{N}_.+-])[\p{L}\p{N}._%+-]+@[\p{L}\p{N}-]+(?:\.[\p{L}\p{N}-]+)*\.[a-z]{2,}(?![\p{L}\p{N}])/iu'],
        'url' => [PiiType::Url, '#\bhttps?://[^\s<>"\'\)\]]+#i'],
        // Mod-97 is what says whether this is an account number, so the case does not
        // have to: a lowercase iban is a person typing.
        //
        // The groups are exact, and that is the whole lesson of this rule. A version
        // that allowed an optional space before a group of two to four characters
        // read `nl91abna0417164300 gestort` as the iban plus `ges` plus `tort`,
        // failed mod-97 on the lot, and reported no iban at all. Uppercase-only had
        // been hiding it, because Dutch words are not uppercase. A grouped iban is
        // groups of four with a tail of one to three — never four, or it would have
        // been another group — and an ungrouped one holds no spaces at all.
        'iban' => [PiiType::Iban, '/(?<![\p{L}\p{N}])[A-Za-z]{2}\d{2}[A-Za-z0-9]{11,30}(?![\p{L}\p{N}])/u'],
        'iban.gegroepeerd' => [PiiType::Iban, '/(?<![\p{L}\p{N}])[A-Za-z]{2}\d{2}(?: [A-Za-z0-9]{4}){2,7}(?: [A-Za-z0-9]{1,3})?(?![\p{L}\p{N}])/u'],
        'bsn' => [PiiType::Bsn, '/(?<![\p{L}\p{N}])\d{9}(?![\p{L}\p{N}])/u'],
        'kvk' => [PiiType::Kvk, '/\bkvk[- ]?(?:nummer|nr\.?)?[:\s]*\K\d{8}(?![\p{L}\p{N}])/iu'],
        'postcode' => [PiiType::Postcode, '/(?<![\p{L}\p{N}])[1-9]\d{3}\s?[A-Z]{2}(?![\p{L}\p{N}])/u'],
        // A Dutch number is ten digits, and where the separators fall is a matter of
        // habit: 0612345678, 06-12345678, 06 12 34 56 78, 030 123 45 67, 06.12345678.
        // So the rule counts digits and allows a separator between any two of them,
        // rather than trying to enumerate the groupings people use — the first
        // version of this knew two of them and missed the rest.
        //
        // The count is what keeps it honest: `01-02-2024` is eight digits and stays a
        // date. Every match is counted again in `passesItsOwnCheck`.
        'telefoon' => [PiiType::Telefoon, '/(?<![\p{L}\p{N}])0\d(?:[\s.-]?\d){8}(?![\p{L}\p{N}])/u'],
        'telefoon.haakjes' => [PiiType::Telefoon, '/(?<![\p{L}\p{N}])\(0\d{1,3}\)[\s.-]?\d(?:[\s.-]?\d){5,7}(?![\p{L}\p{N}])/u'],
        'telefoon.internationaal' => [PiiType::Telefoon, '/(?<![\p{L}\p{N}])(?:\+31|0031)[\s.-]?\(?0?\)?[\s.-]?\d(?:[\s.-]?\d){8}(?![\p{L}\p{N}])/u'],
    ];

    public function recognise(string $text): Spans
    {
        $spans = Spans::none();

        foreach (self::RULES as $name => [$type, $pattern]) {
            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }

            foreach ($matches[0] as [$found, $at]) {
                $found = $this->trimmed($found, $type);

                if ($this->passesItsOwnCheck($found, $type)) {
                    $spans = $spans->with(new Span($type, $at, $found, $name));
                }
            }
        }

        return $spans->resolved();
    }

    /** A url at the end of a sentence carries the full stop; the address does not. */
    private function trimmed(string $found, PiiType $type): string
    {
        return $type === PiiType::Url ? rtrim($found, '.,;:!?') : $found;
    }

    private function passesItsOwnCheck(string $found, PiiType $type): bool
    {
        return match ($type) {
            PiiType::Iban => $this->ibanChecks($found),
            PiiType::Bsn => $this->elfproef($found),
            PiiType::Telefoon => $this->tenDigits($found),
            default => true,
        };
    }

    /**
     * Ten digits nationally, or eleven with the country code standing in for the
     * trunk zero. It is not a check digit, but it is the difference between a
     * telephone number and any other run of digits somebody separated with dots.
     */
    private function tenDigits(string $number): bool
    {
        $digits = preg_replace('/\D+/', '', $number) ?? '';

        // The country code stands in for the trunk zero, and people keep the zero
        // anyway: +31 (0)6 12345678. Nine digits have to be left after both.
        foreach (['0031', '31'] as $country) {
            if (str_starts_with($digits, $country)) {
                return strlen(ltrim(substr($digits, strlen($country)), '0')) === 9;
            }
        }

        return strlen($digits) === 10;
    }

    /** Mod-97 over the account with its country and check digits moved to the back. */
    private function ibanChecks(string $iban): bool
    {
        $iban = strtoupper(preg_replace('/\s+/', '', $iban) ?? '');

        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]+$/', $iban) !== 1) {
            return false;
        }

        if (strlen($iban) < 15 || strlen($iban) > 34) {
            return false;
        }

        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $digits = '';

        foreach (str_split($rearranged) as $character) {
            $digits .= ctype_alpha($character) ? (string) (ord($character) - 55) : $character;
        }

        $remainder = 0;

        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return $remainder === 1;
    }

    /** The elfproef: nine digits weighted 9 down to 2, the last one subtracted. */
    private function elfproef(string $bsn): bool
    {
        if (strlen($bsn) !== 9) {
            return false;
        }

        $sum = 0;

        foreach (str_split($bsn) as $position => $digit) {
            $sum += (int) $digit * ($position === 8 ? -1 : 9 - $position);
        }

        return $sum !== 0 && $sum % 11 === 0;
    }
}
