<?php

namespace Utopia\Cache\Adapter;

use Exception;
use Redis as Client;
use RedisException;
use Throwable;
use Utopia\Cache\Adapter;

class Redis implements Adapter
{
    /**
     * @var Client
     */
    protected Client $redis;

    /**
     * Redis host
     *
     * @var string
     */
    protected string $host;

    /**
     * Redis port
     *
     * @var int
     */
    protected int $port;

    /**
     * Redis max attempts
     *
     * @var int
     */
    protected int $maxAttempts = 3;

    /**
     * Redis initial delay
     *
     * @var int
     */
    protected int $initialDelayMs = 100;

    /**
     * Redis constructor.
     *
     * @param  Client  $redis
     */
    public function __construct(Client $redis, ?string $host = null, ?int $port = null, ?int $maxAttempts = 3, ?int $initialDelayMs = 100)
    {
        if ($maxAttempts < 1) {
            $this->maxAttempts = 1;
        }

        if ($initialDelayMs < 1) {
            $this->initialDelayMs = 1;
        }

        $this->redis = $redis;
        $this->host = $host;
        $this->port = $port;
        $this->maxAttempts = $maxAttempts;
        $this->initialDelayMs = $initialDelayMs;
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

        $getCache = function () use ($key, $hash, $ttl) {
            $redis_string = $this->redis->hGet($key, $hash);

            if ($redis_string === false) {
                return false;
            }

            /** @var array{time: int, data: string} */
            $cache = json_decode($redis_string, true);

            if ($cache['time'] + $ttl > time()) { // Cache is valid
                return $cache['data'];
            }

            return false;
        };

        try {
            return $getCache();
        } catch (RedisException $e) {
            if (strpos($e->getMessage(), 'Connection lost') !== false || strpos($e->getMessage(), 'went away') !== false) {
                if ($this->attemptReconnectWithBackoff()) {
                    try {
                        return $getCache();
                    } catch (RedisException $e2) {
                        return false;
                    }
                }
            }
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

        $setCache = function () use ($key, $hash, $value, $data) {
            $this->redis->hSet($key, $hash, $value);

            return $data;
        };

        try {
            return $setCache();
        } catch (RedisException $e) {
            if (strpos($e->getMessage(), 'Connection lost') !== false || strpos($e->getMessage(), 'went away') !== false) {
                if ($this->attemptReconnectWithBackoff()) {
                    try {
                        return $setCache();
                    } catch (RedisException $e2) {
                        return false;
                    }
                }
            }
        } catch (Throwable $th) {
            return false;
        }

        return false;
    }

    /**
     * @param  string  $key
     * @return string[]
     */
    public function list(string $key): array
    {
        $keys = $this->redis->hKeys($key);

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
            return (bool) $this->redis->hdel($key, $hash);
        }

        return (bool) $this->redis->del($key);
    }

    /**
     * @return bool
     */
    public function flush(): bool
    {
        return $this->redis->flushAll();
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
        return $this->redis->dbSize();
    }

    /**
     * @param  string|null  $key
     * @return string
     */
    public function getName(?string $key = null): string
    {
        return 'redis';
    }

    /**
     * Attempt to reconnect to Redis with retry and exponential backoff.
     *
     * @return bool true if reconnected successfully, false otherwise
     */
    protected function attemptReconnectWithBackoff(): bool
    {
        $attempt = 0;
        $delayMs = $this->initialDelayMs;

        while ($attempt < $this->maxAttempts) {
            try {
                $this->redis->connect($this->host, $this->port);

                return true;
            } catch (RedisException $e) {
                $attempt++;

                if ($attempt >= $this->maxAttempts) {
                    break;
                }

                usleep($delayMs * 1000);
                $delayMs *= 2;
            }
        }

        return false;
    }
}
