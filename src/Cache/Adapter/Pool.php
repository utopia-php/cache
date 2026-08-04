<?php

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;
use Utopia\Cache\Feature\Leasable;
use Utopia\Pools\Pool as UtopiaPool;

class Pool implements Adapter, Leasable
{
    /**
     * @param  UtopiaPool<covariant Adapter>  $pool The pool to use for connections. Must contain instances of Adapter.
     *
     * @throws \Exception
     */
    public function __construct(protected UtopiaPool $pool)
    {
        $this->pool->use(function (mixed $resource): void {
            if (! ($resource instanceof Adapter)) {
                throw new \Exception('Pool must contain instances of ' . Adapter::class);
            }
        });
    }

    /**
     * Forward method calls to the internal adapter instance via the pool.
     *
     * Required because __call() can't be used to implement abstract methods.
     *
     * @param  array<mixed>  $args
     */
    public function delegate(string $method, array $args): mixed
    {
        return $this->pool->use(fn(Adapter $adapter) => $adapter->{$method}(...$args));
    }

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function save(string $key, array|string $data, string $hash = ''): bool|string|array
    {
        /**
         * @var bool|string|array<mixed> $result
         */
        $result = $this->delegate(__FUNCTION__, \func_get_args());

        return $result;
    }

    public function getGeneration(string $key): string
    {
        return $this->pool->use(fn(Adapter $adapter): string => $adapter instanceof Leasable ? $adapter->getGeneration($key) : '0');
    }

    public function saveWithLease(string $key, array|string $data, string $hash, string $generation): bool|string|array
    {
        /**
         * @var bool|string|array<mixed> $result
         */
        $result = $this->pool->use(fn(Adapter $adapter): bool|string|array => $adapter instanceof Leasable
            ? $adapter->saveWithLease($key, $data, $hash, $generation)
            : $adapter->save($key, $data, $hash));

        return $result;
    }

    public function touch(string $key, string $hash = ''): bool
    {
        /**
         * @var bool $result
         */
        $result = $this->delegate(__FUNCTION__, \func_get_args());

        return $result;
    }

    public function list(string $key): array
    {
        /**
         * @var array<string> $result
         */
        $result = $this->delegate(__FUNCTION__, \func_get_args());

        return $result;
    }

    public function purge(string $key, string $hash = ''): bool
    {
        /**
         * @var bool $result
         */
        $result = $this->delegate(__FUNCTION__, \func_get_args());

        return $result;
    }

    public function flush(): bool
    {
        /**
         * @var bool $result
         */
        $result = $this->delegate(__FUNCTION__, \func_get_args());

        return $result;
    }

    public function ping(): bool
    {
        /**
         * @var bool $result
         */
        $result = $this->delegate(__FUNCTION__, \func_get_args());

        return $result;
    }

    public function getSize(): int
    {
        /**
         * @var int $result
         */
        $result = $this->delegate(__FUNCTION__, \func_get_args());

        return $result;
    }

    public function getName(?string $key = null): string
    {
        /**
         * @var string $result
         */
        $result = $this->delegate(__FUNCTION__, \func_get_args());

        return $result;
    }
}
