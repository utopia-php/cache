<?php

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;
use Utopia\Cache\Token;

class Memory implements Adapter
{
    private const TOKEN_TTL = 60;

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
                if ($this->isTokenExpired($saved)) {
                    unset($this->store[$key]);
                }

                $token = $this->purge($key, $hash);

                return $token;
            }

            if ($saved['time'] + $ttl > time()) {
                return $saved['data'];
            }

            return new Token($this->dataToken($saved));
        }

        $token = $this->purge($key, $hash);

        return $token;
    }

    /**
     * @param  string  $key
     * @param  array<int|string, mixed>|string  $data
     * @param  string  $hash optional
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, array|string $data, string $hash = '', ?Token $token = null): bool|string|array
    {
        if (empty($key) || empty($data)) {
            return false;
        }

        if ($token !== null) {
            /** @var array{time: int, data?: string|array<int|string, mixed>, token?: string}|null $saved */
            $saved = $this->store[$key] ?? null;
            if ($saved === null || ! $this->matchesToken($saved, $token)) {
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
     * @return Token|false
     */
    public function purge(string $key, string $hash = ''): Token|false
    {
        if (! empty($key)) {
            $token = new Token(\bin2hex(\random_bytes(16)));
            $this->store[$key] = [
                'time' => \time(),
                'token' => $token->value,
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
        return \count(\array_filter($this->store, static fn (mixed $saved): bool => \is_array($saved) && isset($saved['data'])));
    }

    /**
     * @param  string|null  $key
     * @return string
     */
    public function getName(?string $key = null): string
    {
        return 'memory';
    }

    /**
     * @param  array{time: int, data?: string|array<int|string, mixed>, token?: string}  $saved
     */
    private function isTokenExpired(array $saved): bool
    {
        return ! isset($saved['data']) && ($saved['time'] + self::TOKEN_TTL <= \time());
    }

    /**
     * @param  array{time: int, data?: string|array<int|string, mixed>, token?: string}  $saved
     */
    private function matchesToken(array $saved, Token $token): bool
    {
        if (($saved['token'] ?? null) === $token->value) {
            return true;
        }

        return isset($saved['data']) && $this->dataToken($saved) === $token->value;
    }

    /**
     * @param  array{time: int, data?: string|array<int|string, mixed>, token?: string}  $saved
     */
    private function dataToken(array $saved): string
    {
        return 'data:'.\hash('sha256', \serialize($saved));
    }
}
