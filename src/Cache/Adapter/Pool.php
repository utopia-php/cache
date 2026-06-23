<?php

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;
use Utopia\Cache\Feature\FencedFill;
use Utopia\Cache\Token;
use Utopia\Pools\Pool as UtopiaPool;

class Pool implements Adapter, FencedFill
{
    /**
     * @var UtopiaPool<covariant Adapter>
     */
    protected UtopiaPool $pool;

    /**
     * @param  UtopiaPool<covariant Adapter>  $pool The pool to use for connections. Must contain instances of Adapter.
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

    public function loadFenced(string $key, int $ttl, string $hash = ''): mixed
    {
        return $this->pool->use(function (Adapter $adapter) use ($key, $ttl, $hash) {
            if ($adapter instanceof FencedFill) {
                return $adapter->loadFenced($key, $ttl, $hash);
            }

            return $adapter->load($key, $ttl, $hash);
        });
    }

    public function save(string $key, array|string $data, string $hash = ''): bool|string|array
    {
        /**
         * @var bool|string|array<mixed> $result
         */
        $result = $this->delegate(__FUNCTION__, \func_get_args());

        return $result;
    }

    public function saveFenced(string $key, array|string $data, Token $token, string $hash = ''): bool|string|array
    {
        /**
         * @var bool|string|array<mixed> $result
         */
        $result = $this->pool->use(function (Adapter $adapter) use ($key, $data, $token, $hash) {
            if (! ($adapter instanceof FencedFill)) {
                return false;
            }

            return $adapter->saveFenced($key, $data, $token, $hash);
        });

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
