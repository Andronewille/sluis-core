# Sluis

**Masks the people in Dutch text before an AI reads it, and puts them back in the answer.**
A PHP library and a command. Offline, no dependencies, [EUPL-1.2](LICENSE).

```
Hey Karel, mijn boot kost 250 euro, te bezichtigen aan de Maanstraat 123 in Haasterdam.
ik ben te bereiken op 0612345678. Mvg, Bob

                                    ↓  mask

Hey voornaam1mask, mijn boot kost 250 euro, te bezichtigen aan de adres1mask in stad1mask.
ik ben te bereiken op telefoon1mask. Mvg, voornaam2mask

                                    ↓  the AI rewrites it, and never saw a person

Beste voornaam1mask, de boot op adres1mask in stad1mask is te bezichtigen. Bel telefoon1mask.

                                    ↓  unmask

Beste Karel, de boot op Maanstraat 123 in Haasterdam is te bezichtigen. Bel 0612345678.
```

## Installation

```sh
composer require andronewille/sluis
```

PHP 8.5 or newer with mbstring and sodium. Nothing else comes with it.

## Usage

Try it from the command line first:

```sh
vendor/bin/sluis --json --raw="Hey Karel, bel 0612345678. Mvg, Bob"
```

```json
{"text":"Hey voornaam1mask, bel telefoon1mask. Mvg, voornaam2mask", "found":{"voornaam":2,"telefoon":1}, "vault":{…}}
```

In PHP:

```php
use Sluis\Sluis;

$sluis = Sluis::nederlands();

$masked = $sluis->mask($mail);                    // $masked->text has no people in it
$answer = $yourAi->rewrite($masked->text);
$back = $sluis->unmask($answer, $masked->vault);

$back->text;        // the answer, with the people put back
$back->stray;       // masks that could not be put back — never ignore these
$masked->found;     // ['voornaam' => 2, 'telefoon' => 1], safe to log
```

As a pipe:

```sh
vendor/bin/sluis < mail.txt > masked.txt          # what was taken out goes to sluis-vault.json
cat masked.txt | your-ai | vendor/bin/sluis --reverse
```

The vault is the one thing that holds what was taken out. You keep it; Sluis stores nothing.

## What it finds

| | How |
|---|---|
| e-mail address, url, postcode | by their format |
| telephone number | ten digits, in every grouping people write them |
| iban, bsn | by format, then the check digit |
| kvk number | eight digits behind the word that announces them |
| street and house number | `Maanstraat 123`, `Waterlooplein 12a` |
| names | the greeting and the signature of a mail, and after `heer` or `mevrouw` |
| towns | after a cue such as `in`, and from a word list |

Amounts are left alone on purpose. Money is not personal data, and an answer without it is useless.

## What it promises

- **Offline.** Nothing opens a connection, and a test fails the build if anything ever does.
- **It checks its own work.** Every run, Sluis refuses to return text in which something it masked
  is still readable.
- **Nothing is logged.** No error message, log line or dump ever holds the words it was about.
- **Putting back only substitutes.** It is safe to run over whatever the AI sent back.

## What it misses

Rules read formats and the frame of a letter. They do not read prose, so on their own they miss:

- a name in the middle of a sentence: `Bel Sanne de Vries` keeps `de Vries`
- a town with no cue in front of it: `Haasterdam is mooi`
- company names, licence plates and dates of birth

When Sluis is wrong it is built to mask too much rather than too little. For prose there is an
optional local model, [andronewille/sluis-onnx](https://github.com/Andronewille/sluis-onnx): 178 MB
of weights on your own disk, still offline, and it finds the names, towns and companies the rules
leave standing.

## More

- [The design](https://github.com/Andronewille/sluis/blob/main/docs/design.md) — why every rule and
  every token is the way it is, and the full list of weak spots
- [Changelog](https://github.com/Andronewille/sluis/blob/main/CHANGELOG.md)
- [Contributing](https://github.com/Andronewille/sluis/blob/main/CONTRIBUTING.md)
- [Reporting a leak](https://github.com/Andronewille/sluis/blob/main/SECURITY.md)
- Questions and bugs: [issues](https://github.com/Andronewille/sluis/issues)
