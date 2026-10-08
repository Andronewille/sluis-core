<?php

namespace Sluis\Application;

use Sluis\Domain\Restored;
use Sluis\Domain\Vault;

/**
 * The reverse half: pure substitution, and deliberately incapable of anything
 * else. It does not read the text, does not recognise, does not call a model and
 * does not decide. That is what makes it safe to run over whatever the AI sent
 * back, however thoroughly it rewrote the words around the tokens.
 *
 * Matching is case-insensitive because a model that starts a sentence with a
 * token capitalises it, and bounded by what is not a letter or digit because a
 * token ends up next to a comma, a full stop and a closing bracket.
 */
final readonly class Deanonymise
{
    /** A mask, as Sluis writes them: a label, a number, and `mask`. */
    private const SHAPE = '/(?<![\p{L}\p{N}_])[a-z]+\d+mask(?![\p{L}\p{N}_])/iu';

    public function __invoke(string $text, Vault $vault): Restored
    {
        $restored = $text;
        $unrestored = [];

        foreach ($vault->tokens() as $token) {
            $pattern = '/(?<![\p{L}\p{N}_])'.preg_quote($token, '/').'(?![\p{L}\p{N}_])/iu';
            $put = $vault->value($token) ?? '';

            $restored = preg_replace_callback($pattern, fn () => $put, $restored, count: $count) ?? $restored;

            if ($count === 0) {
                $unrestored[] = $token;
            }
        }

        preg_match_all(self::SHAPE, $restored, $left);

        return new Restored($restored, $unrestored, array_values(array_unique($left[0])));
    }
}
