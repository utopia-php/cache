<?php

namespace Utopia\Tests;

use Redis as Redis;
use Utopia\Cache\Adapter\Redis as RedisAdapter;
use Utopia\Cache\Cache;

class RedisTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        $redis = new Redis();
        $redis->connect('redis', 6379);
        self::$cache = new Cache(new RedisAdapter($redis));
    }

    public static function tearDownAfterClass(): void
    {
        self::$cache::setCaseSensitivity(false);
        // @phpstan-ignore-next-line
        self::$cache = null;
    }

    /**
     * Wildcard is only supported by Redis at the moment.
     * If global support is introduced, move test to Base.php
     */
    public function testCachePurgeWildcard(): void
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
