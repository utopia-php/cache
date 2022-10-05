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

    // Wildcard is supported with search of all prefix keys in HAzelcast
    public function testCachePurgeWildcard()
    {
        $data1 = self::$cache->save('test:file1', 'file1');
        $data2 = self::$cache->save('test:file2', 'file2');

        $this->assertEquals('file1', $data1);
        $this->assertEquals('file2', $data2);

        $result = self::$cache->purge('test:*');
        $this->assertEquals(true, $result);

        $data = self::$cache->load('test:file1', 60 * 60 * 24 * 30 * 3 /* 3 months */);
        $this->assertEquals(false, $data);
        $data = self::$cache->load('test:file2', 60 * 60 * 24 * 30 * 3 /* 3 months */);
        $this->assertEquals(false, $data);

        /**
         * Test for failure
         * Try to glob keys that do not exist
         */
        $result = self::$cache->purge('test:*');
        $this->assertEquals(false, $result);
    }
}
