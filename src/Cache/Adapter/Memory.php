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
     * @param  int  $ttl time in seconds
     * @return mixed
     */
    public function load(string $key, int $ttl): mixed
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
     * @param  string|array<int|string, mixed>  $data
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, $data): bool|string|array
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
     * @param  string|array<int|string, mixed>  $data
     * @return int
     */
    public function push(string $key, $data): int|bool
    {
        if (empty($key) || empty($data)) {
            return false;
        }

        $saved = [
            'time' => \time(),
            'data' => $data,
        ];

        $this->store[$key] = $saved;

        return \count($this->store);
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function pop(string $key): string
    {
        if (! empty($key) && isset($this->store[$key])) {
            /** @var array{time: int, data: string} */
            $saved = $this->store[$key];

            unset($this->store[$key]);

            return $saved['data'];
        }

        return '';
    }

    /**
     * @param  string  $key
     * @return bool
     */
    public function purge(string $key): bool
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
}
