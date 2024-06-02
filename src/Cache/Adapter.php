<?php

namespace Utopia\Cache;

interface Adapter
{
    /**
     * @param  string  $key
     * @param  int  $ttl time in seconds
     * @param  string|null  $hashKey optional
     * @return mixed
     */
    public function load(string $key, int $ttl, string $hashKey = null): mixed;

    /**
     * @param  string  $key
     * @param  string|array<int|string, mixed>  $data
     * @param  string|null  $hashKey optional
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, array|string $data, string $hashKey = null): bool|string|array;

    /**
     * @param  string  $key
     * @return array
     */
    public function list(string $key): array;

    /**
     * @param  string  $key
     * @param  string|null  $hashKey optional
     * @return bool
     */
    public function purge(string $key, string $hashKey = null): bool;

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
