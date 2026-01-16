<?php

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;

class Sharding implements Adapter
{
    /**
     * @var Adapter[]
     */
    protected array $adapters;

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
     * Set the maximum number of retries.
     *
     * The client will automatically retry the request if an connection error occurs.
     * If the request fails after the maximum number of retries, an exception will be thrown.
     */
    public function setMaxRetries(int $maxRetries): self
    {
        foreach ($this->adapters as $adapter) {
            $adapter->setMaxRetries($maxRetries);
        }

        return $this;
    }

    /**
     * Set the retry delay in milliseconds.
     */
    public function setRetryDelay(int $retryDelay): self
    {
        foreach ($this->adapters as $adapter) {
            $adapter->setRetryDelay($retryDelay);
        }

        return $this;
    }

    /**
     * @param  int  $ttl  time in seconds
     * @param  string  $hash  optional
     */
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        return $this->getAdapter($key)->load($key, $ttl, $hash);
    }

    /**
     * @param  array<int|string, mixed>|string  $data
     * @param  string  $hash  optional
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, array|string $data, string $hash = ''): bool|string|array
    {
        return $this->getAdapter($key)->save($key, $data, $hash);
    }

    /**
     * @return string[]
     */
    public function list(string $key): array
    {
        return $this->getAdapter($key)->list($key);
    }

    /**
     * @param  string  $hash  optional
     */
    public function purge(string $key, string $hash = ''): bool
    {
        return $this->getAdapter($key)->purge($key, $hash);
    }

    public function flush(): bool
    {
        $result = true;
        foreach ($this->adapters as $value) {
            $result = ($value->flush()) ? $result : false;
        }

        return $result;
    }

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
     * Returning total number of keys of all adapters
     */
    public function getSize(): int
    {
        $size = 0;
        foreach ($this->adapters as $value) {
            $size += $value->getSize();
        }

        return $size;
    }

    public function getName(?string $key = null): string
    {
        if ($key === null) {
            return $this->adapters[0]->getName();
        }

        return $this->getAdapter($key)->getName();
    }

    protected function getAdapter(string $key): Adapter
    {
        $hash = \crc32($key);
        $index = $hash % $this->count;

        return $this->adapters[$index];
    }

    public function getMaxRetries(): int
    {
        return 0;
    }

    public function getRetryDelay(): int
    {
        return 0;
    }
}
