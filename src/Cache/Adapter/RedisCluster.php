<?php

namespace Utopia\Cache\Adapter;

use Exception;
use RedisCluster as Client;
use Throwable;
use Utopia\Cache\Adapter;
use Utopia\Cache\Adapter\Redis\Envelope;
use Utopia\Cache\Feature;
use Utopia\Cache\Feature\Retryable;

class RedisCluster implements Adapter, Retryable, Feature\Lease
{
    private const LEASE_TTL = 30;

    /**
     * @var Client
     */
    protected Client $redis;

    /**
     * @var array<string>
     */
    protected array $seeds;

    /**
     * @var ?string
     */
    protected ?string $name;

    private int $maxRetries = 0;

    private int $retryDelay = 1000; // milliseconds

    private float $timeout;

    private float $readTimeout;

    private bool $persistent;

    /**
     * @var string|array<string>|null
     */
    private string|array|null $auth;

    /**
     * @param  Client  $redis
     * @param  array<string>  $seeds
     * @param  string|null  $name
     * @param  float  $timeout
     * @param  float  $readTimeout
     * @param  bool  $persistent
     * @param  string|array<string>|null  $auth  Password string or ['username', 'password'] array for ACL
     */
    public function __construct(
        Client $redis,
        array $seeds,
        ?string $name = null,
        float $timeout = 1.5,
        float $readTimeout = 1.5,
        bool $persistent = false,
        string|array|null $auth = null
    ) {
        $this->redis = $redis;
        $this->seeds = $seeds;
        $this->name = $name;
        $this->timeout = $timeout;
        $this->readTimeout = $readTimeout;
        $this->persistent = $persistent;
        $this->auth = $auth;
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

        /** @var string|false */
        $redis_string = $this->execute(fn () => $this->redis->hGet($key, $hash));

        if ($redis_string === false || ! is_string($redis_string)) {
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

        try {
            $value = Envelope::encode($data, time());
        } catch (Throwable $th) {
            return false;
        }

        try {
            $this->execute(fn () => $this->redis->hSet($key, $hash, $value));

            return $data;
        } catch (Throwable $th) {
            return false;
        }
    }

    public function lease(string $key, string $hash = '', ?int $ttl = null): string|false
    {
        if (empty($key)) {
            return false;
        }

        if (empty($hash)) {
            $hash = $key;
        }

        try {
            $token = \bin2hex(\random_bytes(16));
            $value = json_encode([
                'time' => \time(),
                'lease' => $token,
            ], flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }

        $script = <<<'LUA'
local existing = redis.call('HGET', KEYS[1], ARGV[1])
if existing == false then
    redis.call('HSET', KEYS[1], ARGV[1], ARGV[2])
    return 1
end

local ok, decoded = pcall(cjson.decode, existing)
if ok and decoded['lease'] ~= nil and decoded['time'] + tonumber(ARGV[3]) <= tonumber(ARGV[5]) then
    redis.call('HSET', KEYS[1], ARGV[1], ARGV[2])
    return 1
end

if ok and decoded['data'] ~= nil and ARGV[4] ~= '' and decoded['time'] + tonumber(ARGV[4]) <= tonumber(ARGV[5]) then
    redis.call('HSET', KEYS[1], ARGV[1], ARGV[2])
    return 1
end

return 0
LUA;

        try {
            $result = $this->execute(fn () => $this->redis->eval($script, [$key, $hash, $value, (string) self::LEASE_TTL, $ttl === null ? '' : (string) $ttl, (string) \time()], 1));
            if (! \is_int($result) && ! \is_string($result)) {
                return false;
            }

            return ((int) $result === 1) ? $value : false;
        } catch (Throwable) {
            return false;
        }
    }

    public function saveLease(string $key, array|string $data, string $token, string $hash = ''): bool|string|array
    {
        if (empty($key) || empty($data) || empty($token)) {
            return false;
        }

        if (empty($hash)) {
            $hash = $key;
        }

        try {
            $value = Envelope::encode($data, time());
        } catch (Throwable) {
            return false;
        }

        $script = <<<'LUA'
if redis.call('HGET', KEYS[1], ARGV[1]) == ARGV[2] then
    redis.call('HSET', KEYS[1], ARGV[1], ARGV[3])
    return 1
end
return 0
LUA;

        try {
            $result = $this->execute(fn () => $this->redis->eval($script, [$key, $hash, $token, $value], 1));
            if (! \is_int($result) && ! \is_string($result)) {
                return false;
            }

            return ((int) $result === 1) ? $data : false;
        } catch (Throwable) {
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

        /** @var string|false */
        $redis_string = $this->execute(fn () => $this->redis->hGet($key, $hash));

        if ($redis_string === false || ! is_string($redis_string)) {
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
        $keys = (array) $this->execute(fn () => $this->redis->hKeys($key));

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
            return (bool) $this->execute(fn () => $this->redis->hdel($key, $hash));
        }

        return (bool) $this->execute(fn () => $this->redis->del($key));
    }

    /**
     * @return bool
     */
    public function flush(): bool
    {
        return (bool) $this->execute(function () {
            /** @var array<string> $masters */
            $masters = $this->redis->_masters();
            foreach ($masters as $master) {
                $this->redis->flushDB($master);
            }

            return true;
        });
    }

    /**
     * @return bool
     */
    public function ping(): bool
    {
        try {
            return (bool) $this->execute(function () {
                foreach ($this->redis->_masters() as $master) {
                    $this->redis->ping($master);
                }

                return true;
            });
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
        $size = $this->execute(function () {
            $size = 0;
            foreach ($this->redis->_masters() as $master) {
                $size += $this->redis->dbSize($master);
            }

            return $size;
        });

        if ($size === false || ! is_numeric($size)) {
            return 0;
        }

        return (int) $size;
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
     * @return ?string
     */
    public function getClusterName(): ?string
    {
        return $this->name;
    }

    /**
     * @return array<string>
     */
    public function getSeeds(): array
    {
        return $this->seeds;
    }

    /**
     * Execute a Redis command with retry logic
     *
     * @param  callable  $callback
     * @return mixed
     *
     * @throws \RedisClusterException
     */
    private function execute(callable $callback): mixed
    {
        $attempts = 0;
        $maxAttempts = 1 + $this->maxRetries;

        while ($attempts < $maxAttempts) {
            try {
                return $callback();
            } catch (\RedisClusterException $th) {
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
                } catch (\RedisClusterException $e) {
                    // Reconnect failed, will retry on next iteration
                }
            }
        }

        // This line is unreachable but required for PHPStan
        throw new \RedisClusterException('Failed to execute Redis command');
    }

    /**
     * Check if the exception is a connection-related error that should trigger reconnect.
     *
     * RedisClusterException always returns error code 0 with no subclasses for different error types.
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
            // Redis Cluster specific
            "couldn't map cluster keyspace",
            "can't communicate with any node",
            'clusterdown',
            'is not covered by any node',
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
        $newRedis = new Client(
            $this->name,
            $this->seeds,
            $this->timeout,
            $this->readTimeout,
            $this->persistent,
            $this->auth
        );

        $this->redis = $newRedis;
    }

    /**
     * @param  string|null  $key
     * @return string
     */
    public function getName(?string $key = null): string
    {
        return 'redis-cluster';
    }
}
