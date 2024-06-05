<?php

namespace Utopia\Cache;

interface Adapter
{
    /**
     * @param  string  $key
     * @param  int  $ttl time in seconds
     * @param  string  $hash optional
     * @return mixed
     */
    public function load(string $key, int $ttl, string $hash = ''): mixed;

    /**
     * @param  string  $key
     * @param  string|array<int|string, mixed>  $data
     * @param  string  $hash optional
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, array|string $data, string $hash = ''): bool|string|array;

    /**
     * @param  string  $key
     * @return string[]
     */
    public function list(string $key): array;

    /**
     * @param  string  $key
     * @param  string  $hash optional
     * @return bool
     */
    public function purge(string $key, string $hash = ''): bool;

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
