<?php

namespace Utopia\Cache\Adapter;

use Memcached as Client;
use Utopia\Cache\Adapter;
use Utopia\Cache\Feature\Retryable;
use Utopia\Cache\Token;

class Memcached implements Adapter, Retryable
{
    private const ABSENT_TOKEN_PREFIX = 'absent:';

    private const CAS_TOKEN_PREFIX = 'cas:';

    private const TOMBSTONE_PREFIX = '__utopia_cache_token__:';

    private const TOKEN_TTL = 60;

    /**
     * @var Client
     */
    protected Client $memcached;

    private int $maxRetries = 0;

    private int $retryDelay = 1000; // milliseconds

    /**
     * @var array<string, int>
     */
    private array $tokenExpirations = [];

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
        $existing = $this->getWithCas($key);
        if ($existing === false) {
            $token = $this->execute(fn () => $this->memcached->get($this->getTombstoneKey($key)));
            if (\is_string($token)) {
                return new Token($token);
            }

            return $this->createAbsentToken();
        }

        /** @var mixed $cache */
        $cache = $existing['value'];
        if (! \is_array($cache) || ! isset($cache['data'])) {
            if (\is_array($cache) && isset($cache['token']) && \is_string($cache['token'])) {
                return new Token($cache['token']);
            }

            return $this->createAbsentToken();
        }

        if ($cache['time'] + $ttl > time()) { // Cache is valid
            return $cache['data'];
        }

        return new Token(self::CAS_TOKEN_PREFIX.(string) $existing['cas']);
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
            if ($this->isAbsentToken($token)) {
                if ($this->execute(fn () => $this->memcached->get($this->getTombstoneKey($key))) !== false) {
                    return false;
                }

                $saved = $this->execute(fn () => $this->memcached->add($key, $cache));
                if ($saved) {
                    unset($this->tokenExpirations[$key]);
                }

                return $saved ? $data : false;
            }

            $existing = $this->getWithCas($key);
            if ($existing === false) {
                $tombstoneKey = $this->getTombstoneKey($key);
                if ($this->execute(fn () => $this->memcached->get($tombstoneKey)) !== $token->value) {
                    return false;
                }

                $saved = $this->execute(fn () => $this->memcached->add($key, $cache));
                if ($saved) {
                    $this->execute(fn () => $this->memcached->delete($tombstoneKey));
                    unset($this->tokenExpirations[$tombstoneKey]);
                }

                return $saved ? $data : false;
            }

            if (! $this->matchesToken($existing, $token)) {
                return false;
            }

            $saved = $this->execute(fn () => $this->memcached->cas($existing['cas'], $key, $cache));
            if ($saved) {
                unset($this->tokenExpirations[$key]);
            }

            return $saved ? $data : false;
        }

        $saved = $this->execute(fn () => $this->memcached->set($key, $cache));
        if ($saved) {
            unset($this->tokenExpirations[$key]);
        }

        return $saved ? $data : false;
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
        $tombstoneKey = $this->getTombstoneKey($key);

        $saved = (bool) $this->execute(function () use ($key, $tombstoneKey, $token): bool {
            $this->memcached->delete($key);

            return (bool) $this->memcached->set($tombstoneKey, $token->value, self::TOKEN_TTL);
        });
        if ($saved) {
            $this->tokenExpirations[$tombstoneKey] = \time() + self::TOKEN_TTL;
        }

        return $saved ? $token : false;
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
        $this->pruneTokenExpirations();

        $keys = $this->execute(fn () => $this->memcached->getAllKeys());
        if (\is_array($keys) && $keys !== []) {
            return \count(\array_filter($keys, fn (mixed $key): bool => \is_string($key) && ! $this->isTombstoneKey($key)));
        }

        $size = 0;
        $servers = $this->memcached->getServerList();
        if (empty($servers)) {
            return $size;
        }

        $stats = $this->memcached->getStats();
        $key = $servers[0]['host'].':'.$servers[0]['port'];
        if (isset($stats[$key])) {
            $size = $stats[$key]['curr_items'] ?? 0;
        }

        return (int) \max(0, $size - \count($this->tokenExpirations));
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
     * @param  array{value: mixed, cas: float}  $existing
     */
    private function matchesToken(array $existing, Token $token): bool
    {
        $value = $existing['value'];
        if (\is_array($value) && ($value['token'] ?? null) === $token->value) {
            return true;
        }

        return $token->value === self::CAS_TOKEN_PREFIX.(string) $existing['cas'];
    }

    private function createAbsentToken(): Token
    {
        return new Token(self::ABSENT_TOKEN_PREFIX.\bin2hex(\random_bytes(16)));
    }

    private function isAbsentToken(Token $token): bool
    {
        return \str_starts_with($token->value, self::ABSENT_TOKEN_PREFIX);
    }

    private function getTombstoneKey(string $key): string
    {
        return self::TOMBSTONE_PREFIX.\hash('sha256', $key);
    }

    private function isTombstoneKey(string $key): bool
    {
        return \str_starts_with($key, self::TOMBSTONE_PREFIX);
    }

    private function pruneTokenExpirations(): void
    {
        $now = \time();
        foreach ($this->tokenExpirations as $key => $expiresAt) {
            if ($expiresAt <= $now) {
                unset($this->tokenExpirations[$key]);
            }
        }
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
