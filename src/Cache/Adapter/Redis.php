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

    private int $timeout;

    private ?string $persistentId;

    private int $readTimeout;

    private mixed $auth;

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
        $this->timeout = $redis->getTimeout();
        $this->persistentId = $redis->getPersistentId();
        $this->readTimeout = $redis->getReadTimeout();

        if (! empty($redis->getAuth())) {
            $this->auth = $redis->getAuth();
        }

        $this->redis = $redis;
    }

    /**
     * Set the maximum number of retries.
     *
     * The client will automatically retry the request if an connection error occurs.
     * If the request fails after the maximum number of retries, an exception will be thrown.
     *
     * @param  int  $maxRetries
     * @return self
     */
    public function setMaxRetries(int $maxRetries): self
    {
        $this->maxRetries = $maxRetries;

        return $this;
    }

    /**
     * Set the retry delay in milliseconds.
     *
     * @param  int  $retryDelay
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

        $redis_string = $this->executeRedisCommand(fn () => $this->redis->hGet($key, $hash));

        if ($redis_string === false) {
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
            $this->executeRedisCommand(fn () => $this->redis->hSet($key, $hash, $value));

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
        $keys = $this->executeRedisCommand(fn () => $this->redis->hKeys($key));

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
            return (bool) $this->executeRedisCommand(fn () => $this->redis->hdel($key, $hash));
        }

        return (bool) $this->executeRedisCommand(fn () => $this->redis->del($key));
    }

    /**
     * @return bool
     */
    public function flush(): bool
    {
        return $this->executeRedisCommand(fn () => $this->redis->flushAll());
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
        return $this->executeRedisCommand(fn () => $this->redis->dbSize());
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
    private function executeRedisCommand(callable $callback): mixed
    {
        $attempts = 0;
        $maxAttempts = max(1, $this->maxRetries);

        while ($attempts < $maxAttempts) {
            try {
                return $callback();
            } catch (\RedisException $th) {
                $this->reconnect();
                $attempts++;

                if ($attempts >= $maxAttempts) {
                    throw $th;
                }

                usleep($this->retryDelay * 1000); // Convert milliseconds to microseconds
            }
        }
    }

    /**
     * Reconnect to Redis
     */
    private function reconnect(): void
    {
        $newRedis = new Client();

        $newRedis->connect(
            $this->host,
            $this->port,
            $this->timeout,
            $this->persistentId,
            0,
            $this->readTimeout,
        );

        if (! empty($this->auth)) {
            $newRedis->auth($this->auth);
        }

        $this->redis = $newRedis;
    }
}
