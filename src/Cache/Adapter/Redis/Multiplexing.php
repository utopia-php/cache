<?php

namespace Utopia\Cache\Adapter\Redis;

use SplQueue;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Lock;
use Throwable;
use Utopia\Cache\Adapter;
use Utopia\Cache\Feature\Telemetry as TelemetryFeature;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;
use Utopia\Telemetry\Gauge;

/**
 * Redis\Multiplexing adapter.
 *
 * Multiplexes many Swoole coroutines over a single Redis TCP connection. Each
 * caller takes a connection-wide Lock, pushes its response Channel onto the
 * FIFO `pending` queue, sends its RESP frame, and releases the lock — so the
 * order of registrations exactly matches the order of bytes on the wire. A
 * single reader coroutine parses inbound frames and dispatches each one to
 * the next pending Channel, exploiting Redis's guarantee of in-order replies.
 */
class Multiplexing implements Adapter, TelemetryFeature
{
    private int $maxRetries = 0;

    private int $retryDelay = 1000; // milliseconds

    private ?ConnectionContext $connection = null;

    /**
     * Serializes pending enqueue + send so the FIFO invariant holds even when
     * many coroutines issue commands concurrently.
     */
    private Lock $sendLock;

    private ?Gauge $pendingDepth = null;

    /**
     * @param  string  $host
     * @param  int  $port
     * @param  float  $timeout connect timeout in seconds
     * @param  float  $readTimeout read timeout in seconds — caches should
     *                             fail fast, default 0.25s
     * @param  string|array<string>|null  $auth password or [username, password]
     * @param  int  $dbIndex
     */
    public function __construct(
        private string $host,
        private int $port = 6379,
        private float $timeout = 0.0,
        private float $readTimeout = 0.25,
        private string|array|null $auth = null,
        private int $dbIndex = 0,
    ) {
        if ($this->readTimeout <= 0) {
            $this->readTimeout = 0.25;
        }
        $this->sendLock = new Lock();
        $this->setTelemetry(new NoTelemetry());

        $locked = $this->lockSend();
        try {
            $this->connect();
        } finally {
            $this->unlockSend($locked);
        }
    }

    /**
     * Wire a telemetry adapter for connection-level metrics. The current
     * pending-queue depth is recorded after each enqueue — a steady-state
     * non-zero value means callers are queueing faster than Redis is
     * replying.
     */
    public function setTelemetry(Telemetry $telemetry): void
    {
        $this->pendingDepth = $telemetry->createGauge(
            'cache.redis_multiplexing.pending.depth',
            description: 'Pending response channels awaiting RESP frames on the multiplexed connection.',
        );
    }

    /**
     * Explicitly close the multiplexed connection. Required for clean shutdown
     * because the reader coroutine holds a reference to this adapter.
     */
    public function disconnect(): void
    {
        $this->shutdown();
    }

    public function setMaxRetries(int $maxRetries): self
    {
        $this->maxRetries = max(self::MIN_RETRIES, min($maxRetries, self::MAX_RETRIES));

        return $this;
    }

    public function setRetryDelay(int $retryDelay): self
    {
        $this->retryDelay = $retryDelay;

        return $this;
    }

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    public function getRetryDelay(): int
    {
        return $this->retryDelay;
    }

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        if (empty($hash)) {
            $hash = $key;
        }

        $value = $this->command(['HGET', $key, $hash]);

        if (! is_string($value)) {
            return false;
        }

        return Envelope::decode($value, $ttl, time());
    }

    public function save(string $key, array|string $data, string $hash = ''): bool|string|array
    {
        if (empty($key) || empty($data)) {
            return false;
        }

        if (empty($hash)) {
            $hash = $key;
        }

        try {
            $value = Envelope::encode($data, time());
            $this->command(['HSET', $key, $hash, $value]);

            return $data;
        } catch (Throwable $th) {
            return false;
        }
    }

    public function touch(string $key, string $hash = ''): bool
    {
        if (empty($hash)) {
            $hash = $key;
        }

        $value = $this->command(['HGET', $key, $hash]);
        if (! is_string($value)) {
            return false;
        }

        $payload = Envelope::touch($value, time());
        if ($payload === false) {
            return false;
        }

        return $this->command(['HSET', $key, $hash, $payload]) !== false;
    }

    /**
     * @return string[]
     */
    public function list(string $key): array
    {
        $keys = $this->command(['HKEYS', $key]);
        if (! is_array($keys)) {
            return [];
        }

        /** @var string[] $keys */
        return $keys;
    }

    public function purge(string $key, string $hash = ''): bool
    {
        if (! empty($hash)) {
            return (bool) $this->command(['HDEL', $key, $hash]);
        }

        return (bool) $this->command(['DEL', $key]);
    }

    public function flush(): bool
    {
        return $this->command(['FLUSHDB']) === 'OK';
    }

    public function ping(): bool
    {
        try {
            return $this->command(['PING']) === 'PONG';
        } catch (Throwable $th) {
            return false;
        }
    }

    public function getSize(): int
    {
        $size = $this->command(['DBSIZE']);

        return is_int($size) ? $size : 0;
    }

    public function getName(?string $key = null): string
    {
        return 'redis-multiplexing';
    }

    /**
     * Send a Redis command and block the calling coroutine until the response arrives.
     *
     * @param  array<int|string>  $args
     */
    private function command(array $args): mixed
    {
        $attempts = 0;
        $maxAttempts = 1 + $this->maxRetries;

        while (true) {
            try {
                return $this->dispatch($args);
            } catch (ConnectionException $th) {
                if (++$attempts >= $maxAttempts) {
                    throw $th;
                }

                Coroutine::sleep($this->retryDelaySeconds());
                $this->ensureConnected();
            }
        }
    }

    private function retryDelaySeconds(): float
    {
        $baseDelay = max(0, $this->retryDelay) / 1000;
        if ($baseDelay === 0.0) {
            return 0.0;
        }

        return $baseDelay + mt_rand(0, 100) / 1000;
    }

    /**
     * Send a single command on the current connection and wait for its response.
     * Acquires the send lock; do not call from a context that already holds it.
     *
     * @param  array<int|string>  $args
     */
    private function dispatch(array $args): mixed
    {
        $locked = $this->lockSend();
        try {
            $context = $this->connection;
            if ($context === null) {
                throw new ConnectionException('Redis connection is not open');
            }
            $response = new Channel(1);
            $error = null;

            $context->pending->enqueue($response);
            $this->pendingDepth?->record($context->pending->count());
            try {
                $context->client->send(Client::encode($args));
            } catch (ConnectionException $sendError) {
                $error = $sendError;
            }
        } finally {
            $this->unlockSend($locked);
        }

        if ($error !== null) {
            $this->teardownIfCurrent($context, $error);

            throw $error;
        }

        return $this->awaitResponse($context, $response);
    }

    /**
     * @param  Channel<mixed>  $response
     */
    private function awaitResponse(ConnectionContext $context, Channel $response): mixed
    {
        $result = $response->pop($this->readTimeout);
        if ($result === false && $response->errCode !== 0) {
            $error = new ConnectionException('Timed out waiting for Redis response');
            $this->teardownIfCurrent($context, $error);

            throw $error;
        }

        return Client::unwrap($result);
    }

    private function ensureConnected(): void
    {
        $locked = $this->lockSend();
        try {
            if ($this->connection === null) {
                $this->connect();
            }
        } finally {
            $this->unlockSend($locked);
        }
    }

    /**
     * Caller must hold $sendLock when running inside a coroutine.
     */
    private function connect(): void
    {
        $client = new Client($this->host, $this->port, $this->timeout);

        try {
            if ($this->auth !== null) {
                $authArgs = is_array($this->auth)
                    ? array_merge(['AUTH'], array_values($this->auth))
                    : ['AUTH', $this->auth];

                if ($client->command($authArgs, $this->readTimeout) !== 'OK') {
                    throw new \RedisException('Redis AUTH failed');
                }
            }

            if ($this->dbIndex !== 0) {
                if ($client->command(['SELECT', (string) $this->dbIndex], $this->readTimeout) !== 'OK') {
                    throw new \RedisException('Redis SELECT failed');
                }
            }
        } catch (Throwable $th) {
            $client->close();

            throw $th;
        }

        /** @var SplQueue<Channel<mixed>> $pending */
        $pending = new SplQueue();
        $context = new ConnectionContext($client, $pending);
        $this->connection = $context;

        Coroutine::create(function () use ($context) {
            $this->readerLoop($context);
        });
    }

    private function shutdown(): void
    {
        $context = null;
        $locked = $this->lockSend();
        try {
            if ($this->connection !== null) {
                $context = $this->connection;
                $this->connection = null;
            }
        } finally {
            $this->unlockSend($locked);
        }

        if ($context !== null) {
            $this->finishTeardown($context, new ConnectionException('Connection closed'));
        }
    }

    /**
     * Stop the connection and fail every coroutine still waiting on a response.
     */
    private function finishTeardown(ConnectionContext $context, ConnectionException $error): void
    {
        while (! $context->pending->isEmpty()) {
            $ch = $context->pending->dequeue();
            if ($ch instanceof Channel) {
                $ch->push(new ConnectionError($error));
            }
        }

        $context->client->close();
    }

    private function teardownIfCurrent(ConnectionContext $context, ConnectionException $error): void
    {
        $shouldTeardown = false;
        $locked = $this->lockSend();
        try {
            if ($this->connection === $context) {
                $this->connection = null;
                $shouldTeardown = true;
            }
        } finally {
            $this->unlockSend($locked);
        }

        if ($shouldTeardown) {
            $this->finishTeardown($context, $error);
        }
    }

    private function lockSend(): bool
    {
        if (Coroutine::getCid() < 0) {
            return false;
        }

        $this->sendLock->lock();

        return true;
    }

    private function unlockSend(bool $locked): void
    {
        if ($locked) {
            $this->sendLock->unlock();
        }
    }

    private function readerLoop(ConnectionContext $context): void
    {
        $readBuffer = $context->client->takeBuffer();

        while (true) {
            while ($readBuffer !== '') {
                $offset = 0;
                try {
                    $value = Client::parse($readBuffer, $offset);
                } catch (Throwable $th) {
                    $this->teardownIfCurrent($context, new ConnectionException('Redis protocol parse failed: '.$th->getMessage()));

                    return;
                }

                if ($value === Client::INCOMPLETE) {
                    break;
                }

                $readBuffer = substr($readBuffer, $offset);

                if (! $this->isCurrentContext($context)) {
                    return;
                }

                // A complete frame implies a prior pending enqueue.
                $waiting = $context->pending->isEmpty() ? null : $context->pending->dequeue();
                if ($waiting instanceof Channel) {
                    $waiting->push($value);
                } else {
                    // Should never happen given the send-lock invariant. Log
                    // and tear down so the next caller reconnects on a clean
                    // socket rather than continuing to misroute frames.
                    error_log('Redis\\Multiplexing: unexpected RESP frame with no pending request; tearing down connection');
                    $this->teardownIfCurrent($context, new ConnectionException('Unexpected RESP frame with no pending request'));

                    return;
                }
            }

            $chunk = $context->client->recv(-1);
            if ($chunk === false || $chunk === '') {
                $this->teardownIfCurrent($context, new ConnectionException('Redis connection closed'));

                return;
            }

            $readBuffer .= $chunk;
        }
    }

    private function isCurrentContext(ConnectionContext $context): bool
    {
        $locked = $this->lockSend();
        try {
            return $this->connection === $context;
        } finally {
            $this->unlockSend($locked);
        }
    }
}
