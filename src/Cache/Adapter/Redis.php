<?php

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;
use Redis as Client;

class Redis implements Adapter
{
    /**
     * @var Client 
     */
    protected $redis;

    /**
     * Redis constructor.
     * @param Client $redis
     */
    public function __construct(Client $redis)
    {
        $this->redis = $redis;
    }

    /**
     * @param string $key
     * @param int $ttl time in seconds
     * @return mixed
     */
    public function load($key, $ttl)
    {
        /** @var array{time: int, data: string} */
        $cache = json_decode($this->redis->get($key), true);
        
        if (!empty($cache) && ($cache['time'] + $ttl > time())) { // Cache is valid
            return $cache['data'];
        }

        return false;
    }

    /**
     * @param string $key
     * @param string|array $data
     * @return bool|string|array
     */
    public function save($key, $data)
    {
        if (empty($key) || empty($data)) {
            return false;
        }

        $cache = [
            'time' => \time(),
            'data' => $data
        ];

        return ($this->redis->set($key, json_encode($cache))) ? $data : false;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function purge($key): bool
    {
        if (\str_ends_with($key, ':*')) {
            return (bool) $this->redis->del($this->redis->keys($key));
        }

        return (bool) $this->redis->del($key); // unlink() returns number of keys deleted
    }
}
