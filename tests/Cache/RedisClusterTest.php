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
        self::$redis = new RedisCluster(null, ['redis-cluster:7000', 'redis-cluster:7001', 'redis-cluster:7002']);
        self::$cache = new Cache(new RedisAdapter(self::$redis));
    }

    public function testGetSize(): void
    {
        for ($i = 0; $i < 20; $i++) {
            self::$cache->save("test:file$i", "file$i", "test:file$i");
        }

        $this->assertEquals(20, self::$cache->getSize());
    }
}
