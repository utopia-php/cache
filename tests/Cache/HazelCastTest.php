<?php

namespace Utopia\Tests;

use Memcached as Memcached;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Hazelcast as HazelCastAdapter;
use Utopia\Tests\Base;

class HazelCastTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        $memcached = new Memcached();
        $memcached->addServer('127.0.0.1', 5701);
        self::$cache = new Cache(new HazelCastAdapter($memcached));
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
