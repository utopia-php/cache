<?php

namespace Utopia\Cache\Adapter\Redis;

final class RedisError
{
    public function __construct(
        public \RedisException $exception,
    ) {
    }
}
