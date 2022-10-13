<?php

namespace Utopia\Cache;

class Cache
{
    /**
     * @var string
     */
    const EVENT_LOAD  = 'load';

    /**
     * @var string
     */
    const EVENT_SAVE  = 'save';

    /**
     * @var string
     */
    const EVENT_PURGE = 'purge';

    /**
     * @var Adapter
     */
    private $adapter;

    /**
     * @var array
     */
    private  $listeners = [];


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
     * Add event listener.
     *
     * @param string $key
     * @param callable $callback
     */
    public function attach(string $event, callable $callback)
    {
        $this->listeners[$event][] = $callback;
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
    public function load($key, $ttl): mixed
    {
        $key = self::$caseSensitive ? $key : \strtolower($key);
        $loaded = $this->adapter->load($key, $ttl);

        foreach ($this->listeners[self::EVENT_LOAD] ?? [] as $listener) {
            if (is_callable($listener)) {
                call_user_func($listener, $key);
            }
        }

        return $loaded;
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
        $saved = $this->adapter->save($key, $data);

        foreach ($this->listeners[self::EVENT_SAVE] ?? [] as $listener) {
            if (is_callable($listener)) {
                call_user_func($listener, $key);
            }
        }

        return $saved;
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
        $purged = $this->adapter->purge($key);

        foreach ($this->listeners[self::EVENT_PURGE] ?? [] as $listener) {
            if (is_callable($listener)) {
                call_user_func($listener, $key);
            }
        }

        return $purged;
    }


}
