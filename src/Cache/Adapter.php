<?php

namespace Utopia\Cache;

interface Adapter
{
    /**
     * @param  int  $ttl  time in seconds
     * @param  string  $hash  optional
     */
    public function load(string $key, int $ttl, string $hash = ''): mixed;

    /**
     * @param  string|array<int|string, mixed>  $data
     * @param  string  $hash  optional
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, array|string $data, string $hash = ''): bool|string|array;

    /**
     * @return string[]
     */
    public function list(string $key): array;

    /**
     * @param  string  $hash  optional
     */
    public function purge(string $key, string $hash = ''): bool;

    public function flush(): bool;

    public function ping(): bool;

    public function getSize(): int;

    public function getName(?string $key = null): string;

    /**
     * Set the maximum number of retries.
     *
     * The client will automatically retry the request if an connection error occurs.
     * If the request fails after the maximum number of retries, an exception will be thrown.
     */
    public function setMaxRetries(int $maxRetries): self;

    /**
     * Set the retry delay in milliseconds.
     */
    public function setRetryDelay(int $retryDelay): self;

    public function getMaxRetries(): int;

    public function getRetryDelay(): int;
}
