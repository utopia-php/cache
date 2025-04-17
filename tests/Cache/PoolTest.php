<?php

namespace Utopia\Tests;

use Utopia\Cache\Adapter\Filesystem;
use Utopia\Cache\Adapter\Pool;
use Utopia\Cache\Cache;

class PoolTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        $path = __DIR__.'/tests/data';
        if (! file_exists($path)) {
            mkdir($path, 0777, true);
        }

        $pool = new \Utopia\Pools\Pool('test', 10, function () use ($path) {
            return new Filesystem($path);
        });

        self::$cache = new Cache(new Pool($pool));
    }

    public function testGetSize(): void
    {
        self::$cache->save('test', 'test');
        $this->assertEquals(4, self::$cache->getSize());
    }

    public static function tearDownAfterClass(): void
    {
        self::$cache::setCaseSensitivity(false);
        // @phpstan-ignore-next-line
        self::$cache = null;
    }
}
