<?php

namespace Utopia\Tests;

use Memcached as Memcached;
use Utopia\Cache\Adapter\Hazelcast as HazelcastAdapter;
use Utopia\Cache\Cache;

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
    }

    public function testFlush(): void
    {
        //not implemented as Hazelcast doesn't support flush functionality
        $result = self::$cache->flush();

        $this->assertEquals(false, $result);
    }
}
