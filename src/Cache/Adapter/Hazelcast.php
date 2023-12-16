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
        /** @var array{time: int, data: string} */
        $cache = json_decode($this->memcached->get($key), true);

        if (! empty($cache['data']) && ($cache['time'] + $ttl > time())) { // Cache is valid
            return $cache['data'];
        }

        return false;
    }

    /**
     * @param  string  $key
     * @param  string|array  $data
     * @return bool|string|array
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
     * @param  string|array<int|string, mixed>  $data
     * @return int
     */
    public function push(string $key, $data): int|bool
    {
        if (empty($key) || empty($data)) {
            return false;
        }

        $cache = [
            'time' => \time(),
            'data' => $data,
        ];

        return ($this->memcached->append($key, json_encode($cache))) ? \strlen($data) : false;
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function pop(string $key): string
    {
        /** @var array{time: int, data: string}|false */
        $cache = $this->memcached->get($key);
        if ($cache === false) {
            return '';
        }

        $this->memcached->delete($key);

        return json_decode($cache['data'], true);
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
}
