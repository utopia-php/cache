<?php

namespace Utopia\Cache;

use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;
use Utopia\Telemetry\Histogram;

class Cache
{
    /**
     * @var Adapter
     */
    private $adapter;

    /**
     * @var bool If cache keys are case sensitive
     */
    public static bool $caseSensitive = false;

    /**
     * @var Histogram|null
     */
    protected ?Histogram $loadDuration = null;

    /**
     * @var Histogram|null
     */
    protected ?Histogram $saveDuration = null;

    /**
     * @var Histogram|null
     */
    protected ?Histogram $purgeDuration = null;

    /**
     * @var Histogram|null
     */
    protected ?Histogram $flushDuration = null;

    /**
     * @var Histogram|null
     */
    protected ?Histogram $sizeDuration = null;

    /**
     * Set telemetry adapter and create histograms for cache operations.
     *
     * @param  Telemetry  $telemetry
     */
    public function setTelemetry(Telemetry $telemetry): void
    {
        $this->loadDuration = $telemetry->createHistogram(
            'cache.load.duration',
            's',
            null,
            ['ExplicitBucketBoundaries' => [0.001, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1]]
        );

        $this->saveDuration = $telemetry->createHistogram(
            'cache.save.duration',
            's',
            null,
            ['ExplicitBucketBoundaries' => [0.001, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1]]
        );

        $this->purgeDuration = $telemetry->createHistogram(
            'cache.purge.duration',
            's',
            null,
            ['ExplicitBucketBoundaries' => [0.001, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1]]
        );

        $this->flushDuration = $telemetry->createHistogram(
            'cache.flush.duration',
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
     * @param  bool  $value if true, cache keys will be case sensitive
     * @return bool
     */
    public static function setCaseSensitivity(bool $value): bool
    {
        return self::$caseSensitive = $value;
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
        $key = self::$caseSensitive ? $key : \strtolower($key);
        $hash = self::$caseSensitive ? $hash : \strtolower($hash);

        $start = microtime(true);
        $result = $this->adapter->load($key, $ttl, $hash);
        $duration = microtime(true) - $start;
        $this->loadDuration?->record($duration, [
            'operation' => 'load',
            'adapter' => strtolower(get_class($this->adapter)),
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
        $key = self::$caseSensitive ? $key : \strtolower($key);
        $hash = self::$caseSensitive ? $hash : \strtolower($hash);

        $start = microtime(true);
        $result = $this->adapter->save($key, $data, $hash);
        $duration = microtime(true) - $start;
        $this->saveDuration?->record($duration, [
            'operation' => 'save',
            'adapter' => strtolower(get_class($this->adapter)),
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
        $key = self::$caseSensitive ? $key : \strtolower($key);

        return $this->adapter->list($key);
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
        $key = self::$caseSensitive ? $key : \strtolower($key);
        $hash = self::$caseSensitive ? $hash : \strtolower($hash);

        $start = microtime(true);
        $result = $this->adapter->purge($key, $hash);
        $duration = microtime(true) - $start;
        $this->purgeDuration?->record($duration, [
            'operation' => 'purge',
            'adapter' => strtolower(get_class($this->adapter)),
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
        $this->flushDuration?->record($duration, [
            'operation' => 'flush',
            'adapter' => strtolower(get_class($this->adapter)),
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
        $this->sizeDuration?->record($duration, [
            'operation' => 'size',
            'adapter' => strtolower(get_class($this->adapter)),
        ]);

        return $result;
    }
}
