<?php

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;

class Memory implements Adapter
{
    /**
     * @var array<string, mixed>
     */
    public $store = [];

    /**
     * Memory constructor.
     */
    public function __construct()
    {
    }

    /**
     * @param  string  $key
     * @param  int  $ttl
     * @param  string  $hashKey optional
     * @return mixed
     */
    public function load(string $key, int $ttl, string $hashKey = ''): mixed
    {
        if (! empty($key) && isset($this->store[$key])) {
            /** @var array{time: int, data: string} */
            $saved = $this->store[$key];

            return ($saved['time'] + $ttl > time()) ? $saved['data'] : false; // return data if cache is valid
        }

        return false;
    }

    /**
     * @param  string  $key
     * @param  array|string  $data
     * @param  string  $hashKey optional
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, array|string $data, string $hashKey = ''): bool|string|array
    {
        if (empty($key) || empty($data)) {
            return false;
        }

        $saved = [
            'time' => \time(),
            'data' => $data,
        ];

        $this->store[$key] = $saved;

        return $data;
    }

    /**
     * @param  string  $key
     * @return array
     */
    public function list(string $key): array
    {
        return [];
    }

    /**
     * @param  string  $key
     * @param  string  $hashKey optional
     * @return bool
     */
    public function purge(string $key, string $hashKey = ''): bool
    {
        if (! empty($key) && isset($this->store[$key])) { // if a key is passed and it exists in cache
            unset($this->store[$key]);

            return true;
        }

        return false;
    }

    /**
     * @return bool
     */
    public function flush(): bool
    {
        $this->store = [];

        return true;
    }

    /**
     * @return bool
     */
    public function ping(): bool
    {
        return true;
    }

    /**
     * Returning total number of keys
     *
     * @return int
     */
    public function getSize(): int
    {
        return count($this->store);
    }
}
