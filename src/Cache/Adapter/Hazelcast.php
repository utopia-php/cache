<?php

namespace Utopia\Cache\Adapter;

use Memcached as Client;
use Utopia\Cache\Adapter;
use Utopia\Cache\Feature\Retryable;
use Utopia\Cache\Token;

class Hazelcast implements Adapter, Retryable
{
    private const ABSENT_TOKEN_PREFIX = 'absent:';

    private const CAS_TOKEN_PREFIX = 'cas:';

    private const TOKEN_TTL = 60;

    /**
     * @var Client
     */
    protected Client $memcached;

    private int $maxRetries = 0;

    private int $retryDelay = 1000; // milliseconds

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
     * @param  int  $ttl time in seconds
     * @param  string  $hash optional
     * @return mixed
     */
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        $existing = $this->getWithCas($key);
        if ($existing === false || ! \is_string($existing['value'])) {
            return $this->createAbsentToken();
        }

        $cache = $existing['value'];
        if (is_string($cache)) {
            $cache = json_decode($cache, true);
        }

        if (! is_array($cache)) {
            $token = $this->purge($key, $hash);

            return $token;
        }

        if (! isset($cache['data'])) {
            $token = $this->purge($key, $hash);

            return $token;
        }

        if (($cache['time'] + $ttl > time())) { // Cache is valid
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
            'time' => time(),
            'data' => $data,
        ];
        $payload = json_encode($cache);
        if ($payload === false) {
            return false;
        }

        if ($token !== null) {
            if ($this->isAbsentToken($token)) {
                return ($this->execute(fn () => $this->memcached->add($key, $payload))) ? $data : false;
            }

            $existing = $this->getWithCas($key);
            if ($existing === false || ! \is_string($existing['value'])) {
                return false;
            }

            $value = json_decode($existing['value'], true);
            if (! \is_array($value) || ! $this->matchesToken($value, $existing['cas'], $token)) {
                return false;
            }

            if ($existing['cas'] <= 0) {
                return false;
            }

            return ($this->execute(fn () => $this->memcached->cas($existing['cas'], $key, $payload))) ? $data : false;
        }

        return ($this->execute(fn () => $this->memcached->set($key, $payload))) ? $data : false;
    }

    /**
     * @param  string  $key
     * @param  string  $hash optional
     * @return bool
     */
    public function touch(string $key, string $hash = ''): bool
    {
        $cache = $this->execute(fn () => $this->memcached->get($key));
        if (is_string($cache)) {
            $cache = json_decode($cache, true);
        }

        if (! is_array($cache) || ! isset($cache['data'])) {
            return false;
        }

        $cache['time'] = time();

        return (bool) $this->execute(fn () => $this->memcached->set($key, json_encode($cache)));
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

        return (bool) $this->execute(fn () => $this->memcached->set($key, json_encode($cache), self::TOKEN_TTL)) ? $token : false;
    }

    /**
     * @return bool
     * currently hazelcast doesn't support flush functionality, so returning false in that case
     */
    public function flush(): bool
    {
        return false;
    }

    /**
     * @return bool
     */
    public function ping(): bool
    {
        try {
            $statuses = $this->execute(fn () => $this->memcached->getServerList());

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
                $size = $stats[$key]['total_items'] ?? 0;
            }
        }

        return $size;
    }

    /**
     * @return string
     */
    public function getName(?string $key = null): string
    {
        return 'hazelcast';
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

        return [
            'value' => $result['value'],
            'cas' => (float) $cas,
        ];
    }

    /**
     * @param  array{time?: int, data?: mixed, token?: string}  $value
     */
    private function matchesToken(array $value, float $cas, Token $token): bool
    {
        if (($value['token'] ?? null) === $token->value) {
            return true;
        }

        return $token->value === self::CAS_TOKEN_PREFIX.(string) $cas;
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
                    throw new \MemcachedException('Hazelcast connection failed after '.$attempts.' attempts. Error: '.$this->memcached->getResultMessage());
                }

                usleep($this->retryDelay * 1000);

                continue;
            }

            return $result;
        }

        return false;
    }
}
