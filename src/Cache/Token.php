<?php

namespace Utopia\Cache;

/**
 * Internal marker used to fence cache fills after misses.
 */
final class Token
{
    public function __construct(
        public readonly string $value
    ) {
    }
}
