<?php

namespace Utopia\Tests;

use RedisCluster;
use Utopia\Cache\Adapter\RedisCluster as RedisAdapter;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;

const SEEDS = [
    'cache-redis-cluster-0:6379',
    'cache-redis-cluster-1:6379',
    'cache-redis-cluster-2:6379',
];

class RedisClusterTest extends Base
{
    protected static RedisCluster $redis;

    public static function setUpBeforeClass(): void
    {
        self::$redis = new RedisCluster(null, SEEDS);
        self::$cache = new Cache(new RedisAdapter(self::$redis, SEEDS));
    }

    public function test_get_size(): void
    {
        for ($i = 0; $i < 20; $i++) {
            self::$cache->save("test:file$i", "file$i", "test:file$i");
        }

        $this->assertEquals(20, self::$cache->getSize());
    }

    /**
     * @depends test_get_size
     */
    public function test_cache_reconnect(): void
    {
        self::$redis = new RedisCluster(null, SEEDS);
        self::$cache = new Cache((new RedisAdapter(self::$redis, SEEDS))->setMaxRetries(3));

        self::$cache->save('test:reconnect', 'reconnect', 'test:reconnect');

        $stdout = '';
        $stderr = '';
        Console::execute('docker ps -a --filter "name=cache-redis-cluster" --format "{{.Names}}" | xargs -r docker stop', '', $stdout, $stderr);
        sleep(1);

        try {
            self::$cache->load('test:reconnect', 5);
            $this->fail('Redis connection should have failed');
        } catch (\RedisClusterException $e) {
        }

        Console::execute('docker ps -a --filter "name=cache-redis-cluster" --format "{{.Names}}" | xargs -r docker start', '', $stdout, $stderr);
        sleep(3);

        $this->assertEquals('reconnect', self::$cache->save('test:reconnect', 'reconnect', 'test:reconnect'));
        $this->assertEquals('reconnect', self::$cache->load('test:reconnect', 5));
    }
}
