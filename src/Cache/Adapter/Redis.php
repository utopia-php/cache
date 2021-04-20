<?php

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;
use Redis as Client;

class Redis implements Adapter
{
    /**
     * @var Redis
     */
    protected $redis;

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
        return $this->redis->get($key);
    }

    /**
     * @param string $key
     * @param string $data
     * @return bool|string
     */
    public function save($key, $data)
    {
        if (empty($key) || empty($data)) {
            return false;
        }

        return ($this->redis->set($key, $data)) ? $data : false;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function purge($key): bool
    {
        return (bool) $this->redis->del($key); // unlink() returns number of keys deleted
    }
}
