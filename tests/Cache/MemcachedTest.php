<?php

namespace Utopia\Tests;

use Memcached as Memcached;
use Utopia\Cache\Adapter\Memcached as MemcachedAdapter;
use Utopia\Cache\Cache;

class MemcachedTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        $mc = new Memcached();
        $mc->addServer('memcached', 11211);

        self::$cache = new Cache(new MemcachedAdapter($mc));
    }

    public function testGetSize(): void
    {
        self::$cache->save('test:file33', 'file33');
        $this->assertEquals(1, self::$cache->getSize());
    }
}
