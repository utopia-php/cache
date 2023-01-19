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
     * @return array|bool|string
     */
    // @phpstan-ignore-next-line
    public function load(string $key, int $ttl): array|bool|string
    {
        /** @var array{time: int, data: string} */
        // @phpstan-ignore-next-line
        $cache = json_decode($this->memcached->get($key), true);

        if (! empty($cache['data']) && ($cache['time'] + $ttl > time())) { // Cache is valid
            return $cache['data'];
        }

        return false;
    }

    /**
     * @param  string  $key
     * @param  mixed  $data
     * @return bool|string|array
     */
    // @phpstan-ignore-next-line
    public function save(string $key, mixed $data): bool|string|array
    {
        if (empty($key) || empty($data)) {
            return false;
        }

        $cache = [
            'time' => time(),
            'data' => $data,
        ];
        // @phpstan-ignore-next-line
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
}
