<?php

namespace Utopia\Cache\Adapter\Redis;

final class ConnectionError
{
    public function __construct(
        public ConnectionException $exception,
    ) {
    }
}
