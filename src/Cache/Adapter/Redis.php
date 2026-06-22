<?php

namespace Utopia\Cache\Adapter;

use Exception;
use Redis as Client;
use Throwable;
use Utopia\Cache\Adapter;
use Utopia\Cache\Adapter\Redis\Envelope;
use Utopia\Cache\Feature\Retryable;
use Utopia\Cache\Token;

class Redis implements Adapter, Retryable
{
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
        /** @var int */
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
