<?php

namespace Utopia\Cache\Adapter;

use Exception;
use Redis as Client;
use Throwable;
use Utopia\Cache\Adapter;

class Redis implements Adapter
{
    /**
     * @var Client
     */
    protected Client $redis;

    private int $maxRetries = 0;

    private int $retryDelay = 1000; // milliseconds

    private string $host;

    private int $port;

    private float $timeout;

    private ?string $persistentId;

    private float $readTimeout;

    /**
     * @var string|array<string>|null
     */
    private string|array|null $auth = null;

    /**
     * Whether the original connection was persistent (pconnect)
     */
    private bool $persistent = false;

    /**
     * Redis constructor.
     *
     * @param  Client  $redis
     */
    public function __construct(Client $redis)
    {
        // On connection loss, RedisClient loses the connection info. So we need to store the connection info.
        $this->host = $redis->getHost();
        $this->port = $redis->getPort();
        $timeout = $redis->getTimeout();
        $this->timeout = ($timeout !== false) ? (float) $timeout : 0.0;
        $this->persistentId = $redis->getPersistentId();
        $this->readTimeout = $redis->getReadTimeout();

        // Detect if the connection was persistent (pconnect sets a persistentId)
        $this->persistent = $this->persistentId !== null;

        if (! empty($redis->getAuth())) {
            $this->auth = $redis->getAuth();
        }

        $this->redis = $redis;
    }

    /**
     * @param  int  $maxRetries (0-10)
     * @return self
     */
    public function setMaxRetries(int $maxRetries): self
    {
        $this->maxRetries = max(self::MIN_RETRIES, min($maxRetries, self::MAX_RETRIES));

        return $this;
    }

    /**
     * @param  int  $retryDelay time in milliseconds
     * @return self
     */
    public function setRetryDelay(int $retryDelay): self
    {
        $this->retryDelay = $retryDelay;

        return $this;
    }

    /**
     * @param  string  $key
     * @param  int  $ttl time in seconds
     * @param  string  $hash optional
     * @return mixed
     */
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        if (empty($hash)) {
            $hash = $key;
        }

        $redis_string = $this->execute(fn () => $this->redis->hGet($key, $hash));

        if ($redis_string === false || $redis_string === null) {
            return false;
        }

        if (gettype($redis_string) !== 'string') {
            return false;
        }

        /** @var array{time: int, data: string} */
        $cache = json_decode($redis_string, true);

        if ($cache['time'] + $ttl > time()) { // Cache is valid
            return $cache['data'];
        }

        return false;
    }

    /**
     * @param  string  $key
     * @param  array<int|string, mixed>|string  $data
     * @param  string  $hash optional
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, array|string $data, string $hash = ''): bool|string|array
    {
        if (empty($key) || empty($data)) {
            return false;
        }

        if (empty($hash)) {
            $hash = $key;
        }

        try {
            $value = json_encode([
                'time' => \time(),
                'data' => $data,
            ], flags: JSON_THROW_ON_ERROR);
        } catch(Throwable $th) {
            return false;
        }

        try {
            $this->execute(fn () => $this->redis->hSet($key, $hash, $value));

            return $data;
        } catch (Throwable $th) {
            return false;
        }
    }

    /**
     * @param  string  $key
     * @return string[]
     */
    public function list(string $key): array
    {
        /** @var array<string> */
        $keys = $this->execute(fn () => $this->redis->hKeys($key));

        if (empty($keys)) {
            return [];
        }

        return $keys;
    }

    /**
     * @param  string  $key
     * @param  string  $hash optional
     * @return bool
     */
    public function purge(string $key, string $hash = ''): bool
    {
        if (! empty($hash)) {
            return (bool) $this->execute(fn () => $this->redis->hdel($key, $hash));
        }

        return (bool) $this->execute(fn () => $this->redis->del($key));
    }

    /**
     * @return bool
     */
    public function flush(): bool
    {
        return (bool) $this->execute(fn () => $this->redis->flushAll());
    }

    /**
     * @return bool
     */
    public function ping(): bool
    {
        try {
            $this->redis->ping();

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Returning total number of keys
     *
     * @return int
     */
    public function getSize(): int
    {
        /** @var int */
        $size = $this->execute(fn () => $this->redis->dbSize());

        return $size;
    }

    /**
     * @return int
     */
    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    /**
     * @return int
     */
    public function getRetryDelay(): int
    {
        return $this->retryDelay;
    }

    /**
     * Execute a Redis command with retry logic
     *
     * @param  callable  $callback
     * @return mixed
     *
     * @throws \RedisException
     */
    private function execute(callable $callback): mixed
    {
        $attempts = 0;
        $maxAttempts = 1 + $this->maxRetries;

        while ($attempts < $maxAttempts) {
            try {
                return $callback();
            } catch (\RedisException $th) {
                if (! $this->isConnectionError($th)) {
                    throw $th;
                }

                $attempts++;

                if ($attempts >= $maxAttempts) {
                    throw $th;
                }

                usleep($this->retryDelay * 1000); // Convert milliseconds to microseconds

                try {
                    $this->reconnect();
                } catch (\RedisException $e) {
                    // Reconnect failed, will retry on next iteration
                }
            }
        }

        // This line is unreachable but required for PHPStan
        throw new \RedisException('Failed to execute Redis command');
    }

    /**
     * Check if the exception is a connection-related error that should trigger reconnect.
     *
     * RedisException always returns error code 0 with no subclasses for different error types.
     * The only way to differentiate connection errors from command errors is by message matching.
     *
     * @param  Exception  $e
     * @return bool
     */
    private function isConnectionError(Exception $e): bool
    {
        $connectionErrors = [
            'went away',
            'socket',
            'read error on connection',
            'connection lost',
        ];

        $message = strtolower($e->getMessage());
        foreach ($connectionErrors as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function reconnect(): void
    {
        $newRedis = new Client();

        if ($this->persistent) {
            $newRedis->pconnect(
                $this->host,
                $this->port,
                $this->timeout,
                $this->persistentId,
                0,
                $this->readTimeout,
            );
        } else {
            $newRedis->connect(
                $this->host,
                $this->port,
                $this->timeout,
                $this->persistentId,
                0,
                $this->readTimeout,
            );
        }

        if (! empty($this->auth)) {
            $newRedis->auth($this->auth);
        }

        $this->redis = $newRedis;
    }

    /**
     * @param  string|null  $key
     * @return string
     */
    public function getName(?string $key = null): string
    {
        return 'redis';
    }
}
