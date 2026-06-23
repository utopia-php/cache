<?php

namespace Utopia\Cache\Adapter;

use Exception;
use Redis as Client;
use Throwable;
use Utopia\Cache\Adapter;
use Utopia\Cache\Adapter\Redis\Envelope;
use Utopia\Cache\Feature\Leasable;
use Utopia\Cache\Feature\Retryable;

class Redis implements Adapter, Leasable, Retryable
{
    /**
     * Reserved hash field that holds a key's generation alongside its value
     * fields. Keeping the generation inside the value's own hash makes every
     * lease operation single-key (one shard): no sidecar key to collide with or
     * scan, and the scripts stay correct under Redis Cluster / multi-threaded
     * backends (e.g. Dragonfly), where a multi-key script would span shards.
     * Callers must not use this as a cache hash/field.
     */
    private const GENERATION_FIELD = '__utopia_gen__';

    /**
     * Save $hash field into hash $key only when the key's generation field still
     * equals the caller's token. Single-key. KEYS[1]=key; ARGV[1]=hash,
     * ARGV[2]=value, ARGV[3]=expected generation.
     */
    private const LUA_SAVE_WITH_LEASE = <<<'LUA'
        local current = redis.call('HGET', KEYS[1], '__utopia_gen__')
        if current == false then current = '0' end
        if current ~= ARGV[3] then return 0 end
        redis.call('HSET', KEYS[1], ARGV[1], ARGV[2])
        return 1
        LUA;

    /**
     * Drop $key's value fields and advance its generation in one step, so an
     * in-flight reader holding the previous generation cannot re-cache stale
     * data. The bump is unconditional: a reader may have read a row the writer is
     * now deleting before anything was cached, so the generation must move even
     * when nothing was cached. Returns the number of value fields removed (the
     * generation field is excluded), preserving purge() semantics. The
     * generation field is the only thing that survives a purge, so it outlives
     * any reader holding an older generation. Single-key. KEYS[1]=key.
     */
    private const LUA_PURGE_BUMP = <<<'LUA'
        local field = '__utopia_gen__'
        local removed = redis.call('HLEN', KEYS[1]) - redis.call('HEXISTS', KEYS[1], field)
        local current = redis.call('HGET', KEYS[1], field)
        local next = (tonumber(current) or 0) + 1
        redis.call('DEL', KEYS[1])
        redis.call('HSET', KEYS[1], field, next)
        return removed
        LUA;

    /**
     * @var Client
     */
    protected Client $redis;

    private int $maxRetries = 0;

    private int $retryDelay = 1000; // milliseconds

    private string $host;

    private int $port;

    private float $timeout;

    private ?string $persistentId;

    private float $readTimeout;

    /**
     * @var string|array<string>|null
     */
    private string|array|null $auth = null;

    /**
     * Whether the original connection was persistent (pconnect)
     */
    private bool $persistent = false;

    private int $dbIndex = 0;

    /**
     * Redis constructor.
     *
     * @param  Client  $redis
     */
    public function __construct(Client $redis)
    {
        $this->host = $redis->getHost();
        $this->port = $redis->getPort();
        $timeout = $redis->getTimeout();
        $this->timeout = ($timeout !== false) ? (float) $timeout : 0.0;
        $this->persistentId = $redis->getPersistentId();
        $this->readTimeout = $redis->getReadTimeout();

        $this->persistent = $this->persistentId !== null;
        $this->dbIndex = $redis->getDbNum();

        $auth = $redis->getAuth();
        if ($auth !== null && $auth !== false) {
            $this->auth = $auth;
        }

        $this->redis = $redis;
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
        if (empty($hash)) {
            $hash = $key;
        }

        // The generation lives in this same hash; never let a caller read, write
        // or delete it through the public field API, or they could reset the
        // generation and revive stale lease tokens.
        if ($hash === self::GENERATION_FIELD) {
            return false;
        }

        $redis_string = $this->execute(fn () => $this->redis->hGet($key, $hash));

        if (! is_string($redis_string)) {
            return false;
        }

        return Envelope::decode($redis_string, $ttl, time());
    }

    /**
     * @param  string  $key
     * @param  array<int|string, mixed>|string  $data
     * @param  string  $hash optional
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, array|string $data, string $hash = ''): bool|string|array
    {
        if (empty($key) || empty($data)) {
            return false;
        }

        if (empty($hash)) {
            $hash = $key;
        }

        // The generation lives in this same hash; never let a caller read, write
        // or delete it through the public field API, or they could reset the
        // generation and revive stale lease tokens.
        if ($hash === self::GENERATION_FIELD) {
            return false;
        }

        try {
            $value = Envelope::encode($data, time());
            $this->execute(fn () => $this->redis->hSet($key, $hash, $value));

            return $data;
        } catch (Throwable $th) {
            return false;
        }
    }

    public function getGeneration(string $key): string
    {
        $gen = $this->execute(fn () => $this->redis->hGet($key, self::GENERATION_FIELD));

        return \is_string($gen) ? $gen : '0';
    }

    public function saveWithLease(string $key, array|string $data, string $hash, string $generation): bool|string|array
    {
        if (empty($key) || empty($data)) {
            return false;
        }

        if (empty($hash)) {
            $hash = $key;
        }

        // The generation lives in this same hash; never let a caller read, write
        // or delete it through the public field API, or they could reset the
        // generation and revive stale lease tokens.
        if ($hash === self::GENERATION_FIELD) {
            return false;
        }

        try {
            $value = Envelope::encode($data, time());
            $stored = $this->execute(fn () => $this->redis->eval(
                self::LUA_SAVE_WITH_LEASE,
                [$key, $hash, $value, $generation],
                1
            ));

            return $stored ? $data : false;
        } catch (Throwable $th) {
            return false;
        }
    }

    /**
     * @param  string  $key
     * @param  string  $hash optional
     * @return bool
     */
    public function touch(string $key, string $hash = ''): bool
    {
        if (empty($hash)) {
            $hash = $key;
        }

        // The generation lives in this same hash; never let a caller read, write
        // or delete it through the public field API, or they could reset the
        // generation and revive stale lease tokens.
        if ($hash === self::GENERATION_FIELD) {
            return false;
        }

        $redis_string = $this->execute(fn () => $this->redis->hGet($key, $hash));

        if (! is_string($redis_string)) {
            return false;
        }

        $value = Envelope::touch($redis_string, time());
        if ($value === false) {
            return false;
        }

        return $this->execute(fn () => $this->redis->hSet($key, $hash, $value)) !== false;
    }

    /**
     * @param  string  $key
     * @return string[]
     */
    public function list(string $key): array
    {
        /** @var array<string> */
        $keys = $this->execute(fn () => $this->redis->hKeys($key));

        if (empty($keys)) {
            return [];
        }

        return $keys;
    }

    /**
     * @param  string  $key
     * @param  string  $hash optional
     * @return bool
     */
    public function purge(string $key, string $hash = ''): bool
    {
        if (! empty($hash)) {
            if ($hash === self::GENERATION_FIELD) {
                return false;
            }

            return (bool) $this->execute(fn () => $this->redis->hdel($key, $hash));
        }

        // Drop the value fields and advance the in-hash generation in one atomic
        // step (single-key Redis Lua) so an in-flight reader holding the previous
        // generation cannot re-cache stale data after this purge.
        return (bool) $this->execute(fn () => $this->redis->eval(
            self::LUA_PURGE_BUMP,
            [$key],
            1
        ));
    }

    /**
     * @return bool
     */
    public function flush(): bool
    {
        return (bool) $this->execute(fn () => $this->redis->flushDB());
    }

    /**
     * @return bool
     */
    public function ping(): bool
    {
        try {
            $this->redis->ping();

            return true;
        } catch (Exception $e) {
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
        // Trade-off of the in-hash generation: a purged key keeps a tiny hash
        // holding only its generation field until it is re-cached, so it still
        // counts here. Filtering those out would need an O(N) scan with a per-key
        // HLEN check; for a diagnostic counter that isn't worth it, so DBSIZE may
        // slightly over-count purged-but-not-yet-recached keys.
        /** @var int $size */
        $size = $this->execute(fn () => $this->redis->dbSize());

        return $size;
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
     * Execute a Redis command with retry logic
     *
     * @param  callable  $callback
     * @return mixed
     *
     * @throws \RedisException
     */
    private function execute(callable $callback): mixed
    {
        $attempts = 0;
        $maxAttempts = 1 + $this->maxRetries;

        while ($attempts < $maxAttempts) {
            try {
                return $callback();
            } catch (\RedisException $th) {
                if (! $this->isConnectionError($th)) {
                    throw $th;
                }

                $attempts++;

                if ($attempts >= $maxAttempts) {
                    throw $th;
                }

                usleep($this->retryDelay * 1000); // Convert milliseconds to microseconds

                try {
                    $this->reconnect();
                } catch (\RedisException $e) {
                    // Reconnect failed, will retry on next iteration
                }
            }
        }

        // This line is unreachable but required for PHPStan
        throw new \RedisException('Failed to execute Redis command');
    }

    /**
     * Check if the exception is a connection-related error that should trigger reconnect.
     *
     * RedisException always returns error code 0 with no subclasses for different error types.
     * The only way to differentiate connection errors from command errors is by message matching.
     *
     * @param  Exception  $e
     * @return bool
     */
    private function isConnectionError(Exception $e): bool
    {
        $connectionErrors = [
            'went away',
            'socket',
            'read error on connection',
            'connection lost',
            'timed out',
            'timeout',
            'connection refused',
            'no connection',
            'broken pipe',
        ];

        $message = strtolower($e->getMessage());
        foreach ($connectionErrors as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function reconnect(): void
    {
        $newRedis = new Client();

        if ($this->persistent) {
            $newRedis->pconnect(
                $this->host,
                $this->port,
                $this->timeout,
                $this->persistentId,
                0,
                $this->readTimeout,
            );
        } else {
            $newRedis->connect(
                $this->host,
                $this->port,
                $this->timeout,
                $this->persistentId,
                0,
                $this->readTimeout,
            );
        }

        if ($this->auth !== null) {
            $newRedis->auth($this->auth);
        }

        if ($this->dbIndex !== 0) {
            $newRedis->select($this->dbIndex);
        }

        $this->redis = $newRedis;
    }

    public function getName(?string $key = null): string
    {
        return 'redis';
    }
}
