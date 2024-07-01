<?php

namespace Utopia\Cache;

class Cache
{
    /**
     * @var string
     */
    const EVENT_LOAD = 'load';

    /**
     * @var string
     */
    const EVENT_SAVE = 'save';

    /**
     * @var string
     */
    const EVENT_PURGE = 'purge';

    /**
     * @var Adapter
     */
    private Adapter $adapter;

    /**
     * @var array
     */
    // @phpstan-ignore-next-line
    private array $listeners = [];

    /**
     * @var bool
     */
    private bool $listenersStatus = true;

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
     * Add event listener.
     *
     * @param  string  $event
     * @param  callable  $callback
     * @return Cache
     */
    public function on(string $event, callable $callback): self
    {
        $this->listeners[$event][] = $callback;

        return $this;
    }

    /**
     * Set disableListeners
     *
     * @param  bool  $status
     * @return self
     */
    public function setListenersStatus(bool $status): self
    {
        $this->listenersStatus = $status;

        return $this;
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

        $loaded = $this->adapter->load($key, $ttl, $hash);

        if (! $this->listenersStatus) {
            return $loaded;
        }

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
     * @param  string  $key
     * @param  string|array<int|string, mixed>  $data
     * @param  string  $hash optional
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, mixed $data, string $hash = ''): bool|string|array
    {
        $key = self::$caseSensitive ? $key : \strtolower($key);
        $hash = self::$caseSensitive ? $hash : \strtolower($hash);
        $saved = $this->adapter->save($key, $data, $hash);

        if (! $this->listenersStatus) {
            return $saved;
        }

        foreach ($this->listeners[self::EVENT_SAVE] ?? [] as $listener) {
            if (is_callable($listener)) {
                call_user_func($listener, $key);
            }
        }

        return $saved;
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
        $purged = $this->adapter->purge($key, $hash);

        if (! $this->listenersStatus) {
            return $purged;
        }

        foreach ($this->listeners[self::EVENT_PURGE] ?? [] as $listener) {
            if (is_callable($listener)) {
                call_user_func($listener, $key);
            }
        }

        return $purged;
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
