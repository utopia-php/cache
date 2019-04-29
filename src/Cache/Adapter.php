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
     * @param string $data
     * @return mixed
     */
    public function save($key, $data);

    /**
     * @param string $key
     * @return bool
     */
    public function purge($key);
}
