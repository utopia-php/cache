<?php

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;
use Utopia\Cache\TelemetryAware;
use Utopia\CircuitBreaker\CircuitBreaker as UtopiaCircuitBreaker;
use Utopia\Telemetry\Adapter as Telemetry;

class CircuitBreaker implements Adapter, TelemetryAware
{
    public function __construct(
        private readonly Adapter $adapter,
        private readonly UtopiaCircuitBreaker $breaker,
    ) {
    }

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        return $this->breaker->call(
            open: fn (): bool => false,
            close: fn (): mixed => $this->adapter->load($key, $ttl, $hash),
        );
    }

    public function save(string $key, array|string $data, string $hash = ''): bool|string|array
    {
        /** @var bool|string|array<int|string, mixed> $result */
        $result = $this->breaker->call(
            open: fn (): bool => false,
            close: fn (): bool|string|array => $this->adapter->save($key, $data, $hash),
        );

        return $result;
    }

    public function touch(string $key, string $hash = ''): bool
    {
        /** @var bool $result */
        $result = $this->breaker->call(
            open: fn (): bool => false,
            close: fn (): bool => $this->adapter->touch($key, $hash),
        );

        return $result;
    }

    public function list(string $key): array
    {
        /** @var string[] $result */
        $result = $this->breaker->call(
            open: fn (): array => [],
            close: fn (): array => $this->adapter->list($key),
        );

        return $result;
    }

    public function purge(string $key, string $hash = ''): bool
    {
        /** @var bool $result */
        $result = $this->breaker->call(
            open: fn (): bool => false,
            close: fn (): bool => $this->adapter->purge($key, $hash),
        );

        return $result;
    }

    public function flush(): bool
    {
        /** @var bool $result */
        $result = $this->breaker->call(
            open: fn (): bool => false,
            close: fn (): bool => $this->adapter->flush(),
        );

        return $result;
    }

    public function ping(): bool
    {
        /** @var bool $result */
        $result = $this->breaker->call(
            open: fn (): bool => false,
            close: fn (): bool => $this->adapter->ping(),
        );

        return $result;
    }

    public function getSize(): int
    {
        /** @var int $result */
        $result = $this->breaker->call(
            open: fn (): int => 0,
            close: fn (): int => $this->adapter->getSize(),
        );

        return $result;
    }

    public function getName(?string $key = null): string
    {
        try {
            return $this->adapter->getName($key);
        } catch (\Throwable) {
            return 'circuit-breaker';
        }
    }

    public function setTelemetry(Telemetry $telemetry): void
    {
        $this->breaker->setTelemetry($telemetry);

        if ($this->adapter instanceof TelemetryAware) {
            $this->adapter->setTelemetry($telemetry);
        }
    }

    public function setMaxRetries(int $maxRetries): self
    {
        $this->adapter->setMaxRetries($maxRetries);

        return $this;
    }

    public function setRetryDelay(int $retryDelay): self
    {
        $this->adapter->setRetryDelay($retryDelay);

        return $this;
    }

    public function getMaxRetries(): int
    {
        return $this->adapter->getMaxRetries();
    }

    public function getRetryDelay(): int
    {
        return $this->adapter->getRetryDelay();
    }
}
