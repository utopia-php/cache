<?php

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;

class None implements Adapter
{
    /**
     * None constructor.
     */
    public function __construct()
    {
    }

    /**
     * @param  string  $key
     * @param  int  $ttl
     * @param  string  $hash optional
     * @return mixed
     */
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        return false;
    }

    /**
     * @param  string  $key
     * @param  array<int|string, mixed>|string  $data
     * @param  string  $hash optional
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, array|string $data, string $hash = ''): bool|string|array
    {
        return false;
    }

    /**
     * @param  string  $key
     * @return string[]
     */
    public function list(string $key): array
    {
        return [];
    }

    /**
     * @param  string  $key
     * @param  string  $hash optional
     * @return bool
     */
    public function purge(string $key, string $hash = ''): bool
    {
        return true;
    }

    /**
     * @return bool
     */
    public function flush(): bool
    {
        return true;
    }

    /**
     * @return bool
     */
    public function ping(): bool
    {
        return true;
    }

    /**
     * @return int
     */
    public function getSize(): int
    {
        return 0;
    }
}
