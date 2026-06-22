<?php

namespace Utopia\Tests\E2E;

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

    public function testGetSize(): void
    {
        $this->assertEquals(0, self::$cache->getSize());
    }

    public function testCacheReconnect(): void
    {
        $memcached = new Memcached();
        $memcached->addServer('hazelcast', 5701);
        self::$cache = new Cache((new HazelcastAdapter($memcached))->setMaxRetries(3));
        self::$cache->save('test:reconnect', 'reconnect', 'test:reconnect');

        $stopCmd = 'docker ps -a --filter "name=hazelcast" --format "{{.Names}}" | xargs -r docker stop';
        exec($stopCmd.' 2>&1', $output, $exitCode);
        $this->assertEquals(0, $exitCode, "Docker stop failed: $stopCmd\nOutput: ".implode("\n", $output));
        sleep(3);

        try {
            self::$cache->load('test:reconnect', 5);
            $this->fail('Hazelcast connection should have failed');
        } catch (\MemcachedException $e) {
        }

        $output = [];
        $startCmd = 'docker ps -a --filter "name=hazelcast" --format "{{.Names}}" | xargs -r docker start';
        exec($startCmd.' 2>&1', $output, $exitCode);
        $this->assertEquals(0, $exitCode, "Docker start failed: $startCmd\nOutput: ".implode("\n", $output));
        sleep(6);

        $this->assertEquals('reconnect', self::$cache->save('test:reconnect', 'reconnect', 'test:reconnect'));
        $this->assertEquals('reconnect', self::$cache->load('test:reconnect', 5));
    }

    public function testFlush(): void
    {
        //not implemented as Hazelcast doesn't support flush functionality
        $result = self::$cache->flush();

        $this->assertEquals(false, $result);
    }

    public function testCacheMissSaveIsFenced(): void
    {
        $missingKey = 'hazelcast-missing-'.\bin2hex(\random_bytes(8));

        $this->assertFalse(self::$cache->load($missingKey, 60, $missingKey));
        $this->assertEquals('fresh data', self::$cache->save($missingKey, 'fresh data', $missingKey));
        $this->assertEquals('fresh data', self::$cache->load($missingKey, 60, $missingKey));

        self::$cache->purge('fenced-key', 'fenced-key');

        $this->assertFalse(self::$cache->load('fenced-key', 60, 'fenced-key'));
        $this->assertFalse(self::$cache->save('fenced-key', 'fresh data', 'fenced-key'));
        $this->assertFalse(self::$cache->load('fenced-key', 60, 'fenced-key'));

        self::$cache->purge('fenced-key', 'fenced-key');
    }

    public function testExpiredCacheDoesNotBlockFencedSave(): void
    {
        self::$cache->purge('expired-fence-key', 'expired-fence-key');
        self::$cache->save('expired-fence-key', 'expired data', 'expired-fence-key');
        $this->assertFalse(self::$cache->load('expired-fence-key', 0, 'expired-fence-key'));

        $this->assertFalse(self::$cache->save('expired-fence-key', 'fresh data', 'expired-fence-key'));
        $this->assertEquals('expired data', self::$cache->load('expired-fence-key', 60, 'expired-fence-key'));

        self::$cache->purge('expired-fence-key', 'expired-fence-key');
    }
}
