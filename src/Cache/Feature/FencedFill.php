<?php

namespace Utopia\Cache\Feature;

use Utopia\Cache\Token;

interface FencedFill
{
    /**
     * Load cached data and return an internal fill token on cache misses.
     *
     * @param  string  $key
     * @param  int  $ttl time in seconds
     * @param  string  $hash optional
     * @return mixed
     */
    public function loadFenced(string $key, int $ttl, string $hash = ''): mixed;

    /**
     * Save data only if the fill token is still current.
     *
     * @param  string  $key
     * @param  array<int|string, mixed>|string  $data
     * @param  Token  $token
     * @param  string  $hash optional
     * @return bool|string|array<int|string, mixed>
     */
    public function saveFenced(string $key, array|string $data, Token $token, string $hash = ''): bool|string|array;
}
