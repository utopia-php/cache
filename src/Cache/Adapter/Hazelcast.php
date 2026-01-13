<?php

namespace Utopia\Cache\Adapter;

use Memcached as Client;
use Utopia\Cache\Adapter;

class Hazelcast implements Adapter
{
    protected Client $memcached;

    private int $maxRetries = 0;

    private int $retryDelay = 1000; // milliseconds

    public function __construct(Client $memcached)
    {
        $this->memcached = $memcached;
    }

    /**
     * Set the maximum number of retries.
     *
     * The client will automatically retry the request if an connection error occurs.
     * If the request fails after the maximum number of retries, an exception will be thrown.
     */
    public function setMaxRetries(int $maxRetries): self
    {
        $this->maxRetries = $maxRetries;

        return $this;
    }

    /**
     * Set the retry delay in milliseconds.
     */
    public function setRetryDelay(int $retryDelay): self
    {
        $this->retryDelay = $retryDelay;

        return $this;
    }

    /**
     * @param  int  $ttl  time in seconds
     * @param  string  $hash  optional
     */
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        $cache = $this->executeMemcachedCommand(fn () => $this->memcached->get($key));
        if (is_string($cache)) {
            $cache = json_decode($cache, true);
        }

        if (! is_array($cache) || ! isset($cache['time'], $cache['data']) || ! is_int($cache['time'])) {
            return false;
        }

        if ((time() < $cache['time'] + $ttl)) { // Cache is valid
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
            'time' => time(),
            'data' => $data,
        ];

        return ($this->executeMemcachedCommand(fn () => $this->memcached->set($key, json_encode($cache)))) ? $data : false;
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
        return (bool) $this->executeMemcachedCommand(fn () => $this->memcached->delete($key));
    }

    /**
     * @return bool
     *              currently hazelcast doesn't support flush functionality, so returning false in that case
     */
    public function flush(): bool
    {
        return false;
    }

    public function ping(): bool
    {
        $statuses = $this->executeMemcachedCommand(fn () => $this->memcached->getServerList());

        return !empty($statuses);
    }

    /**
     * Returning total number of keys
     */
    public function getSize(): int
    {
        $size = 0;
        $servers = $this->memcached->getServerList();
        if (!empty($servers) && is_array($servers[0])) {
            $stats = $this->memcached->getStats();
            if (is_array($stats) && isset($servers[0]['host'], $servers[0]['port'])) {
                $host = $servers[0]['host'];
                $port = $servers[0]['port'];
                if (is_string($host) && (is_int($port) || is_string($port))) {
                    $key = $host.':'.$port;
                    if (isset($stats[$key]) && is_array($stats[$key]) && isset($stats[$key]['total_items'])) {
                        $size = (int) $stats[$key]['total_items'];
                    }
                }
            }
        }

        return $size;
    }

    public function getName(?string $key = null): string
    {
        return 'hazelcast';
    }

    /*
     * @return int
     */
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
    private function executeMemcachedCommand(callable $callback): mixed
    {
        $attempts = 0;
        $maxAttempts = max(1, $this->maxRetries);

        while ($attempts < $maxAttempts) {
            $result = $callback();

            if ($result === false && in_array($this->memcached->getResultCode(), [
                \Memcached::RES_HOST_LOOKUP_FAILURE,
                \Memcached::RES_UNKNOWN_READ_FAILURE,
                \Memcached::RES_WRITE_FAILURE,
                \Memcached::RES_PROTOCOL_ERROR,
                \Memcached::RES_INVALID_HOST_PROTOCOL,
                \Memcached::RES_CONNECTION_SOCKET_CREATE_FAILURE,
                \Memcached::RES_CONNECTION_FAILURE,
                \Memcached::RES_SERVER_TEMPORARILY_DISABLED,
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
