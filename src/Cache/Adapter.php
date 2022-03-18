<?php

namespace Utopia\Cache;

abstract class Adapter
{
    /**
     * @param string $key
     * @param int $ttl time in seconds
     * @return mixed
     */
    public abstract function internalLoad($key, $ttl);

    /**
     * @param string $key
     * @param string|array $data
     * @return bool|string|array
     */
    public abstract function internalSave($key, $data);

    /**
     * @param string $key
     * @return bool
     */
    public abstract function internalPurge($key);
    
    /**
     * @param string $key
     * @param int $ttl time in seconds
     * @return mixed
     */
    public function load($key, $ttl) {
        $key = \strtolower($key);
        return $this->internalLoad($key, $ttl);
    }

    /**
     * @param string $key
     * @param string|array $data
     * @return bool|string|array
     */
    public function save($key, $data) {
        $key = \strtolower($key);
        return $this->internalSave($key, $data);
    }

    /**
     * @param string $key
     * @return bool
     */
    public function purge($key): bool {
        $key = \strtolower($key);
        return $this->internalPurge($key);
    }
}
