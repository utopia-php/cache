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
     * @param  int  $ttl time in seconds
     * @return mixed
     */
    public function load(string $key, int $ttl): mixed
    {
        return false;
    }

    /**
     * @param  string  $key
     * @param  string|array<int|string, mixed>  $data
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, $data): bool|string|array
    {
        return false;
    }

    /**
     * @param  string  $key
     * @param  string|array<int|string, mixed>  $data
     * @return int
     */
    public function push(string $key, $data): int|bool
    {
        return false;
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function pop(string $key): string
    {
        return '';
    }

    /**
     * @param  string  $key
     * @return bool
     */
    public function purge(string $key): bool
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
}
