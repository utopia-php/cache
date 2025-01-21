<?php

namespace Utopia\Tests;

use Memcached as Memcached;
use Utopia\Cache\Adapter\Hazelcast as HazelcastAdapter;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;

class HazelcastTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        $memcached = new Memcached();
        $memcached->addServer('hazelcast', 5701);
        self::$cache = new Cache(new HazelcastAdapter($memcached));
    }

    public static function tearDownAfterClass(): void
    {
        self::$cache::setCaseSensitivity(false);
        // @phpstan-ignore-next-line
        self::$cache = null;
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

        $stdout = '';
        $stderr = '';
        Console::execute('docker ps -a --filter "name=hazelcast" --format "{{.Names}}" | xargs -r docker stop', '', $stdout, $stderr);
        sleep(1);

        try {
            self::$cache->load('test:file33', 5);
            Console::execute('docker ps -a --filter "name=hazelcast" --format "{{.Names}}" | xargs -r docker start', '', $stdout, $stderr);
            $this->fail('Hazelcast connection should have failed');
        } catch (\MemcachedException $e) {
            Console::execute('docker ps -a --filter "name=hazelcast" --format "{{.Names}}" | xargs -r docker start', '', $stdout, $stderr);
            sleep(3);
        }

        $this->assertEquals('file33', self::$cache->save('test:file33', 'file33', 'test:file33'));
        $this->assertEquals('file33', self::$cache->load('test:file33', 5));
    }

    public function testFlush(): void
    {
        //not implemented as Hazelcast doesn't support flush functionality
        $result = self::$cache->flush();

        $this->assertEquals(false, $result);
    }
}
