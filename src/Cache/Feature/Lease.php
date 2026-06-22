<?php

namespace Utopia\Cache\Feature;

interface Lease
{
    /**
     * @param  string  $key
     * @param  string  $hash optional
     * @param  int|null  $ttl optional
     * @return string|false
     */
    public function lease(string $key, string $hash = '', ?int $ttl = null): string|false;

    /**
     * @param  string  $key
     * @param  string|array<int|string, mixed>  $data
     * @param  string  $token
     * @param  string  $hash optional
     * @return bool|string|array<int|string, mixed>
     */
    public function saveLease(string $key, array|string $data, string $token, string $hash = ''): bool|string|array;
}
