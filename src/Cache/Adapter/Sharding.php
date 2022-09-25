<?php

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;

class Sharding implements Adapter
{
    /**
     * @var Adapter[] 
     */
    protected $adapters;

    /**
     * Sharding constructor.
     * 
     * Allows to shard cache across multiple adapters in a consistent way.
     * 
     * Please Note:
     *  When adding a new adapter to the pool, cached will gradually
     *  get re-distributed to fill the new adapter, this might casue a siegnificant drop
     *  in hit-rate for a short period of time.
     * 
     * @param Adapter[] $adapters
     */
    public function __construct(array $adapters)
    {
        $this->adapters = $adapters;
    }

    /**
     * @param string $key
     * @param int $ttl time in seconds
     * @return mixed
     */
    public function load($key, $ttl)
    {
        return $this->getAdapter($key)->load($key, $ttl);
    }

    /**
     * @param string $key
     * @param string|array $data
     * @return bool|string|array
     */
    public function save($key, $data)
    {
        return $this->getAdapter($key)->save($key, $data);
    }

    /**
     * @param string $key
     * @return bool
     */
    public function purge($key): bool
    {
        return (bool) $this->getAdapter($key)->purge($key);
    }

    /**
     * @param string $key
     * @return Adapter
     */
    protected function getAdapter(string $key): Adapter
    {
        $hash = \crc32($key);
        $index = $hash % \count($this->adapters);
        return $this->adapters[$index];
    }
}
