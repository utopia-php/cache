<?php

namespace Utopia\Cache;

use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;
use Utopia\Telemetry\Histogram;

class Cache
{
    private Adapter $adapter;

    /**
     * @var bool If cache keys are case-sensitive
     */
    public bool $caseSensitive = false;

    /**
     * @var Histogram|null
     */
    protected ?Histogram $operationDuration = null;

    /**
     * Set telemetry adapter and create histograms for cache operations.
     *
     * @param  Telemetry  $telemetry
     */
    public function setTelemetry(Telemetry $telemetry): void
    {
        $this->operationDuration = $telemetry->createHistogram(
            'cache.operation.duration',
            's',
            null,
            ['ExplicitBucketBoundaries' => [0.001, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1]]
        );
    }

    /**
     * Initialize with a no-op telemetry adapter by default.
     *
     * @param  Adapter  $adapter
     */
    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
        $this->setTelemetry(new NoTelemetry());
    }

    /**
     * Toggle case sensitivity of keys inside cache
     *
     * @param  bool  $value if true, cache keys will be case-sensitive
     * @return bool
     */
    public function setCaseSensitivity(bool $value): bool
    {
        return $this->caseSensitive = $value;
    }

    /**
     * Load cached data. return false in no valid cache.
     *
     * @param  string  $key
     * @param  int  $ttl time in seconds
     * @param  string  $hash optional
     * @return mixed
     */
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        $key = $this->caseSensitive ? $key : \strtolower($key);
        $hash = $this->caseSensitive ? $hash : \strtolower($hash);

        $start = microtime(true);
        $result = $this->adapter->load($key, $ttl, $hash);
        $duration = microtime(true) - $start;
        $this->operationDuration?->record($duration, [
            'operation' => 'load',
            'adapter' => $this->adapter->getName($key),
        ]);

        return $result;
    }

    /**
     * Save data to cache. Returns data on success of false on failure.
     *
     * @param  string  $key
     * @param  string|array<int|string, mixed>  $data
     * @param  string  $hash optional
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, mixed $data, string $hash = ''): bool|string|array
    {
        $key = $this->caseSensitive ? $key : strtolower($key);
        $hash = $this->caseSensitive ? $hash : strtolower($hash);
        $start = microtime(true);

        try {
            return $this->adapter->save($key, $data, $hash);
        } finally {
            $duration = microtime(true) - $start;
            $this->operationDuration?->record($duration, [
                'operation' => 'save',
                'adapter' => $this->adapter->getName($key),
            ]);
        }
    }

    /**
     * Returns a list of keys.
     *
     * @param  string  $key
     * @return string[]
     */
    public function list(string $key): array
    {
        $key = $this->caseSensitive ? $key : \strtolower($key);

        $start = microtime(true);
        $result = $this->adapter->list($key);
        $duration = microtime(true) - $start;
        $this->operationDuration?->record($duration, [
            'operation' => 'list',
            'adapter' => $this->adapter->getName($key),
        ]);

        return $result;
    }

    /**
     * Removes data from cache. Returns true on success of false on failure.
     *
     * @param  string  $key
     * @param  string  $hash optional
     * @return bool
     */
    public function purge(string $key, string $hash = ''): bool
    {
        $key = $this->caseSensitive ? $key : \strtolower($key);
        $hash = $this->caseSensitive ? $hash : \strtolower($hash);

        $start = microtime(true);
        $result = $this->adapter->purge($key, $hash);
        $duration = microtime(true) - $start;
        $this->operationDuration?->record($duration, [
            'operation' => 'purge',
            'adapter' => $this->adapter->getName($key),
        ]);

        return $result;
    }

    /**
     * Removes all data from cache. Returns true on success of false on failure.
     *
     * @return bool
     */
    public function flush(): bool
    {
        $start = microtime(true);
        $result = $this->adapter->flush();
        $duration = microtime(true) - $start;
        $this->operationDuration?->record($duration, [
            'operation' => 'flush',
            'adapter' => $this->adapter->getName(),
        ]);

        return $result;
    }

    /**
     * Check Cache Connecitivity
     *
     * @return bool
     */
    public function ping(): bool
    {
        return $this->adapter->ping();
    }

    /**
     * Get db size.
     *
     * @return int
     */
    public function getSize(): int
    {
        $start = microtime(true);
        $result = $this->adapter->getSize();
        $duration = microtime(true) - $start;
        $this->operationDuration?->record($duration, [
            'operation' => 'size',
            'adapter' => $this->adapter->getName(),
        ]);

        return $result;
    }
}
