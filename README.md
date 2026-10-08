# Sluis

Takes the people out of Dutch text before it reaches an AI system, and puts them back afterwards.
Offline: it requires PHP, mbstring and sodium, and nothing else.

```sh
composer require andronewille/sluis
```

```php
use Sluis\Sluis;

$sluis = Sluis::nederlands();

$masked = $sluis->mask($mail);          // $masked->text has no people in it
$answer = $model->rewrite($masked->text);
$back = $sluis->unmask($answer, $masked->vault);

$back->text;        // the answer, with the people put back
$back->stray;       // masks the vault could not place — never ignore these
```

```sh
vendor/bin/sluis < mail.txt > masked.txt
cat masked.txt | your-ai | vendor/bin/sluis --reverse
```

This repository is a read-only mirror, split out of
[Andronewille/sluis](https://github.com/Andronewille/sluis) so Composer can install it. What each
rule finds, where it is weak, the issues and the tests are all there.

Licensed under the [EUPL-1.2](LICENSE).
