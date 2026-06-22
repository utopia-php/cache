<?php

namespace Utopia\Cache\Feature;

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
}
