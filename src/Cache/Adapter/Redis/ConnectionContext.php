<?php

namespace Utopia\Cache\Adapter\Redis;

use SplQueue;

class ConnectionContext
{
    /**
     * @param  SplQueue<\Swoole\Coroutine\Channel<mixed>>  $pending
     */
    public function __construct(
        public Client $client,
        public SplQueue $pending,
    ) {
    }
}
