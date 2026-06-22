<?php

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;
use Utopia\Cache\Feature;

class Memory implements Adapter, Feature\Lease
{
    private const LEASE_TTL = 30;

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
     * @param  string  $hash optional
     * @return mixed
     */
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        if (! empty($key) && isset($this->store[$key])) {
            /** @var array{time: int, data?: string|array<int|string, mixed>, lease?: string} */
            $saved = $this->store[$key];

            if (! isset($saved['data'])) {
                return false;
            }

            return ($saved['time'] + $ttl > time()) ? $saved['data'] : false; // return data if cache is valid
        }

        return false;
    }

    /**
     * @param  string  $key
     * @param  array<int|string, mixed>|string  $data
     * @param  string  $hash optional
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

    public function lease(string $key, string $hash = '', ?int $ttl = null): string|false
    {
        if (empty($key)) {
            return false;
        }

        if (isset($this->store[$key])) {
            /** @var array{time: int, data?: string|array<int|string, mixed>, lease?: string} $saved */
            $saved = $this->store[$key];
            $expiredLease = isset($saved['lease']) && $saved['time'] + self::LEASE_TTL <= \time();
            $expiredData = $ttl !== null && isset($saved['data']) && $saved['time'] + $ttl <= \time();
            if (! $expiredLease && ! $expiredData) {
                return false;
            }
        }

        $token = \bin2hex(\random_bytes(16));
        $this->store[$key] = [
            'time' => \time(),
            'lease' => $token,
        ];

        return $token;
    }

    public function saveLease(string $key, array|string $data, string $token, string $hash = ''): bool|string|array
    {
        if (empty($key) || empty($data) || ! isset($this->store[$key])) {
            return false;
        }

        /** @var array{time: int, data?: string|array<int|string, mixed>, lease?: string} $saved */
        $saved = $this->store[$key];
        if (($saved['lease'] ?? null) !== $token) {
            return false;
        }

        $this->store[$key] = [
            'time' => \time(),
            'data' => $data,
        ];

        return $data;
    }

    /**
     * @param  string  $key
     * @param  string  $hash optional
     * @return bool
     */
    public function touch(string $key, string $hash = ''): bool
    {
        if (empty($key) || ! isset($this->store[$key])) {
            return false;
        }

        /** @var array{time: int, data: string|array<int|string, mixed>} $saved */
        $saved = $this->store[$key];
        $saved['time'] = time();
        $this->store[$key] = $saved;

        return true;
    }

    /**
     * @param  string  $key
     * @return string[]
     */
    public function list(string $key): array
    {
        return [];
    }

    /**
     * @param  string  $key
     * @param  string  $hash optional
     * @return bool
     */
    public function purge(string $key, string $hash = ''): bool
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

    /**
     * @param  string|null  $key
     * @return string
     */
    public function getName(?string $key = null): string
    {
        return 'memory';
    }
}
