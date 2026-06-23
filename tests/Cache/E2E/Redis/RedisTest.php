<?php

namespace Utopia\Tests\E2E\Redis;

use Redis as Redis;
use Utopia\Cache\Adapter\Redis as RedisAdapter;
use Utopia\Cache\Cache;
use Utopia\Tests\E2E\Base;

class RedisTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        $redis = new Redis();
        $redis->connect('redis', 6379);
        self::$cache = new Cache(new RedisAdapter($redis));
    }

    public function testGetSize(): void
    {
        self::$cache->flush();
        self::$cache->save('test:file33', 'file33', 'test:file33');
        self::$cache->save('test:file34', 'file34', 'test:file34');
        self::$cache->save('test:file35', 'file35', 'test:file35');
        $this->assertEquals(3, self::$cache->getSize());
    }

    /**
     * @depends testGetSize
     */
    public function testCacheReconnect(): void
    {
        $redis = new Redis();
        $redis->connect('redis', 6379);
        self::$cache = new Cache((new RedisAdapter($redis))->setMaxRetries(3));

        self::$cache->save('test:reconnect', 'reconnect', 'test:reconnect');

        $stopCmd = 'docker ps -a --filter "name=cache-redis" --format "{{.Names}}" | xargs -r docker stop';
        exec($stopCmd.' 2>&1', $output, $exitCode);
        $this->assertEquals(0, $exitCode, "Docker stop failed: $stopCmd\nOutput: ".implode("\n", $output));
        sleep(1);

        try {
            self::$cache->load('test:reconnect', 5);
            $this->fail('Redis connection should have failed');
        } catch (\RedisException $e) {
        }

        $output = [];
        $startCmd = 'docker ps -a --filter "name=cache-redis" --format "{{.Names}}" | xargs -r docker start';
        exec($startCmd.' 2>&1', $output, $exitCode);
        $this->assertEquals(0, $exitCode, "Docker start failed: $startCmd\nOutput: ".implode("\n", $output));
        sleep(3);

        $this->assertEquals('reconnect', self::$cache->save('test:reconnect', 'reconnect', 'test:reconnect'));
        $this->assertEquals('reconnect', self::$cache->load('test:reconnect', 5));
    }

    /**
     * @depends testCacheReconnect
     */
    public function testCacheReconnectPersistent(): void
    {
        $redis = new Redis();
        $redis->pconnect('redis', 6379);
        self::$cache = new Cache((new RedisAdapter($redis))->setMaxRetries(3));

        self::$cache->save('test:reconnect_persistent', 'reconnect_persistent', 'test:reconnect_persistent');

        $stopCmd = 'docker ps -a --filter "name=cache-redis" --format "{{.Names}}" | xargs -r docker stop';
        exec($stopCmd.' 2>&1', $output, $exitCode);
        $this->assertEquals(0, $exitCode, "Docker stop failed: $stopCmd\nOutput: ".implode("\n", $output));
        sleep(1);

        try {
            self::$cache->load('test:reconnect_persistent', 5);
            $this->fail('Redis connection should have failed');
        } catch (\RedisException $e) {
        }

        $output = [];
        $startCmd = 'docker ps -a --filter "name=cache-redis" --format "{{.Names}}" | xargs -r docker start';
        exec($startCmd.' 2>&1', $output, $exitCode);
        $this->assertEquals(0, $exitCode, "Docker start failed: $startCmd\nOutput: ".implode("\n", $output));
        sleep(3);

        $this->assertEquals('reconnect_persistent', self::$cache->save('test:reconnect_persistent', 'reconnect_persistent', 'test:reconnect_persistent'));
        $this->assertEquals('reconnect_persistent', self::$cache->load('test:reconnect_persistent', 5));
    }

    public function testRedisMissSaveIsFenced(): void
    {
        self::$cache->purge('redis:fenced-key', 'redis:fenced-key');

        $this->assertFalse(self::$cache->load('redis:fenced-key', 60, 'redis:fenced-key'));
        $this->assertEquals('fresh data', self::$cache->save('redis:fenced-key', 'fresh data', 'redis:fenced-key'));
        $this->assertEquals('fresh data', self::$cache->load('redis:fenced-key', 60, 'redis:fenced-key'));

        self::$cache->purge('redis:fenced-key', 'redis:fenced-key');
    }

    public function testRedisPurgedMissAllowsFreshSave(): void
    {
        self::$cache->purge('redis:purged-miss-key', 'redis:purged-miss-key');

        $this->assertFalse(self::$cache->load('redis:purged-miss-key', 60, 'redis:purged-miss-key'));
        self::$cache->purge('redis:purged-miss-key', 'redis:purged-miss-key');

        $this->assertEquals('fresh data', self::$cache->save('redis:purged-miss-key', 'fresh data', 'redis:purged-miss-key'));
        $this->assertEquals('fresh data', self::$cache->load('redis:purged-miss-key', 60, 'redis:purged-miss-key'));

        self::$cache->purge('redis:purged-miss-key', 'redis:purged-miss-key');
    }

    public function testRedisFlushBlocksPreFlushMissToken(): void
    {
        self::$cache->purge('redis:flush-fence-key', 'redis:flush-fence-key');

        $this->assertFalse(self::$cache->load('redis:flush-fence-key', 60, 'redis:flush-fence-key'));

        $redis = new Redis();
        $redis->connect('redis', 6379);
        $otherCache = new Cache(new RedisAdapter($redis));
        $this->assertTrue($otherCache->flush());

        $this->assertFalse(self::$cache->save('redis:flush-fence-key', 'stale data', 'redis:flush-fence-key'));
        $this->assertFalse(self::$cache->load('redis:flush-fence-key', 60, 'redis:flush-fence-key'));
    }

    public function testRedisExpiredEntryCanBeReplacedByFencedSave(): void
    {
        self::$cache->purge('redis:expired-fence-key', 'redis:expired-fence-key');
        self::$cache->save('redis:expired-fence-key', 'expired data', 'redis:expired-fence-key');

        $this->assertFalse(self::$cache->load('redis:expired-fence-key', 0, 'redis:expired-fence-key'));
        $this->assertEquals('fresh data', self::$cache->save('redis:expired-fence-key', 'fresh data', 'redis:expired-fence-key'));
        $this->assertEquals('fresh data', self::$cache->load('redis:expired-fence-key', 60, 'redis:expired-fence-key'));

        self::$cache->purge('redis:expired-fence-key', 'redis:expired-fence-key');
    }

    public function testRedisGlobalPurgeBlocksStaleHashFill(): void
    {
        self::$cache->purge('redis:global-purge-key');

        $this->assertFalse(self::$cache->load('redis:global-purge-key', 60, 'field'));
        self::$cache->purge('redis:global-purge-key');

        $this->assertFalse(self::$cache->save('redis:global-purge-key', 'stale data', 'field'));
        $this->assertFalse(self::$cache->load('redis:global-purge-key', 60, 'field'));
    }
}
