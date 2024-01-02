<?php

namespace Utopia\Cache\Adapter;

use Memcached as Client;
use Utopia\Cache\Adapter;

class Hazelcast implements Adapter
{
    /**
     * @var Client
     */
    protected Client $memcached;

    public function __construct(Client $memcached)
    {
        $this->memcached = $memcached;
    }

    /**
     * @param  string  $key
     * @param  int  $ttl time in seconds
     * @return mixed
     */
    public function load(string $key, int $ttl): mixed
    {
        $cachedValue = $this->memcached->get($key);
        if (is_string($cachedValue)) {
            $cache = json_decode($cachedValue, true);

            if (json_last_error() === JSON_ERROR_NONE &&
                isset($cache['data'], $cache['time']) &&
                ($cache['time'] + $ttl > time())
            ) {
                return $cache['data'];
            }
        }

        return false;
    }

    /**
     * @param  string  $key
     * @param  string|array<int|string, mixed>  $data
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, $data): bool|string|array
    {
        if (empty($key) || empty($data)) {
            return false;
        }

        $cache = [
            'time' => time(),
            'data' => $data,
        ];

        return ($this->memcached->set($key, json_encode($cache))) ? $data : false;
    }

    /**
     * @param  string  $key
     * @return bool
     */
    public function purge(string $key): bool
    {
        return $this->memcached->delete($key);
    }

    /**
     * @return bool
     * currently hazelcast doesn't support flush functionality, so returning false in that case
     */
    public function flush(): bool
    {
        return false;
    }

    /**
     * @return bool
     */
    public function ping(): bool
    {
        $statuses = $this->memcached->getServerList();

        return ! empty($statuses);
    }

    /**
     * Returning total number of keys
     *
     * @return int
     */
    public function getSize(): int
    {
        $size = 0;
        $servers = $this->memcached->getServerList();
        if (! empty($servers)) {
            $stats = $this->memcached->getStats();
            $key = $servers[0]['host'].':'.$servers[0]['port'];
            if (isset($stats[$key])) {
                $size = $stats[$key]['total_items'] ?? 0;
            }
        }

        return $size;
    }
}
