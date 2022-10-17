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
     * @param  string|array  $data
     * @return bool|string|array
     */
    public function save(string $key, $data): bool|string|array
    {
        return false;
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
