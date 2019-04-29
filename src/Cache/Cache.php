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
     * Load cached data. return false in no valid cache.
     *
     * @param string $key
     * @param int $ttl time in seconds
     * @return mixed
     */
    public function load($key, $ttl)
    {
        return $this->adapter->load($key, $ttl);
    }

    /**
     * Save data to cache. Returns data on success of false on failure.
     *
     * @param string $key
     * @param string $data
     * @return bool
     */
    public function save($key, $data) {
        return $this->adapter->save($key, $data);
    }

    /**
     * Removes data from cache. Returns true on success of false on failure.
     *
     * @param string $key
     * @return bool
     */
    public function purge($key) {
        return $this->adapter->purge($key);
    }
}
