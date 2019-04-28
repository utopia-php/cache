<?php

namespace Utopia\Cache;

interface Adapter
{
    /**
     * @param string $key
     * @param int $ttl time in seconds
     * @param mixed $data
     * @return mixed
     */
    public function load($key, $ttl, $data = []);

    /**
     * @param string $key
     * @param string $data
     * @return bool
     */
    public function save($key, $data);
}
