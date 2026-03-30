<?php

namespace Utopia\Cache;

interface Adapter
{
    const MIN_RETRIES = 0;

    const MAX_RETRIES = 10;

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
     * @param  int  $maxRetries  (0-10)
     */
    public function setMaxRetries(int $maxRetries): self;

    /**
     * @param  int  $retryDelay  time in milliseconds
     */
    public function setRetryDelay(int $retryDelay): self;

    public function getMaxRetries(): int;

    public function getRetryDelay(): int;
}
