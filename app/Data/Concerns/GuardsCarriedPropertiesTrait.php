<?php

declare(strict_types=1);

namespace App\Data\Concerns;

use LogicException;
use ReflectionMethod;

/**
 * Refuses to build a payload object that would silently drop one of its own properties.
 *
 * A payload with more than one door — an admin form and the Admin REST API — used to
 * be constructed field by field at each of them, so a property added for one door took
 * its default at the other and said nothing about it (#186). The `fromValidated()`
 * named constructors use this to fail loudly instead: a promoted property that is not
 * listed as carried throws, rather than quietly arriving as `false`.
 */
trait GuardsCarriedPropertiesTrait
{
    /**
     * @param  list<string>  $carriedProperties  the promoted properties `fromValidated()` reads
     * @param  string  $doors  what the author has to carry the new field through, named in the message
     */
    private static function guardEveryPropertyIsCarried(array $carriedProperties, string $doors): void
    {
        foreach ((new ReflectionMethod(static::class, '__construct'))->getParameters() as $parameter) {
            if (in_array($parameter->getName(), $carriedProperties, true)) {
                continue;
            }

            throw new LogicException(sprintf(
                '%s::fromValidated() does not carry $%s. Every field on this payload passes '
                .'through %s: carry it through all of them, and list it here.',
                static::class,
                $parameter->getName(),
                $doors,
            ));
        }
    }
}
