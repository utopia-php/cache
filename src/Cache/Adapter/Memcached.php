<?php

namespace KabbajHoussine\Cache\Adapter;

use Memcached as Client;
use KabbajHoussine\Cache\Adapter;

class Memcached implements Adapter
{
    /**
     * @var Client
     */
    protected Client $memcached;

    /**
     * Memcached constructor.
     *
     * @param  Client  $memcached
     */
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
        /** @var array{time: int, data: string}|false */
        $cache = $this->memcached->get($key);
        if ($cache === false) {
            return false;
        }

        if ($cache['time'] + $ttl > time()) { // Cache is valid
            return $cache['data'];
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
            'time' => \time(),
            'data' => $data,
        ];

        return ($this->memcached->set($key, $cache)) ? $data : false;
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
     */
    public function flush(): bool
    {
        return $this->memcached->flush();
    }

    /**
     * @return bool
     */
    public function ping(): bool
    {
        $statuses = $this->memcached->getStats();

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
                $size = $stats[$key]['curr_items'] ?? 0;
            }
        }

        return $size;
    }
}
