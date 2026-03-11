<?php

namespace Utopia\Tests;

use Utopia\Cache\Adapter\Filesystem;
use Utopia\Cache\Adapter\Pool;
use Utopia\Cache\Cache;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool as UtopiaPool;

class PoolTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        $path = __DIR__.'/tests/pool';
        if (! file_exists($path)) {
            mkdir($path, 0777, true);
        }

        $pool = new UtopiaPool(new Stack(), 'test', 10, function () use ($path) {
            return new Filesystem($path);
        });

        self::$cache = new Cache(new Pool($pool));
    }

    public function testGetSize(): void
    {
        self::$cache->save('test', 'test');
        $this->assertEquals(4, self::$cache->getSize());
    }

    public function testPoolRetriesAfterConnectionCreationFailure(): void
    {
        $callCount = 0;
        $path = __DIR__.'/tests/pool-retry';
        if (! file_exists($path)) {
            mkdir($path, 0777, true);
        }

        $pool = new UtopiaPool(new Stack(), 'retry-test', 2, function () use ($path, &$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new \Exception('Transient connection failure');
            }

            return new Filesystem($path);
        });

        $cache = new Cache(new Pool($pool));

        $result = $cache->save('retry-key', 'retry-value');
        $this->assertEquals('retry-value', $result);

        $loaded = $cache->load('retry-key', 3600);
        $this->assertEquals('retry-value', $loaded);
    }

    public function testPoolEmptyErrorIncludesDiagnostics(): void
    {
        $path = __DIR__.'/tests/pool-diag';
        if (! file_exists($path)) {
            mkdir($path, 0777, true);
        }

        $pool = new UtopiaPool(new Stack(), 'diag-test', 1, function () use ($path) {
            return new Filesystem($path);
        });
        $pool->setRetryAttempts(1);
        $pool->setRetrySleep(0);

        // Exhaust the pool by popping the only connection
        $connection = $pool->pop();

        try {
            // This should fail because the pool is exhausted
            $pool->pop();
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            $this->assertStringContainsString('diag-test', $e->getMessage());
            $this->assertStringContainsString('active', $e->getMessage());
            $this->assertStringContainsString('idle', $e->getMessage());
        }

        // Return the connection to clean up
        $pool->push($connection);
    }
}
