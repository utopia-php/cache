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
     * Reserved hash field holding the epoch-microsecond deadline until which
     * saveWithLease() is refused after a purge. The generation lease alone cannot
     * reject a reader that captured the post-purge generation yet read the row
     * from a source lagging behind the write (a replica, or an older MVCC
     * snapshot on a pooled connection): its token matches, so the stale value
     * would be cached. This tombstone shrinks that window by refusing every lease
     * save for graceWindow ms after a purge, regardless of token. It is a bounded
     * mitigation, not a proof: a reader whose read stays stale for longer than
     * graceWindow can still slip through, so size graceWindow to the worst-case
     * read-after-write staleness (replica lag / snapshot lifetime). Fully closing
     * the race needs a database freshness token (LSN/GTID), not a wall clock.
     * Lives in the same hash to keep operations single-key. Not a usable field.
     */
    private const TOMBSTONE_FIELD = '__utopia_tomb__';

    /**
     * Save $hash field into hash $key only when the key's generation field still
     * equals the caller's token AND, when a grace window is set, the key is past
     * its post-purge tombstone. redis.call('TIME') is CLOCK_REALTIME, so a
     * deadline more than the window ahead of now means the wall clock stepped
     * backward since the stamp; it is ignored rather than wedging saves, which
     * also bounds any block to graceWindow of real time. A non-numeric window or
     * deadline is treated as absent (fail open), and a spent/invalid deadline is
     * dropped so a field-purged hash doesn't keep the tombstone forever.
     * Single-key. KEYS[1]=key; ARGV[1]=hash, ARGV[2]=value, ARGV[3]=expected
     * generation, ARGV[4]=grace window in ms (0 disables it).
     */
    private const LUA_SAVE_WITH_LEASE = <<<'LUA'
        local current = redis.call('HGET', KEYS[1], '__utopia_gen__')
        if current == false then current = '0' end
        if current ~= ARGV[3] then return 0 end
        local window = tonumber(ARGV[4]) or 0
        if window > 0 then
            local tomb = redis.call('HGET', KEYS[1], '__utopia_tomb__')
            if tomb ~= false then
                local deadline = tonumber(tomb)
                if deadline ~= nil then
                    local t = redis.call('TIME')
                    local now = tonumber(t[1]) * 1000000 + tonumber(t[2])
                    if now < deadline and (deadline - now) <= window * 1000 then
                        return 0
                    end
                end
                redis.call('HDEL', KEYS[1], '__utopia_tomb__')
            end
        end
        redis.call('HSET', KEYS[1], ARGV[1], ARGV[2])
        return 1
        LUA;

    /**
     * Drop $key's value fields and advance its generation in one step, so an
     * in-flight reader holding the previous generation cannot re-cache stale
     * data. The bump is unconditional: a reader may have read a row the writer is
     * now deleting before anything was cached, so the generation must move even
     * when nothing was cached. When ARGV[1] (grace window, ms) is > 0 the key is
     * also stamped with a tombstone that refuses lease saves for that long, so a
     * reader whose read lags the write cannot repopulate stale data even with a
     * matching token. Returns the number of value fields removed (the reserved
     * generation and tombstone fields are excluded), preserving purge()
     * semantics. Single-key. KEYS[1]=key; ARGV[1]=grace window in ms.
     */
    private const LUA_PURGE_BUMP = <<<'LUA'
        local gen = '__utopia_gen__'
        local tomb = '__utopia_tomb__'
        local removed = redis.call('HLEN', KEYS[1]) - redis.call('HEXISTS', KEYS[1], gen) - redis.call('HEXISTS', KEYS[1], tomb)
        local current = redis.call('HGET', KEYS[1], gen)
        local next = (tonumber(current) or 0) + 1
        redis.call('DEL', KEYS[1])
        redis.call('HSET', KEYS[1], gen, next)
        local window = tonumber(ARGV[1]) or 0
        if window > 0 then
            local t = redis.call('TIME')
            local now = tonumber(t[1]) * 1000000 + tonumber(t[2])
            redis.call('HSET', KEYS[1], tomb, now + window * 1000)
        end
        return removed
        LUA;

    /**
     * Delete a single $hash field and advance the key's generation in one step,
     * so a field-level purge invalidates in-flight leases just like a full purge,
     * and stamp the post-purge tombstone when a grace window is set. The tombstone
     * is key-level, so it also briefly blocks lease re-caching of the key's other
     * fields (conservative: never caches stale, may skip a fresh sibling save
     * within the window). Returns the number of fields removed, preserving
     * purge()'s deletion-result semantics. Single-key. KEYS[1]=key; ARGV[1]=field,
     * ARGV[2]=grace window in ms.
     */
    private const LUA_PURGE_FIELD = <<<'LUA'
        local removed = redis.call('HDEL', KEYS[1], ARGV[1])
        local current = redis.call('HGET', KEYS[1], '__utopia_gen__')
        local next = (tonumber(current) or 0) + 1
        redis.call('HSET', KEYS[1], '__utopia_gen__', next)
        local window = tonumber(ARGV[2]) or 0
        if window > 0 then
            local t = redis.call('TIME')
            local now = tonumber(t[1]) * 1000000 + tonumber(t[2])
            redis.call('HSET', KEYS[1], '__utopia_tomb__', now + window * 1000)
        end
        return removed
        LUA;

    /**
     * @var Client
     */
    protected Client $redis;

    private int $maxRetries = 0;

    private int $retryDelay = 1000; // milliseconds

    /**
     * Milliseconds after a purge during which saveWithLease() is refused, to
     * defend against a reader whose read lags the purge's write (replica lag or a
     * stale MVCC snapshot on a pooled connection). Size it to the worst-case
     * read-after-write staleness horizon: a read that stays stale longer than
     * this can still slip through. 0 disables the tombstone and preserves the
     * pure generation-lease behaviour.
     */
    private int $leaseGraceWindow = 0;

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
     * @param  int  $milliseconds window after a purge during which lease saves are
     *                            refused; size it to the worst-case read-after-write
     *                            staleness horizon (0 disables the tombstone)
     * @return self
     */
    public function setLeaseGraceWindow(int $milliseconds): self
    {
        $this->leaseGraceWindow = max(0, $milliseconds);

        return $this;
    }

    public function getLeaseGraceWindow(): int
    {
        return $this->leaseGraceWindow;
    }

    /**
     * The generation and tombstone live in each key's own hash; a caller must
     * never read, write or delete them through the public field API, or they
     * could reset the generation and revive stale lease tokens, or tamper with
     * the post-purge tombstone.
     */
    private function isReserved(string $hash): bool
    {
        return $hash === self::GENERATION_FIELD || $hash === self::TOMBSTONE_FIELD;
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

        if ($this->isReserved($hash)) {
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

        if ($this->isReserved($hash)) {
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

        if ($this->isReserved($hash)) {
            return false;
        }

        try {
            $value = Envelope::encode($data, time());
            $stored = $this->execute(fn () => $this->redis->eval(
                self::LUA_SAVE_WITH_LEASE,
                [$key, $hash, $value, $generation, $this->leaseGraceWindow],
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

        if ($this->isReserved($hash)) {
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

        // Don't expose reserved internal fields (generation, tombstone) as listable cache fields.
        return \array_values(\array_filter($keys, fn (string $field): bool => ! $this->isReserved($field)));
    }

    /**
     * @param  string  $key
     * @param  string  $hash optional
     * @return bool
     */
    public function purge(string $key, string $hash = ''): bool
    {
        if (! empty($hash)) {
            if ($this->isReserved($hash)) {
                return false;
            }

            return (bool) $this->execute(fn () => $this->redis->eval(
                self::LUA_PURGE_FIELD,
                [$key, $hash, $this->leaseGraceWindow],
                1
            ));
        }

        // Drop the value fields and advance the in-hash generation in one atomic
        // step (single-key Redis Lua) so an in-flight reader holding the previous
        // generation cannot re-cache stale data after this purge, and stamp the
        // post-purge tombstone when a grace window is configured.
        return (bool) $this->execute(fn () => $this->redis->eval(
            self::LUA_PURGE_BUMP,
            [$key, $this->leaseGraceWindow],
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
        // holding its reserved generation (and, when a grace window is set,
        // tombstone) field until it is re-cached, so it still counts here.
        // Filtering those out would need an O(N) scan with a per-key HLEN check;
        // for a diagnostic counter that isn't worth it, so DBSIZE may slightly
        // over-count purged-but-not-yet-recached keys.
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
