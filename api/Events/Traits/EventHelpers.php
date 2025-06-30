<?php

declare(strict_types=1);

namespace Glueful\Events\Traits;

/**
 * Event Helpers Trait
 *
 * Convenience trait that includes the most commonly used event traits.
 * Use this for simple events that need basic functionality.
 *
 * Equivalent to:
 * use Dispatchable, Timestampable, Serializable;
 */
trait EventHelpers
{
    use Dispatchable;
    use Timestampable;
    use Serializable;
}
