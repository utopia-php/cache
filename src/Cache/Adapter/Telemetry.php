<?php

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;
use Utopia\Telemetry\Adapter as TelemetryAdapter;
use Utopia\Telemetry\Histogram;

class Telemetry implements Adapter
{
    protected Adapter $adapter;

    protected Histogram $metrics;

    public function __construct(Adapter $adapter, TelemetryAdapter $telemetry)
    {
        $this->adapter = $adapter;
        // https://opentelemetry.io/docs/specs/semconv/database/database-metrics/#metric-dbclientoperationduration
        $this->metrics = $telemetry->createHistogram(
            'db.client.operation.duration',
            's',
            null,
            ['ExplicitBucketBoundaries' => [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10]]
        );
    }

    private function recordMetrics(string $name, callable $operation): mixed
    {
        $start = microtime(true);
        $error = null;
        try {
            return call_user_func($operation);
        } catch (\Throwable $e) {
            $error = $e::class;
            throw $e;
        } finally {
            $attributes = [
                'db.operation.name' => $name,
                'error.type' => $error,
            ];
            $this->metrics->record(microtime(true) - $start, $attributes);
        }
    }

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        return $this->recordMetrics('load', fn () => $this->adapter->load($key, $ttl, $hash));
    }

    public function save(string $key, array|string $data, string $hash = ''): bool|string|array
    {
        return $this->recordMetrics('save', fn () => $this->adapter->save($key, $data, $hash));
    }

    public function list(string $key): array
    {
        return $this->recordMetrics('list', fn () => $this->adapter->list($key));
    }

    public function purge(string $key, string $hash = ''): bool
    {
        return $this->recordMetrics('purge', fn () => $this->adapter->purge($key, $hash));
    }

    public function flush(): bool
    {
        return $this->recordMetrics('flush', fn () => $this->adapter->flush());
    }

    public function ping(): bool
    {
        return $this->recordMetrics('ping', fn () => $this->adapter->ping());
    }

    public function getSize(): int
    {
        return $this->recordMetrics('getSize', fn () => $this->adapter->getSize());
    }
}
