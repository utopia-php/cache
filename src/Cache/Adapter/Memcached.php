<?php

namespace Utopia\Cache\Adapter;

use Memcached as Client;
use Utopia\Cache\Adapter;
use Utopia\Cache\Feature\Retryable;
use Utopia\Cache\Token;

class Memcached implements Adapter, Retryable
{
    /**
     * @var Client
     */
    protected Client $memcached;

    private int $maxRetries = 0;

    private int $retryDelay = 1000; // milliseconds

    /**
     * Memcached constructor.
     *
     * @param  Client  $memcached
     */
    public function __construct(Client $memcached)
    {
        $this->memcached = $memcached;
    }

    /**
     * @param  int  $maxRetries (0-10)
     * @return self
     */
    public function setMaxRetries(int $maxRetries): self
    {
        $this->maxRetries = max(Retryable::MIN_RETRIES, min($maxRetries, Retryable::MAX_RETRIES));

        return $this;
    }

    /**
     * @param  int  $retryDelay time in milliseconds
     * @return self
     */
    public function setRetryDelay(int $retryDelay): self
    {
        $this->retryDelay = $retryDelay;

        return $this;
    }

    /**
     * @param  string  $key
     * @param  int  $ttl
     * @param  string  $hash optional
     * @return mixed
     */
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        /** @var array{time: int, data?: string|array<int|string, mixed>, token?: string}|false */
        $cache = $this->execute(fn () => $this->memcached->get($key));
        if ($cache === false) {
            $token = $this->purge($key, $hash);

            return $token;
        }

        if (! isset($cache['data'])) {
            $token = $this->purge($key, $hash);

            return $token;
        }

        if ($cache['time'] + $ttl > time()) { // Cache is valid
            return $cache['data'];
        }

        return false;
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

        $cache = [
            'time' => \time(),
            'data' => $data,
        ];

        if ($token !== null) {
            $existing = $this->getWithCas($key);
            if ($existing === false) {
                return false;
            }

            $value = $existing['value'];
            if (! \is_array($value) || ($value['token'] ?? null) !== $token->value) {
                return false;
            }

            return $this->execute(fn () => $this->memcached->cas($existing['cas'], $key, $cache)) ? $data : false;
        }

        return $this->execute(fn () => $this->memcached->set($key, $cache)) ? $data : false;
    }

    /**
     * @param  string  $key
     * @param  string  $hash optional
     * @return bool
     */
    public function touch(string $key, string $hash = ''): bool
    {
        /** @var array{time: int, data?: string|array<int|string, mixed>, token?: string}|false */
        $cache = $this->execute(fn () => $this->memcached->get($key));
        if ($cache === false || ! isset($cache['data'])) {
            return false;
        }

        $cache['time'] = time();

        return (bool) $this->execute(fn () => $this->memcached->set($key, $cache));
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
        $token = new Token(\bin2hex(\random_bytes(16)));
        $cache = [
            'time' => \time(),
            'token' => $token->value,
        ];

        return (bool) $this->execute(fn () => $this->memcached->set($key, $cache)) ? $token : false;
    }

    /**
     * @return bool
     */
    public function flush(): bool
    {
        return (bool) $this->execute(fn () => $this->memcached->flush());
    }

    /**
     * @return bool
     */
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
     *
     * @return int
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

    /**
     * @param  string|null  $key
     * @return string
     */
    public function getName(?string $key = null): string
    {
        return 'memcached';
    }

    /**
     * @return int
     */
    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    /**
     * @return int
     */
    public function getRetryDelay(): int
    {
        return $this->retryDelay;
    }

    /**
     * @return array{value: mixed, cas: float}|false
     */
    private function getWithCas(string $key): array|false
    {
        $result = $this->execute(fn () => $this->memcached->get($key, null, Client::GET_EXTENDED));

        if (! \is_array($result) || ! \array_key_exists('value', $result) || ! \array_key_exists('cas', $result)) {
            return false;
        }

        $cas = $result['cas'];
        if (! \is_string($cas) && ! \is_int($cas) && ! \is_float($cas)) {
            return false;
        }

        $cas = (float) $cas;
        if ($cas <= 0) {
            return false;
        }

        return [
            'value' => $result['value'],
            'cas' => $cas,
        ];
    }

    /**
     * Execute a Memcached command with retry logic
     *
     * @param  callable  $callback The Memcached operation to execute
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
                \Memcached::RES_HOST_LOOKUP_FAILURE,
                \Memcached::RES_UNKNOWN_READ_FAILURE,
                \Memcached::RES_WRITE_FAILURE,
                \Memcached::RES_PROTOCOL_ERROR,
                \Memcached::RES_INVALID_HOST_PROTOCOL,
                \Memcached::RES_CONNECTION_SOCKET_CREATE_FAILURE,
                \Memcached::RES_CONNECTION_FAILURE,
                \Memcached::RES_SERVER_TEMPORARILY_DISABLED,
                \Memcached::RES_SERVER_MARKED_DEAD,
                \Memcached::RES_TIMEOUT,
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
