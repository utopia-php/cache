<?php

namespace Utopia\Cache;

class Cache
{
    /**
     * @var Adapter
     */
    private $adapter;

    /**
     * @param Adapter $adapter
     */
    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * Load cached data. return false in no valid cache
     *
     * @param string $key
     * @param int $ttl time in seconds
     * @param mixed $data
     * @return mixed
     */
    public function load($key, $ttl, $data = [])
    {
        return $this->adapter->load($key, $ttl, $data);
    }

    /**
     * Save data to cache
     *
     * @param string $key
     * @param string $data
     * @return bool
     */
    public function save($key, $data) {
        return $this->adapter->save($key, $data);
    }
}
