<?php

namespace Utopia\Tests;

use Memcached;
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
}
