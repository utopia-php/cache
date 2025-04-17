<?php

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;
use Utopia\Pools\Pool as UtopiaPool;

class Pool implements Adapter
{
    /**
     * @var UtopiaPool<Adapter>
     */
    protected UtopiaPool $pool;

    /**
     * @param  UtopiaPool<Adapter>  $pool The pool to use for connections. Must contain instances of Adapter.
     *
     * @throws \Exception
     */
    public function __construct(UtopiaPool $pool)
    {
        $this->pool = $pool;

        $this->pool->use(function (mixed $resource) {
            if (! ($resource instanceof Adapter)) {
                throw new \Exception('Pool must contain instances of '.Adapter::class);
            }
        });
    }

    /**
     * Forward method calls to the internal adapter instance via the pool.
     *
     * Required because __call() can't be used to implement abstract methods.
     *
     * @param  string  $method
     * @param  array<mixed>  $args
     * @return mixed
     */
    public function delegate(string $method, array $args): mixed
    {
        return $this->pool->use(function (Adapter $adapter) use ($method, $args) {
            return $adapter->{$method}(...$args);
        });
    }

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function save(string $key, array|string $data, string $hash = ''): bool|string|array
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function list(string $key): array
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function purge(string $key, string $hash = ''): bool
    {
        return $this->delegate(__FUNCTION__, \func_get_args()); // TODO: Implement purge() method.
    }

    public function flush(): bool
    {
        return $this->delegate(__FUNCTION__, \func_get_args()); // TODO: Implement flush() method.
    }

    public function ping(): bool
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function getSize(): int
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function getName(?string $key = null): string
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }
}
