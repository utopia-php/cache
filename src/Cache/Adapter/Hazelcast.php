<?php

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;
use Memcached as Client;

class Hazelcast implements Adapter
{
    /**
     * @var Client 
     */
    protected Client $memcached;

    /**
     *
     */
    public function __construct(Client $memcached)
    {
        $this->memcached = $memcached;
    }

    /**
     * @param string $key
     * @param int $ttl time in seconds
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
     * @param string $key
     * @param string|array $data
     * @return bool|string|array
     */
    public function save(string $key, $data): bool|string|array
    {
        if (empty($key) || empty($data)) {
            return false;
        }

        $cache = [
            'time' => time(),
            'data' => $data
        ];

        return ($this->memcached->set($key, json_encode($cache))) ? $data : false;
    }

    /**
     * @param string $key
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
