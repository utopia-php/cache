<?php

namespace Utopia\Tests;

use Memcached as Memcached;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Hazelcast as HazelcastAdapter;
use Utopia\Tests\Base;

class HazelcastTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        $memcached = new Memcached();
        $memcached->addServer('hazelcast', 5701);
        self::$cache = new Cache(new HazelcastAdapter($memcached));
    }

    public static function tearDownAfterClass(): void
    {
        self::$cache::setCaseSensitivity(false);
        self::$cache = null;
    }

    public function testFlush()
    {
        
        //not implemented as Hazelcast doesn't support flush functionality
        $result = self::$cache->flush();

        $this->assertEquals(false, $result);
    }
}
