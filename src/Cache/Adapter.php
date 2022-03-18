<?php

namespace Utopia\Cache;

interface Adapter
{
    /**
     * @param string $key
     * @param int $ttl time in seconds
     * @return mixed
     */
    public function load($key, $ttl);

    /**
     * @param string $key
     * @param string|array $data
     * @return bool|string|array
     */
    public function save($key, $data);

    /**
     * @param string $key
     * @return bool
     */
    public function purge($key): bool;
}
