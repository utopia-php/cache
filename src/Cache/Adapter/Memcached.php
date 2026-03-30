<?php

namespace Utopia\Cache\Adapter;

use Memcached as Client;
use Utopia\Cache\Adapter;

class Memcached implements Adapter
{
    protected Client $memcached;

    private int $maxRetries = 0;

    private int $retryDelay = 1000; // milliseconds

    /**
     * Memcached constructor.
     */
    public function __construct(Client $memcached)
    {
        $this->memcached = $memcached;
    }

    public function getClient(): Client
    {
        return $this->memcached;
    }

    /**
     * @param  int  $maxRetries  (0-10)
     */
    public function setMaxRetries(int $maxRetries): self
    {
        $this->maxRetries = max(self::MIN_RETRIES, min($maxRetries, self::MAX_RETRIES));

        return $this;
    }

    /**
     * @param  int  $retryDelay  time in milliseconds
     */
    public function setRetryDelay(int $retryDelay): self
    {
        $this->retryDelay = $retryDelay;

        return $this;
    }

    /**
     * @param  string  $hash  optional
     */
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        /** @var array{time: int, data: string}|false */
        $cache = $this->execute(fn () => $this->memcached->get($key));
        if ($cache === false) {
            return false;
        }

        if (time() < $cache['time'] + $ttl) { // Cache is valid
            return $cache['data'];
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

        $cache = [
            'time' => \time(),
            'data' => $data,
        ];

        return $this->execute(fn () => $this->memcached->set($key, $cache)) ? $data : false;
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
        return (bool) $this->execute(fn () => $this->memcached->delete($key));
    }

    public function flush(): bool
    {
        return (bool) $this->execute(fn () => $this->memcached->flush());
    }

    public function ping(): bool
    {
        try {
            $statuses = $this->memcached->getStats();

            return ! empty($statuses);
        } catch (\MemcachedException $e) {
            return false;
        }
    }

    /**
     * Returning total number of keys
     */
    public function getSize(): int
    {
        $size = 0;
        $servers = $this->memcached->getServerList();
        if (! empty($servers)) {
            $stats = $this->memcached->getStats();
            $key = $servers[0]['host'].':'.$servers[0]['port'];
            if (isset($stats[$key])) {
                $size = $stats[$key]['curr_items'] ?? 0;
            }
        }

        return $size;
    }

    public function getName(?string $key = null): string
    {
        return 'memcached';
    }

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    public function getRetryDelay(): int
    {
        return $this->retryDelay;
    }

    /**
     * Execute a Memcached command with retry logic
     *
     * @param  callable  $callback  The Memcached operation to execute
     * @return mixed The result of the Memcached operation
     *
     * @throws \MemcachedException When all retry attempts fail
     */
    private function execute(callable $callback): mixed
    {
        $attempts = 0;
        $maxAttempts = 1 + $this->maxRetries;

        while ($attempts < $maxAttempts) {
            $result = $callback();

            if ($result === false && in_array($this->memcached->getResultCode(), [
                Client::RES_HOST_LOOKUP_FAILURE,
                Client::RES_UNKNOWN_READ_FAILURE,
                Client::RES_WRITE_FAILURE,
                Client::RES_PROTOCOL_ERROR,
                Client::RES_INVALID_HOST_PROTOCOL,
                Client::RES_CONNECTION_SOCKET_CREATE_FAILURE,
                Client::RES_CONNECTION_FAILURE,
                Client::RES_SERVER_TEMPORARILY_DISABLED,
                Client::RES_SERVER_MARKED_DEAD,
                Client::RES_TIMEOUT,
            ])) {
                $attempts++;

                if ($attempts >= $maxAttempts) {
                    throw new \MemcachedException('Memcached connection failed after '.$attempts.' attempts. Error: '.$this->memcached->getResultMessage());
                }

                usleep($this->retryDelay * 1000);

                continue;
            }

            return $result;
        }

        return false;
    }
}
