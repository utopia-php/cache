<?php

namespace Utopia\Cache;

class Cache
{
    /**
     * @var Adapter
     */
    private $adapter;

    /**
     * @var bool If cache keys are case sensitive
     */
    public static bool $caseSensitive = false;

    /**
     * @param  Adapter  $adapter
     */
    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * Toggle case sensitivity of keys inside cache
     *
     * @param  bool  $value if true, cache keys will be case sensitive
     * @return bool
     */
    public static function setCaseSensitivity(bool $value): bool
    {
        return self::$caseSensitive = $value;
    }

    /**
     * Load cached data. return false in no valid cache.
     *
     * @param  string  $key
     * @param  int  $ttl time in seconds
     * @return mixed
     */
    public function load(string $key, int $ttl): mixed
    {
        $key = self::$caseSensitive ? $key : \strtolower($key);

        return $this->adapter->load($key, $ttl);
    }

    /**
     * Save data to cache. Returns data on success of false on failure.
     *
     * @param  string  $key
     * @param  string|array<int|string, mixed>  $data
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, mixed $data): bool|string|array
    {
        $key = self::$caseSensitive ? $key : \strtolower($key);

        return $this->adapter->save($key, $data);
    }

    /**
     * Removes data from cache. Returns true on success of false on failure.
     *
     * @param  string  $key
     * @return bool
     */
    public function purge(string $key): bool
    {
        $key = self::$caseSensitive ? $key : \strtolower($key);

        return $this->adapter->purge($key);
    }

    /**
     * Removes all data from cache. Returns true on success of false on failure.
     *
     * @return bool
     */
    public function flush(): bool
    {
        return $this->adapter->flush();
    }

    /**
     * Check Cache Connecitivity
     *
     * @return bool
     */
    public function ping(): bool
    {
        return $this->adapter->ping();
    }

    /**
     * Get db size.
     *
     * @return int
     */
    public function getSize(): int
    {
        return $this->adapter->getSize();
    }
}
