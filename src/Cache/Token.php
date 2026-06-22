<?php

namespace Utopia\Cache;

/**
 * Internal marker returned by adapters to tell Cache that a miss reserved a
 * fill token. Public load() callers still receive false for the miss.
 */
final class Token
{
    public function __construct(
        public readonly string $value
    ) {
    }
}
