<?php

namespace Utopia\Cache\Adapter;

use Exception;
use Redis as Client;
use RedisException;
use Throwable;
use Utopia\Cache\Adapter;

class Redis implements Adapter
{
    /**
     * @var Client
     */
    protected Client $redis;

    /**
     * Redis host
     *
     * @var string|null
     */
    protected ?string $host = null;

    /**
     * Redis port
     *
     * @var int
     */
    protected ?int $port = null;

    /**
     * Redis max attempts
     *
     * @var int
     */
    protected int $maxAttempts;

    /**
     * Redis initial delay
     *
     * @var int
     */
    protected int $initialDelayMs;

    /**
     * Redis constructor.
     *
     * @param  Client  $redis
     */
    public function __construct(Client $redis, ?string $host = null, ?int $port = null, ?int $maxAttempts = null, ?int $initialDelayMs = null)
    {
        $this->redis = $redis;
        $this->host = $host;
        $this->port = $port;
        $this->maxAttempts = max(1, $maxAttempts ?? 3);
        $this->initialDelayMs = max(0, $initialDelayMs ?? 100);
    }

    protected function isConnectionIssue(RedisException $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, 'Connection lost')
            || str_contains($msg, 'went away')
            || str_contains($msg, 'read error')
            || str_contains($msg, 'timed out');
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

        $getCache = function () use ($key, $hash, $ttl) {
            $redis_string = $this->redis->hGet($key, $hash);

            if ($redis_string === false) {
                return false;
            }

            /** @var array{time: int, data: string} */
            $cache = json_decode($redis_string, true);

            if ($cache['time'] + $ttl > time()) { // Cache is valid
                return $cache['data'];
            }

            return false;
        };

        try {
            return $getCache();
        } catch (RedisException $e) {
            if ($this->isConnectionIssue($e)) {
                if ($this->attemptReconnectWithBackoff()) {
                    try {
                        return $getCache();
                    } catch (RedisException $e2) {
                        return false;
                    }
                }
            }
        } catch (Throwable $th) {
            return false;
        }

        return false;
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
            $value = json_encode([
                'time' => \time(),
                'data' => $data,
            ], flags: JSON_THROW_ON_ERROR);
        } catch(Throwable $th) {
            return false;
        }

        $setCache = function () use ($key, $hash, $value, $data) {
            $this->redis->hSet($key, $hash, $value);

            return $data;
        };

        try {
            return $setCache();
        } catch (RedisException $e) {
            if ($this->isConnectionIssue($e)) {
                if ($this->attemptReconnectWithBackoff()) {
                    try {
                        return $setCache();
                    } catch (RedisException $e2) {
                        return false;
                    }
                }
            }
        } catch (Throwable $th) {
            return false;
        }

        return false;
    }

    /**
     * @param  string  $key
     * @return string[]
     */
    public function list(string $key): array
    {
        $keys = $this->redis->hKeys($key);

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
            return (bool) $this->redis->hdel($key, $hash);
        }

        return (bool) $this->redis->del($key);
    }

    /**
     * @return bool
     */
    public function flush(): bool
    {
        return $this->redis->flushAll();
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
        return $this->redis->dbSize();
    }

    /**
     * @param  string|null  $key
     * @return string
     */
    public function getName(?string $key = null): string
    {
        return 'redis';
    }

    /**
     * Attempt to reconnect to Redis with retry and exponential backoff.
     *
     * @return bool true if reconnected successfully, false otherwise
     */
    protected function attemptReconnectWithBackoff(): bool
    {
        if ($this->host === null || $this->port === null) {
            return false;
        }

        $attempt = 0;
        $delayMs = $this->initialDelayMs;

        while ($attempt < $this->maxAttempts) {
            try {
                $this->redis->connect($this->host, $this->port);

                return true;
            } catch (RedisException $e) {
                $attempt++;

                if ($attempt >= $this->maxAttempts) {
                    break;
                }

                usleep($delayMs * 1000);
                $delayMs *= 2;
            } catch (Throwable $th) {
                return false;
            }
        }

        return false;
    }
}
