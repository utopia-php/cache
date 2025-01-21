<?php

namespace Utopia\Cache\Adapter;

use Exception;
use RedisCluster as Client;
use Throwable;
use Utopia\Cache\Adapter;

class RedisCluster implements Adapter
{
    /**
     * @var Client
     */
    protected Client $redis;

    /**
     * Redis constructor.
     *
     * @param  Client  $redis
     */
    public function __construct(Client $redis)
    {
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

        $redis_string = $this->redis->hGet($key, $hash);

        /** @phpstan-ignore identical.alwaysFalse */
        if ($redis_string === false) {
            return false;
        }

        /** @var array{time: int, data: string} $cache */
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
        } catch (Throwable $th) {
            return false;
        }

        try {
            $this->redis->hSet($key, $hash, $value);

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
        foreach ($this->redis->_masters() as $master) {
            $this->redis->flushAll($master);
        }

        return true;
    }

    /**
     * @return bool
     */
    public function ping(): bool
    {
        try {
            foreach ($this->redis->_masters() as $master) {
                $this->redis->ping($master);
            }

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
        $size = 0;
        foreach ($this->redis->_masters() as $master) {
            $size += $this->redis->dbSize($master);
        }

        return $size;
    }

    /**
     * @return int
     */
    public function getMaxRetries(): int
    {
        return 0;
    }

    /**
     * @return int
     */
    public function getRetryDelay(): int
    {
        return 1000;
    }
}
