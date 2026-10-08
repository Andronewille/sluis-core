<?php

declare(strict_types=1);

namespace Sluis\Domain;

use RuntimeException;

/**
 * Thrown when masked text still holds something the vault took out. It means a
 * value was found in one place and left standing in another, and the text was
 * about to be handed on as safe. Failing here is the point: for a tool like this,
 * carrying on is the worse outcome.
 */
final class Leaked extends RuntimeException {}
