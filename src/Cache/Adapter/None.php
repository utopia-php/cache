<?php

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;

class None implements Adapter
{
    /**
     * None constructor.
     */
    public function __construct() {}

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
        return false;
    }

    /**
     * @param  array<int|string, mixed>|string  $data
     * @param  string  $hash  optional
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, array|string $data, string $hash = ''): bool|string|array
    {
        return false;
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
        return true;
    }

    public function flush(): bool
    {
        return true;
    }

    public function ping(): bool
    {
        return true;
    }

    public function getSize(): int
    {
        return 0;
    }

    public function getName(?string $key = null): string
    {
        return 'none';
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
