<?php

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;

class Memory implements Adapter
{
    /**
     * @var array
     */
    protected $cache = [];

    /**
     * Memory constructor.
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
        if (!empty($key) && isset($this->cache[$key])) {
            /** @var array{time: int, data: string} */
            $saved = $this->cache[$key];

            return ($saved['time'] + $ttl > time()) ? $saved['data'] : false; // return data if cache is valid
        }
        
        return false;
    }

    /**
     * @param string $key
     * @param string $data
     * @return bool|string
     */
    public function save($key, $data)
    {
        if (empty($key) || empty($data)) {
            return false;
        }

        $saved = [
            'time' => \time(),
            'data' => $data
        ];

        $this->cache[] = $saved;

        return $data;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function purge($key): bool
    {
        if (!empty($key) && isset($this->cache[$key])) { // if a key is passed and it exists in cache
            unset($this->cache[$key]);
            return true;
        }

        return false;
    }
}
