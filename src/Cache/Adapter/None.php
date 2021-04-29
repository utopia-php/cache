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
        return;
    }

    /**
     * @param string $key
     * @param int $ttl time in seconds
     * @return mixed
     */
    public function load($key, $ttl)
    {
        return false;
    }

    /**
     * @param string $key
     * @param string|array $data
     * @return bool|string|array
     */
    public function save($key, $data)
    {
        return false;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function purge($key): bool
    {
        return true;
    }
}
