<?php

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;
use Utopia\Cache\Feature;
use Utopia\CircuitBreaker\CircuitBreaker as UtopiaCircuitBreaker;
use Utopia\Telemetry\Adapter as Telemetry;

class CircuitBreaker implements Adapter, Feature\Telemetry
{
    public function __construct(
        private readonly Adapter $adapter,
        private readonly UtopiaCircuitBreaker $breaker,
    ) {
    }

    /**
     * Forward method calls to the internal adapter through the circuit breaker.
     *
     * Required because __call() can't be used to implement abstract methods.
     *
     * @param  string  $method
     * @param  array<mixed>  $args
     * @return mixed
     */
    public function delegate(string $method, array $args, mixed $fallback): mixed
    {
        return $this->breaker->call(
            open: fn (): mixed => $fallback,
            close: fn (): mixed => $this->adapter->{$method}(...$args),
        );
    }

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        return $this->delegate(__FUNCTION__, \func_get_args(), false);
    }

    public function save(string $key, array|string $data, string $hash = ''): bool|string|array
    {
        /** @var bool|string|array<int|string, mixed> $result */
        $result = $this->delegate(__FUNCTION__, \func_get_args(), false);

        return $result;
    }

    public function touch(string $key, string $hash = ''): bool
    {
        /** @var bool $result */
        $result = $this->delegate(__FUNCTION__, \func_get_args(), false);

        return $result;
    }

    public function list(string $key): array
    {
        /** @var string[] $result */
        $result = $this->delegate(__FUNCTION__, \func_get_args(), []);

        return $result;
    }

    public function purge(string $key, string $hash = ''): bool
    {
        /** @var bool $result */
        $result = $this->delegate(__FUNCTION__, \func_get_args(), false);

        return $result;
    }

    public function flush(): bool
    {
        /** @var bool $result */
        $result = $this->delegate(__FUNCTION__, \func_get_args(), false);

        return $result;
    }

    public function ping(): bool
    {
        /** @var bool $result */
        $result = $this->delegate(__FUNCTION__, \func_get_args(), false);

        return $result;
    }

    public function getSize(): int
    {
        /** @var int $result */
        $result = $this->delegate(__FUNCTION__, \func_get_args(), 0);

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

        if ($this->adapter instanceof Feature\Telemetry) {
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
