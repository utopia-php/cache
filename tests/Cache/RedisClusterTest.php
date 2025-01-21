<?php

namespace Utopia\Tests;

use RedisCluster as RedisCluster;
use Utopia\Cache\Adapter\RedisCluster as RedisAdapter;
use Utopia\Cache\Cache;

class RedisClusterTest extends Base
{
    protected static RedisCluster $redis;

    public static function setUpBeforeClass(): void
    {
        self::$redis = new RedisCluster(null, ['cache-redis-cluster-0:6379', 'cache-redis-cluster-1:6379', 'cache-redis-cluster-2:6379']);
        self::$cache = new Cache(new RedisAdapter(self::$redis));
    }

    public static function tearDownAfterClass(): void
    {
        self::$cache::setCaseSensitivity(false);
        // @phpstan-ignore-next-line
        self::$cache = null;
    }

    public function testGetSize(): void
    {
        for ($i = 0; $i < 20; $i++) {
            self::$cache->save("test:file$i", "file$i", "test:file$i");
        }

        $this->assertEquals(20, self::$cache->getSize());
    }
}
