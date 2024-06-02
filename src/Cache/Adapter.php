<?php

namespace Utopia\Cache;

interface Adapter
{
    /**
     * @param  string  $key
     * @param  int  $ttl time in seconds
     * @param  string  $hashKey optional
     * @return mixed
     */
    public function load(string $key, int $ttl, string $hashKey = ''): mixed;

    /**
     * @param  string  $key
     * @param  string|array<int|string, mixed>  $data
     * @param  string  $hashKey optional
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, array|string $data, string $hashKey = ''): bool|string|array;

    /**
     * @param  string  $key
     * @return array
     */
    public function list(string $key): array;

    /**
     * @param  string  $key
     * @param  string  $hashKey optional
     * @return bool
     */
    public function purge(string $key, string $hashKey = ''): bool;

    /**
     * @return bool
     */
    public function flush(): bool;

    /**
     * @return bool
     */
    public function ping(): bool;

    /**
     * @return int
     */
    public function getSize(): int;
}
