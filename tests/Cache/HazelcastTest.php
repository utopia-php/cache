<?php

namespace Utopia\Tests;

use Memcached;
use Utopia\Cache\Adapter\Hazelcast as HazelcastAdapter;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;

class HazelcastTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        $memcached = new Memcached;
        $memcached->addServer('hazelcast', 5701);
        self::$cache = new Cache(new HazelcastAdapter($memcached));
    }

    public function test_get_size(): void
    {
        $this->assertEquals(0, self::$cache->getSize());
    }

    public function test_cache_reconnect(): void
    {
        $memcached = new Memcached;
        $memcached->addServer('hazelcast', 5701);
        self::$cache = new Cache((new HazelcastAdapter($memcached))->setMaxRetries(3));
        self::$cache->save('test:reconnect', 'reconnect', 'test:reconnect');

        $stdout = '';
        $stderr = '';
        Console::execute('docker ps -a --filter "name=hazelcast" --format "{{.Names}}" | xargs -r docker stop', '', $stdout, $stderr);
        sleep(3);

        try {
            self::$cache->load('test:reconnect', 5);
            $this->fail('Hazelcast connection should have failed');
        } catch (\MemcachedException $e) {
        }

        Console::execute('docker ps -a --filter "name=hazelcast" --format "{{.Names}}" | xargs -r docker start', '', $stdout, $stderr);
        sleep(3);

        $this->assertEquals('reconnect', self::$cache->save('test:reconnect', 'reconnect', 'test:reconnect'));
        $this->assertEquals('reconnect', self::$cache->load('test:reconnect', 5));
    }

    public function test_flush(): void
    {
        // not implemented as Hazelcast doesn't support flush functionality
        $result = self::$cache->flush();

        $this->assertEquals(false, $result);
    }
}
