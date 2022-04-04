<?php

namespace Utopia\Cache;

class Cache
{
    /**
     * @var Adapter
     */
    private $adapter;

    /**
     * @var boolean If cache keys are case sensitive
     */
    public static bool $caseSensitive = false;

    /**
     * @param Adapter $adapter
     */
    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * Toggle case sensitivity of keys inside cache
     *
     * @param string $key
     * @param boolean $value if true, cache keys will be case sensitive
     * @return mixed
     */
    public static function setCaseSensitivity(bool $value)
    {
        return self::$caseSensitive = $value;
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
        $key = self::$caseSensitive ? $key : \strtolower($key);
        return $this->adapter->load($key, $ttl);
    }

    /**
     * Save data to cache. Returns data on success of false on failure.
     *
     * @param string $key
     * @param string|array $data
     * @return bool|string|array
     */
    public function save($key, $data)
    {
        $key = self::$caseSensitive ? $key : \strtolower($key);
        return $this->adapter->save($key, $data);
    }

    /**
     * Removes data from cache. Returns true on success of false on failure.
     *
     * @param string $key
     * @return bool
     */
    public function purge($key): bool
    {
        $key = self::$caseSensitive ? $key : \strtolower($key);
        return $this->adapter->purge($key);
    }
}
