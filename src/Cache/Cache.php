<?php

namespace Utopia\Cache;

use Utopia\Cache\Feature\Leasable;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;
use Utopia\Telemetry\Counter;
use Utopia\Telemetry\Histogram;

class Cache
{
    private Adapter $adapter;

    /**
     * @var bool If cache keys are case-sensitive
     */
    public bool $caseSensitive = false;

    protected Telemetry $telemetry;

    /**
     * @var Histogram|null
     */
    protected ?Histogram $operationDuration = null;

    /**
     * @var Counter|null
     */
    protected ?Counter $loadResults = null;

    /**
     * Set telemetry adapter. Instruments are created lazily on first use to
     * avoid emitting empty data point streams on every export interval.
     *
     * @param  Telemetry  $telemetry
     */
    public function setTelemetry(Telemetry $telemetry): void
    {
        $this->telemetry = $telemetry;
        $this->operationDuration = null;
        $this->loadResults = null;

        if ($this->adapter instanceof Feature\Telemetry) {
            $this->adapter->setTelemetry($telemetry);
        }
    }

    private function getOperationDuration(): Histogram
    {
        return $this->operationDuration ??= $this->telemetry->createHistogram(
            'cache.operation.duration',
            's',
            null,
            ['ExplicitBucketBoundaries' => [0.001, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1]]
        );
    }

    private function getLoadResults(): Counter
    {
        return $this->loadResults ??= $this->telemetry->createCounter(
            'cache.load.total',
            null,
            'Cache load operations broken down by hit/miss result.',
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
        $this->telemetry = new NoTelemetry();
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
        $adapterName = $this->adapter->getName($key);
        $this->getOperationDuration()->record($duration, [
            'operation' => 'load',
            'adapter' => $adapterName,
        ]);
        $this->getLoadResults()->add(1, [
            'adapter' => $adapterName,
            'result' => $result === false ? 'miss' : 'hit',
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
            $this->getOperationDuration()->record($duration, [
                'operation' => 'save',
                'adapter' => $this->adapter->getName($key),
            ]);
        }
    }

    /**
     * Current generation token for $key (advances on each purge). Returns '0'
     * when the underlying adapter does not support leasing.
     *
     * @param  string  $key
     * @return string
     */
    public function getGeneration(string $key): string
    {
        if (! $this->adapter instanceof Leasable) {
            return '0';
        }

        $key = $this->caseSensitive ? $key : \strtolower($key);

        return $this->adapter->getGeneration($key);
    }

    /**
     * Save $data only if the key's generation still equals $generation (i.e. no
     * purge happened since the caller captured it via getGeneration). Closes the
     * cache-aside read-after-write race. Falls back to an unconditional save when
     * the adapter does not support leasing.
     *
     * @param  string  $key
     * @param  string|array<int|string, mixed>  $data
     * @param  string  $hash
     * @param  string  $generation
     * @return bool|string|array<int|string, mixed>
     */
    public function saveWithLease(string $key, mixed $data, string $hash, string $generation): bool|string|array
    {
        $key = $this->caseSensitive ? $key : \strtolower($key);
        $hash = $this->caseSensitive ? $hash : \strtolower($hash);
        $start = microtime(true);

        try {
            if (! $this->adapter instanceof Leasable) {
                return $this->adapter->save($key, $data, $hash);
            }

            return $this->adapter->saveWithLease($key, $data, $hash, $generation);
        } finally {
            $duration = microtime(true) - $start;
            $this->getOperationDuration()->record($duration, [
                'operation' => 'saveWithLease',
                'adapter' => $this->adapter->getName($key),
            ]);
        }
    }

    /**
     * Refresh a cache entry timestamp without replacing its data.
     *
     * @param  string  $key
     * @param  string  $hash optional
     * @return bool
     */
    public function touch(string $key, string $hash = ''): bool
    {
        $key = $this->caseSensitive ? $key : strtolower($key);
        $hash = $this->caseSensitive ? $hash : strtolower($hash);

        $start = microtime(true);
        $result = $this->adapter->touch($key, $hash);
        $duration = microtime(true) - $start;
        $this->getOperationDuration()->record($duration, [
            'operation' => 'touch',
            'adapter' => $this->adapter->getName($key),
        ]);

        return $result;
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
        $this->getOperationDuration()->record($duration, [
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
        $this->getOperationDuration()->record($duration, [
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
        $this->getOperationDuration()->record($duration, [
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
        $this->getOperationDuration()->record($duration, [
            'operation' => 'size',
            'adapter' => $this->adapter->getName(),
        ]);

        return $result;
    }
}
