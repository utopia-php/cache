<?php

namespace Utopia\Cache\Adapter;

use Exception;
use Redis as Client;
use Utopia\Cache\Adapter;

class Redis implements Adapter
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
     * @param  string  $key
     * @param  int  $ttl time in seconds
     * @param  string|null  $hashKey optional
     * @return mixed
     */
    public function load(string $key, int $ttl, string $hashKey = null): mixed
    {
        $redis_string = $this->redis->hGet($key, $hashKey);

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
     * @param  string|array  $data
     * @param  string|null  $hashKey optional
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, array|string $data, string $hashKey = null): bool|string|array
    {
        if (empty($key) || empty($data)) {
            return false;
        }

        $value = [
            'time' => \time(),
            'data' => $data,
        ];

        return $this->redis->hSet($key, $hashKey, json_encode($value)) ? $data : false;
    }

    /**
     * @param  string  $key
     * @return array
     */
    public function list(string $key): array
    {
        return empty($this->redis->hKeys($key)) ? $this->redis->hKeys($key) : [];
    }

    /**
     * @param  string  $key
     * @param  string|null  $hashKey optional
     * @return bool
     */
    public function purge(string $key, string $hashKey = null): bool
    {
        if (! empty($hashKey)) {
            return (bool) $this->redis->hdel($key, $hashKey);
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
}
