<?php

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;
use Utopia\Cache\Token;

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
     * @param  string  $hash optional
     * @return mixed
     */
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        if (! empty($key) && isset($this->store[$key])) {
            /** @var array{time: int, data?: string|array<int|string, mixed>, token?: string} */
            $saved = $this->store[$key];

            if (! isset($saved['data'])) {
                $token = $this->purge($key, $hash);

                return $token === false ? false : new Token($token);
            }

            return ($saved['time'] + $ttl > time()) ? $saved['data'] : false; // return data if cache is valid
        }

        $token = $this->purge($key, $hash);

        return $token === false ? false : new Token($token);
    }

    /**
     * @param  string  $key
     * @param  array<int|string, mixed>|string  $data
     * @param  string  $hash optional
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, array|string $data, string $hash = '', ?string $token = null): bool|string|array
    {
        if (empty($key) || empty($data)) {
            return false;
        }

        if ($token !== null) {
            /** @var array{time: int, data?: string|array<int|string, mixed>, token?: string}|null $saved */
            $saved = $this->store[$key] ?? null;
            if (($saved['token'] ?? null) !== $token) {
                return false;
            }
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
     * @param  string  $hash optional
     * @return bool
     */
    public function touch(string $key, string $hash = ''): bool
    {
        if (empty($key) || ! isset($this->store[$key])) {
            return false;
        }

        /** @var array{time: int, data?: string|array<int|string, mixed>, token?: string} $saved */
        $saved = $this->store[$key];
        if (! isset($saved['data'])) {
            return false;
        }

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
     * @return string|false
     */
    public function purge(string $key, string $hash = ''): string|false
    {
        if (! empty($key)) {
            $token = \bin2hex(\random_bytes(16));
            $this->store[$key] = [
                'time' => \time(),
                'token' => $token,
            ];

            return $token;
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
