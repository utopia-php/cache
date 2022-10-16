<?php

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;

class Sharding implements Adapter
{
    /**
     * @var Adapter[]
     */
    protected array $adapters;

    /**
     * @var int
     */
    protected int $count = 0;

    /**
     * Sharding Adapter.
     *
     * Allows to shard cache across multiple adapters in a consistent way.
     * Using sharding we can increase cache size and balance the read
     * and write load between multiple adapters.
     *
     * Each cache key will be hashed and the hash will be used to determine
     * which adapter to use for fetching or storing this key. Only one
     * adapter will always match a specific key unless a new adapter is
     * added to the pool.
     *
     * When adding a new adapter to the pool, cached will gradually
     * get re-distributed to fill the new adapter, this might cause a
     * significant drop in hit-rate for a short period of time.
     *
     * @param  Adapter[]  $adapters
     */
    public function __construct(array $adapters)
    {
        if (empty($adapters)) {
            throw new \Exception('No adapters provided');
        }

        $this->count = \count($adapters);
        $this->adapters = $adapters;
    }

    /**
     * @param  string  $key
     * @param  int  $ttl time in seconds
     * @return mixed
     */
    public function load(string $key, int $ttl): mixed
    {
        return $this->getAdapter($key)->load($key, $ttl);
    }

    /**
     * @param  string  $key
     * @param  string|array<int|string, mixed>  $data
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, $data): bool|string|array
    {
        return $this->getAdapter($key)->save($key, $data);
    }

    /**
     * @param  string  $key
     * @return bool
     */
    public function purge(string $key): bool
    {
        return $this->getAdapter($key)->purge($key);
    }

    /**
     * @return bool
     */
    public function flush(): bool
    {
        $result = true;
        foreach ($this->adapters as $value) {
            $result = ($value->flush()) ? $result : false;
        }

        return $result;
    }

    /**
     * @return bool
     */
    public function ping(): bool
    {
        foreach ($this->adapters as $value) {
            if (! ($value->ping())) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  string  $key
     * @return Adapter
     */
    protected function getAdapter(string $key): Adapter
    {
        $hash = \crc32($key);
        $index = $hash % $this->count;

        return $this->adapters[$index];
    }
}
