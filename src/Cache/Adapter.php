<?php

namespace Utopia\Cache;

interface Adapter
{
    /**
     * @param  string  $key
     * @param  int  $ttl time in seconds
     * @return mixed
     */
    public function load(string $key, int $ttl): mixed;

    /**
     * @param  string  $key
     * @param  string|array  $data
     * @return bool|string|array
     */
    public function save(string $key, $data): bool|string|array;

    /**
     * @param  string  $key
     * @return bool
     */
    public function purge(string $key): bool;

    /**
     * @return bool
     */
    public function flush(): bool;

    /**
     * @return bool
     */
    public function ping(): bool;
}
