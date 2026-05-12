<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;
use Utopia\Cache\Feature;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None;

class TelemetryTest extends TestCase
{
    public function testCachePropagatesTelemetryToAdapter(): void
    {
        $adapter = new class extends Memory implements Feature\Telemetry
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
