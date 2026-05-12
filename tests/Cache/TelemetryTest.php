<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;
use Utopia\Cache\TelemetryAware;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None;

class TelemetryTest extends TestCase
{
    public function test_cache_propagates_telemetry_to_adapter(): void
    {
        $adapter = new class extends Memory implements TelemetryAware
        {
            public ?Telemetry $telemetry = null;

            public int $calls = 0;

            public function setTelemetry(Telemetry $telemetry): void
            {
                $this->telemetry = $telemetry;
                $this->calls++;
            }
        };

        $cache = new Cache($adapter);
        $telemetry = new None;

        $cache->setTelemetry($telemetry);

        $this->assertSame($telemetry, $adapter->telemetry);
        $this->assertEquals(2, $adapter->calls);
    }
}
