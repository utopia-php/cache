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
     * Set the maximum number of retries.
     *
     * The client will automatically retry the request if an connection error occurs.
     * If the request fails after the maximum number of retries, an exception will be thrown.
     */
    public function setMaxRetries(int $maxRetries): self
    {
        return $this;
    }

    /**
     * Set the retry delay in milliseconds.
     */
    public function setRetryDelay(int $retryDelay): self
    {
        return $this;
    }

    /**
     * @param  string  $hash  optional
     */
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        if (!empty($key) && isset($this->store[$key])) {
            /** @var array{time: int, data: string} */
            $saved = $this->store[$key];

            return (time() < $saved['time'] + $ttl) ? $saved['data'] : false; // return data if cache is valid
        }

        return false;
    }

    /**
     * @param  array<int|string, mixed>|string  $data
     * @param  string  $hash  optional
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, array|string $data, string $hash = ''): bool|string|array
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
     * @return string[]
     */
    public function list(string $key): array
    {
        return [];
    }

    /**
     * @param  string  $hash  optional
     */
    public function purge(string $key, string $hash = ''): bool
    {
        if (!empty($key) && isset($this->store[$key])) { // if a key is passed and it exists in cache
            unset($this->store[$key]);

            return true;
        }

        return false;
    }

    public function flush(): bool
    {
        $this->store = [];

        return true;
    }

    public function ping(): bool
    {
        return true;
    }

    /**
     * Returning total number of keys
     */
    public function getSize(): int
    {
        return count($this->store);
    }

    public function getName(?string $key = null): string
    {
        return 'memory';
    }

    public function getMaxRetries(): int
    {
        return 0;
    }

    public function getRetryDelay(): int
    {
        return 0;
    }
}
