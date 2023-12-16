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
     * @param  string|array<int|string, mixed>  $data
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, $data): bool|string|array;

    /**
     * @param  string  $key
     * @param  string|array<int|string, mixed>  $data
     * @return int
     */
    public function push(string $key, $data): int|bool;

    /**
     * @param string $key
     * @return mixed
     */
    public function pop(string $key): string;

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
