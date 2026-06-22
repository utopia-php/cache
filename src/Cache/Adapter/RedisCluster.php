<?php

namespace Utopia\Cache\Adapter;

use Exception;
use RedisCluster as Client;
use Throwable;
use Utopia\Cache\Adapter;
use Utopia\Cache\Adapter\Redis\Envelope;
use Utopia\Cache\Feature\Retryable;
use Utopia\Cache\Token;

class RedisCluster implements Adapter, Retryable
{
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

        $result = $this->loadOrReserve($key, $hash, $ttl);
        if (! \is_array($result) || ! isset($result[0])) {
            return false;
        }

        $status = $result[0];
        if (! \is_int($status) && ! \is_string($status)) {
            return false;
        }

        if ((int) $status === 2 && isset($result[1]) && \is_string($result[1])) {
            return new Token($result[1]);
        }

        if ((int) $status === 1 && isset($result[1]) && \is_string($result[1])) {
            return Envelope::decode($result[1], $ttl, time());
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

        if (empty($hash)) {
            $hash = $key;
        }

        try {
            $value = Envelope::encode($data, time());
        } catch (Throwable $th) {
            return false;
        }

        try {
            if ($token !== null) {
                $script = <<<'LUA'
if redis.call('HGET', KEYS[1], ARGV[1]) == ARGV[2] then
    redis.call('HSET', KEYS[1], ARGV[1], ARGV[3])
    return 1
end
return 0
LUA;

                $result = $this->execute(fn () => $this->redis->eval($script, [$key, $hash, $token->value, $value], 1));
                if ((! \is_int($result) && ! \is_string($result)) || (int) $result !== 1) {
                    return false;
                }

                return $data;
            }

            $this->execute(fn () => $this->redis->hSet($key, $hash, $value));

            return $data;
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
     * @return Token|false
     */
    public function purge(string $key, string $hash = ''): Token|false
    {
        $token = $this->createToken();
        if ($token === false) {
            return false;
        }

        if (! empty($hash)) {
            return $this->execute(fn () => $this->redis->hDel($key, $hash)) !== false ? $token : false;
        }

        return $this->execute(fn () => $this->redis->del($key)) !== false ? $token : false;
    }

    /**
     * @return array<int, mixed>|false
     */
    private function loadOrReserve(string $key, string $hash, int $ttl): array|false
    {
        $token = $this->createToken();
        if ($token === false) {
            return false;
        }

        $script = <<<'LUA'
local value = redis.call('HGET', KEYS[1], ARGV[1])
if value then
    local ok, payload = pcall(cjson.decode, value)
    if ok and type(payload) == 'table' then
        if payload['data'] ~= nil and type(payload['time']) == 'number' then
            if payload['time'] + tonumber(ARGV[2]) > tonumber(ARGV[3]) then
                return {1, value}
            end
            return {0, ''}
        end
        if payload['token'] ~= nil and payload['data'] == nil then
            redis.call('HSET', KEYS[1], ARGV[1], ARGV[4])
            return {2, ARGV[4]}
        end
    end
    return {0, ''}
end

redis.call('HSET', KEYS[1], ARGV[1], ARGV[4])
return {2, ARGV[4]}
LUA;

        $result = $this->execute(fn () => $this->redis->eval($script, [$key, $hash, (string) $ttl, (string) \time(), $token->value], 1));

        return \is_array($result) ? $result : false;
    }

    private function createToken(): Token|false
    {
        try {
            return new Token(json_encode([
                'time' => \time(),
                'token' => \bin2hex(\random_bytes(16)),
            ], flags: JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            return false;
        }
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
