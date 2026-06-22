<?php

namespace Utopia\Cache;

final class Token
{
    public function __construct(
        public readonly string $value
    ) {
    }
}
