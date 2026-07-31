<?php

namespace Utopia\Tests\E2E;

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
        }, timeout: 0.0);

        self::$cache = new Cache(new Pool($pool));
    }

    public function testGetSize(): void
    {
        self::$cache->save('test', 'test');
        $this->assertEquals(4, self::$cache->getSize());
    }

    public function testLeaseFallbackForNonLeasableAdapter(): void
    {
        // The pool holds Filesystem adapters, which are not Leasable. The Pool
        // adapter must degrade gracefully (no "Call to undefined method" fatal):
        // getGeneration() returns '0' and saveWithLease() falls back to save().
        $this->assertSame('0', self::$cache->getGeneration('lease:fallback'));
        $this->assertNotFalse(self::$cache->saveWithLease('lease:fallback', 'value', 'lease:fallback', '0'));
        $this->assertSame('value', self::$cache->load('lease:fallback', 60, 'lease:fallback'));
    }
}
