<?php

declare(strict_types=1);

namespace Utopia\Cache\Adapter\Redis;

use SplQueue;

class ConnectionContext
{
    /**
     * When the reader last made progress on this connection, as a monotonic
     * timestamp.
     *
     * A caller's expired deadline does not say whether the connection is alive —
     * only that this caller waited long enough. The reader is the only party that
     * observes the socket, so its last progress is what separates "the server is
     * slow" from "the server is gone". Written by the reader on every dispatched
     * frame and read by {@see Multiplexing::awaitResponse()}.
     */
    public float $lastProgressAt;

    /**
     * @param  SplQueue<\Swoole\Coroutine\Channel<mixed>>  $pending
     */
    public function __construct(
        public Client $client,
        public SplQueue $pending,
        ?float $lastProgressAt = null,
    ) {
        $this->lastProgressAt = $lastProgressAt ?? hrtime(true) / 1e9;
    }

    /**
     * Seconds since the reader last dispatched a frame on this connection.
     */
    public function idleFor(): float
    {
        return (hrtime(true) / 1e9) - $this->lastProgressAt;
    }

    public function recordProgress(): void
    {
        $this->lastProgressAt = hrtime(true) / 1e9;
    }
}
