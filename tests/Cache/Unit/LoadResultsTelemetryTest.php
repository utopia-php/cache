<?php

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;
use Utopia\Cache\Token;
use Utopia\Telemetry\Adapter\Test as TestTelemetry;

class LoadResultsTelemetryTest extends TestCase
{
    public function testLoadEmitsHitAndMissCounts(): void
    {
        $cache = new Cache(new Memory());
        $telemetry = new TestTelemetry();
        $cache->setTelemetry($telemetry);

        $cache->load('missing', 60);
        $cache->save('present', 'value');
        $cache->load('present', 60);
        $cache->load('present', 60);

        $this->assertArrayHasKey('cache.load.total', $telemetry->counters);
        /** @phpstan-ignore-next-line property.notFound */
        $this->assertCount(3, $telemetry->counters['cache.load.total']->values);
    }

    public function testHitMissAttributesAreRecorded(): void
    {
        $captured = [];

        $cache = new Cache(new Memory());
        $telemetry = new class($captured) extends TestTelemetry
        {
            /**
             * @param  array<int, array<string, mixed>>  $captured
             */
            public function __construct(public array &$captured)
            {
            }

            public function createCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): \Utopia\Telemetry\Counter
            {
                if ($name !== 'cache.load.total') {
                    return parent::createCounter($name, $unit, $description, $advisory);
                }
                $captured = &$this->captured;

                return $this->counters[$name] = new class($captured) extends \Utopia\Telemetry\Counter
                {
                    /**
                     * @param  array<int, array<string, mixed>>  $captured
                     */
                    public function __construct(public array &$captured)
                    {
                    }

                    public function add(float|int $amount, iterable $attributes = []): void
                    {
                        $this->captured[] = \is_array($attributes) ? $attributes : \iterator_to_array($attributes);
                    }
                };
            }
        };
        $cache->setTelemetry($telemetry);

        $cache->load('absent', 60);
        $cache->save('here', 'value');
        $cache->load('here', 60);

        $results = \array_column($captured, 'result');
        $this->assertSame(['miss', 'hit'], $results);
    }

    public function testNullReturnIsTreatedAsHit(): void
    {
        $captured = [];

        $adapter = new class implements Adapter
        {
            public function load(string $key, int $ttl, string $hash = ''): mixed
            {
                return null;
            }

            public function save(string $key, array|string $data, string $hash = '', ?Token $token = null): bool|string|array
            {
                return false;
            }

            public function touch(string $key, string $hash = ''): bool
            {
                return false;
            }

            public function list(string $key): array
            {
                return [];
            }

            public function purge(string $key, string $hash = ''): Token|false
            {
                return false;
            }

            public function flush(): bool
            {
                return true;
            }

            public function ping(): bool
            {
                return true;
            }

            public function getSize(): int
            {
                return 0;
            }

            public function getName(?string $key = null): string
            {
                return 'null-loader';
            }
        };

        $cache = new Cache($adapter);
        $telemetry = new class($captured) extends TestTelemetry
        {
            /**
             * @param  array<int, array<string, mixed>>  $captured
             */
            public function __construct(public array &$captured)
            {
            }

            public function createCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): \Utopia\Telemetry\Counter
            {
                if ($name !== 'cache.load.total') {
                    return parent::createCounter($name, $unit, $description, $advisory);
                }
                $captured = &$this->captured;

                return $this->counters[$name] = new class($captured) extends \Utopia\Telemetry\Counter
                {
                    /**
                     * @param  array<int, array<string, mixed>>  $captured
                     */
                    public function __construct(public array &$captured)
                    {
                    }

                    public function add(float|int $amount, iterable $attributes = []): void
                    {
                        $this->captured[] = \is_array($attributes) ? $attributes : \iterator_to_array($attributes);
                    }
                };
            }
        };
        $cache->setTelemetry($telemetry);

        $cache->load('any', 60);

        $this->assertSame('hit', $captured[0]['result']);
    }
}
