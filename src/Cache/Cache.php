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
     * @param  string  $hash optional
     * @return mixed
     */
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        $key = self::$caseSensitive ? $key : \strtolower($key);
        $hash = self::$caseSensitive ? $hash : \strtolower($hash);

        return $this->adapter->load($key, $ttl, $hash);
    }

    /**
     * Save data to cache. Returns data on success of false on failure.
     *
     * @param  string  $key
     * @param  string|array<int|string, mixed>  $data
     * @param  string  $hash optional
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, mixed $data, string $hash = ''): bool|string|array
    {
        $key = self::$caseSensitive ? $key : \strtolower($key);
        $hash = self::$caseSensitive ? $hash : \strtolower($hash);

        return $this->adapter->save($key, $data, $hash);
    }

    /**
     * Returns a list of keys.
     *
     * @param  string  $key
     * @return string[]
     */
    public function list(string $key): array
    {
        $key = self::$caseSensitive ? $key : \strtolower($key);

        return $this->adapter->list($key);
    }

    /**
     * Removes data from cache. Returns true on success of false on failure.
     *
     * @param  string  $key
     * @param  string  $hash optional
     * @return bool
     */
    public function purge(string $key, string $hash = ''): bool
    {
        $key = self::$caseSensitive ? $key : \strtolower($key);
        $hash = self::$caseSensitive ? $hash : \strtolower($hash);

        return $this->adapter->purge($key, $hash);
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
