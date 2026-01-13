<?php

namespace Utopia\Cache\Adapter;

use Exception;
use RedisCluster as Client;
use Throwable;
use Utopia\Cache\Adapter;

class RedisCluster implements Adapter
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

    /**
     * Redis constructor.
     *
     * @param  Client  $redis
     * @param  array<string>  $seeds
     */
    public function __construct(Client $redis, array $seeds, ?string $name = null)
    {
        $this->redis = $redis;
        $this->seeds = $seeds;
        $this->name = $name;
    }

    /**
     * Set the maximum number of retries.
     *
     * The client will automatically retry the request if an connection error occurs.
     * If the request fails after the maximum number of retries, an exception will be thrown.
     *
     * @param  int  $maxRetries
     * @return self
     */
    public function setMaxRetries(int $maxRetries): self
    {
        $this->maxRetries = $maxRetries;

        return $this;
    }

    /**
     * Set the retry delay in milliseconds.
     *
     * @param  int  $retryDelay
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
        $redis_string = $this->executeRedisCommand(fn () => $this->redis->hGet($key, $hash));

        if ($redis_string === false) {
            return false;
        }

        /** @var array{time: int, data: string} $cache */
        $cache = json_decode($redis_string, true);

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
        } catch (Throwable $th) {
            return false;
        }

        try {
            $this->executeRedisCommand(fn () => $this->redis->hSet($key, $hash, $value));

            return $data;
        } catch (Throwable $th) {
            return false;
        }
    }

    /**
     * @param  string  $key
     * @return string[]
     */
    public function list(string $key): array
    {
        /** @var array<string> */
        $keys = (array) $this->executeRedisCommand(fn () => $this->redis->hKeys($key));

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
            return (bool) $this->executeRedisCommand(fn () => $this->redis->hdel($key, $hash));
        }

        return (bool) $this->executeRedisCommand(fn () => $this->redis->del($key));
    }

    /**
     * @return bool
     */
    public function flush(): bool
    {
        return (bool) $this->executeRedisCommand(function () {
            foreach ($this->redis->_masters() as $master) {
                $this->redis->flushAll($master);
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
            return (bool) $this->executeRedisCommand(function () {
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
        $size = $this->executeRedisCommand(function () {
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
        return 0;
    }

    /**
     * @return int
     */
    public function getRetryDelay(): int
    {
        return 1000;
    }

    /**
     * @return ?string
     */
    public function getName(): ?string
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
     * @throws \RedisException
     */
    private function executeRedisCommand(callable $callback): mixed
    {
        $attempts = 0;
        $maxAttempts = max(1, $this->maxRetries);

        while ($attempts < $maxAttempts) {
            try {
                return $callback();
            } catch (\RedisClusterException $th) {
                $this->reconnect();
                $attempts++;

                if ($attempts >= $maxAttempts) {
                    throw $th;
                }

                usleep($this->retryDelay * 1000); // Convert milliseconds to microseconds
            }
        }

        return false;
    }

    private function reconnect(): void
    {
        $newRedis = new Client($this->name, $this->seeds);

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
