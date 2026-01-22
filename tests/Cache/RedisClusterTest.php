<?php

namespace Utopia\Tests;

use RedisCluster;
use Utopia\Cache\Adapter\RedisCluster as RedisAdapter;
use Utopia\Cache\Cache;

const SEEDS = [
    'redis-cluster:7000',
    'redis-cluster:7001',
    'redis-cluster:7002',
];

const TIMEOUT = 1.5;

class RedisClusterTest extends Base
{
    protected static RedisCluster $redis;

    public static function setUpBeforeClass(): void
    {
        self::$redis = new RedisCluster(null, SEEDS, TIMEOUT, TIMEOUT);
        self::$cache = new Cache(new RedisAdapter(self::$redis, SEEDS));
    }

    public function testGetSize(): void
    {
        for ($i = 0; $i < 20; $i++) {
            self::$cache->save("test:file$i", "file$i", "test:file$i");
        }

        $this->assertEquals(20, self::$cache->getSize());
    }

    /**
     * @depends testGetSize
     */
    public function testCacheReconnect(): void
    {
        self::$redis = new RedisCluster(null, SEEDS, TIMEOUT, TIMEOUT);
        self::$cache = new Cache((new RedisAdapter(self::$redis, SEEDS))->setMaxRetries(3));

        self::$cache->save('test:reconnect', 'reconnect', 'test:reconnect');

        // Must recreate container because grokzen/redis-cluster doesn't persist cluster state across stop/start
        $rmCmd = 'docker rm -f redis-cluster';
        exec($rmCmd.' 2>&1', $output, $exitCode);
        $this->assertEquals(0, $exitCode, "Docker rm failed: $rmCmd\nOutput: ".implode("\n", $output));
        sleep(1);

        try {
            self::$cache->load('test:reconnect', 5);
            $this->fail('Redis connection should have failed');
        } catch (\RedisClusterException $e) {
        }

        $output = [];
        $runCmd = 'docker run -d --name redis-cluster --hostname redis-cluster --network cache_database -e IP=redis-cluster grokzen/redis-cluster:7.0.10';
        exec($runCmd.' 2>&1', $output, $exitCode);
        $this->assertEquals(0, $exitCode, "Docker run failed: $runCmd\nOutput: ".implode("\n", $output));

        // Wait for cluster to be ready with retry logic (max 60 seconds) to reduce flaky tests
        $maxWait = 60;
        $waited = 0;
        $saveResult = false;
        while ($waited < $maxWait && $saveResult === false) {
            try {
                $saveResult = self::$cache->save('test:reconnect', 'reconnect', 'test:reconnect');
                if ($saveResult !== false) {
                    break;
                }
            } catch (\RedisClusterException $e) {
                // Exception thrown, will retry
            }
            sleep(5);
            $waited += 5;
        }
        $this->assertEquals('reconnect', self::$cache->save('test:reconnect', 'reconnect', 'test:reconnect'));
        $this->assertEquals('reconnect', self::$cache->load('test:reconnect', 5));
    }
}
