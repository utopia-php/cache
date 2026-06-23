<?php

namespace Utopia\Tests\E2E\Redis;

use RedisCluster as RedisCluster;
use Utopia\Cache\Adapter\RedisCluster as RedisAdapter;
use Utopia\Cache\Cache;
use Utopia\Tests\E2E\Base;

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

    public function testRedisClusterMissSaveIsFenced(): void
    {
        self::$cache->purge('redis-cluster:fenced-key', 'redis-cluster:fenced-key');

        $this->assertFalse(self::$cache->load('redis-cluster:fenced-key', 60, 'redis-cluster:fenced-key'));
        $this->assertEquals('fresh data', self::$cache->save('redis-cluster:fenced-key', 'fresh data', 'redis-cluster:fenced-key'));
        $this->assertEquals('fresh data', self::$cache->load('redis-cluster:fenced-key', 60, 'redis-cluster:fenced-key'));

        self::$cache->purge('redis-cluster:fenced-key', 'redis-cluster:fenced-key');
    }

    public function testRedisClusterPurgedMissAllowsFreshSave(): void
    {
        self::$cache->purge('redis-cluster:purged-miss-key', 'redis-cluster:purged-miss-key');

        $this->assertFalse(self::$cache->load('redis-cluster:purged-miss-key', 60, 'redis-cluster:purged-miss-key'));
        self::$cache->purge('redis-cluster:purged-miss-key', 'redis-cluster:purged-miss-key');

        $this->assertEquals('fresh data', self::$cache->save('redis-cluster:purged-miss-key', 'fresh data', 'redis-cluster:purged-miss-key'));
        $this->assertEquals('fresh data', self::$cache->load('redis-cluster:purged-miss-key', 60, 'redis-cluster:purged-miss-key'));

        self::$cache->purge('redis-cluster:purged-miss-key', 'redis-cluster:purged-miss-key');
    }

    public function testRedisClusterFlushBlocksPreFlushMissToken(): void
    {
        self::$cache->purge('redis-cluster:flush-fence-key', 'redis-cluster:flush-fence-key');

        $this->assertFalse(self::$cache->load('redis-cluster:flush-fence-key', 60, 'redis-cluster:flush-fence-key'));

        $redis = new RedisCluster(null, SEEDS, TIMEOUT, TIMEOUT);
        $otherCache = new Cache(new RedisAdapter($redis, SEEDS));
        $this->assertTrue($otherCache->flush());

        $this->assertFalse(self::$cache->save('redis-cluster:flush-fence-key', 'stale data', 'redis-cluster:flush-fence-key'));
        $this->assertFalse(self::$cache->load('redis-cluster:flush-fence-key', 60, 'redis-cluster:flush-fence-key'));
    }

    public function testRedisClusterExpiredEntryCanBeReplacedByFencedSave(): void
    {
        self::$cache->purge('redis-cluster:expired-fence-key', 'redis-cluster:expired-fence-key');
        self::$cache->save('redis-cluster:expired-fence-key', 'expired data', 'redis-cluster:expired-fence-key');

        $this->assertFalse(self::$cache->load('redis-cluster:expired-fence-key', 0, 'redis-cluster:expired-fence-key'));
        $this->assertEquals('fresh data', self::$cache->save('redis-cluster:expired-fence-key', 'fresh data', 'redis-cluster:expired-fence-key'));
        $this->assertEquals('fresh data', self::$cache->load('redis-cluster:expired-fence-key', 60, 'redis-cluster:expired-fence-key'));

        self::$cache->purge('redis-cluster:expired-fence-key', 'redis-cluster:expired-fence-key');
    }

    public function testRedisClusterGlobalPurgeBlocksStaleHashFill(): void
    {
        self::$cache->purge('redis-cluster:global-purge-key');

        $this->assertFalse(self::$cache->load('redis-cluster:global-purge-key', 60, 'field'));
        self::$cache->purge('redis-cluster:global-purge-key');

        $this->assertFalse(self::$cache->save('redis-cluster:global-purge-key', 'stale data', 'field'));
        $this->assertFalse(self::$cache->load('redis-cluster:global-purge-key', 60, 'field'));
    }
}
