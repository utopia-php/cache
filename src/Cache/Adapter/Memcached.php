<?php

namespace Utopia\Cache\Adapter;

use Memcached as Client;
use Utopia\Cache\Adapter;
use Utopia\Cache\Feature\FencedFill;
use Utopia\Cache\Feature\Retryable;
use Utopia\Cache\Token;

class Memcached implements Adapter, FencedFill, Retryable
{
    private const ABSENT_TOKEN_PREFIX = 'absent:';

    private const CAS_TOKEN_PREFIX = 'cas:';

    private const TOMBSTONE_PREFIX = '__utopia_cache_token__:';

    private const LOCK_PREFIX = '__utopia_cache_lock__:';

    private const LOCK_TTL = 5;

    private const LOCK_RETRY_DELAY = 10000;

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
        $result = $this->loadFenced($key, $ttl, $hash);

        return $result instanceof Token ? false : $result;
    }

    public function loadFenced(string $key, int $ttl, string $hash = ''): mixed
    {
        $tombstoneKey = $this->getTombstoneKey($key);
        $token = $this->getTombstone($tombstoneKey);
        if (\is_string($token)) {
            return new Token($token);
        }

        $existing = $this->getWithCas($key);
        if ($existing === false) {
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
            return $this->withLock($key, fn () => $this->saveWithToken($key, $data, $cache, $token));
        }

        $saved = $this->withLock($key, function () use ($key, $cache): bool {
            $saved = (bool) $this->execute(fn () => $this->memcached->set($key, $cache));
            if ($saved) {
                $tombstoneKey = $this->getTombstoneKey($key);
                $this->execute(fn () => $this->memcached->delete($tombstoneKey));
                unset($this->tokenExpirations[$tombstoneKey]);
            }

            return $saved;
        });

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

        $saved = (bool) $this->withLock($key, fn (): bool => (bool) $this->execute(function () use ($key, $tombstoneKey, $token): bool {
            $saved = (bool) $this->memcached->set($tombstoneKey, $token->value, self::TOKEN_TTL);
            if ($saved) {
                $this->memcached->delete($key);
            }

            return $saved;
        }));
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

    /**
     * @param  array<int|string, mixed>|string  $data
     * @param  array{time: int, data: array<int|string, mixed>|string}  $cache
     * @return bool|string|array<int|string, mixed>
     */
    private function saveWithToken(string $key, array|string $data, array $cache, Token $token): bool|string|array
    {
        if ($this->isAbsentToken($token)) {
            $tombstoneKey = $this->getTombstoneKey($key);
            if ($this->getTombstone($tombstoneKey) !== false) {
                return false;
            }

            $saved = $this->execute(fn () => $this->memcached->add($key, $cache));
            if ($saved) {
                if ($this->hasTombstoneAfterWrite($tombstoneKey)) {
                    $this->execute(fn () => $this->memcached->delete($key));

                    return false;
                }

                unset($this->tokenExpirations[$this->getTombstoneKey($key)]);
            }

            return $saved ? $data : false;
        }

        $existing = $this->getWithCas($key);
        if ($existing === false) {
            $tombstoneKey = $this->getTombstoneKey($key);
            if ($this->getTombstone($tombstoneKey) !== $token->value) {
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

        $tombstoneKey = $this->getTombstoneKey($key);
        if ($this->getTombstone($tombstoneKey) !== false) {
            return false;
        }

        $saved = $this->execute(fn () => $this->memcached->cas($existing['cas'], $key, $cache));
        if ($saved) {
            if ($this->hasTombstoneAfterWrite($tombstoneKey)) {
                $this->execute(fn () => $this->memcached->delete($key));

                return false;
            }

            unset($this->tokenExpirations[$this->getTombstoneKey($key)]);
        }

        return $saved ? $data : false;
    }

    private function getTombstoneKey(string $key): string
    {
        return self::TOMBSTONE_PREFIX.\hash('sha256', $key);
    }

    private function getTombstone(string $key): mixed
    {
        return $this->execute(fn (): mixed => $this->memcached->get($key));
    }

    /**
     * @phpstan-impure
     */
    private function hasTombstoneAfterWrite(string $key): bool
    {
        return $this->getTombstone($key) !== false;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|false
     */
    private function withLock(string $key, callable $callback): mixed
    {
        $lockKey = self::LOCK_PREFIX.\hash('sha256', $key);
        $lockValue = \bin2hex(\random_bytes(16));
        $lockTtl = $this->getLockTtl();
        $deadline = \microtime(true) + $lockTtl + 1;

        while (\microtime(true) < $deadline) {
            if ($this->execute(fn () => $this->memcached->add($lockKey, $lockValue, $lockTtl))) {
                try {
                    return $callback();
                } finally {
                    if ($this->execute(fn () => $this->memcached->get($lockKey)) === $lockValue) {
                        $this->execute(fn () => $this->memcached->delete($lockKey));
                    }
                }
            }

            \usleep(self::LOCK_RETRY_DELAY);
        }

        return false;
    }

    private function getLockTtl(): int
    {
        $retryWindow = (int) \ceil((($this->maxRetries + 1) * $this->retryDelay) / 1000);

        return \max(self::LOCK_TTL, $retryWindow + 5);
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
