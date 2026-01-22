<?php

namespace Utopia\Tests;

use Memcached;
use Utopia\Cache\Adapter\Memcached as MemcachedAdapter;
use Utopia\Cache\Cache;

class MemcachedTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        $mc = new Memcached();
        $mc->addServer('memcached', 11211);

        self::$cache = new Cache(new MemcachedAdapter($mc));
    }

    public function testGetSize(): void
    {
        self::$cache->save('test:file33', 'file33');
        $this->assertEquals(1, self::$cache->getSize());
    }

    public function testCacheReconnect(): void
    {
        $mc = new Memcached();
        $mc->addServer('memcached', 11211);
        self::$cache = new Cache((new MemcachedAdapter($mc))->setMaxRetries(3));
        self::$cache->save('test:reconnect', 'reconnect');

        $stopCmd = 'docker ps -a --filter "name=memcached" --format "{{.Names}}" | xargs -r docker stop';
        exec($stopCmd.' 2>&1', $output, $exitCode);
        $this->assertEquals(0, $exitCode, "Docker stop failed: $stopCmd\nOutput: ".implode("\n", $output));
        sleep(3);

        try {
            self::$cache->load('test:reconnect', 5);
            $this->fail('Memcached connection should have failed');
        } catch (\MemcachedException $e) {
        }

        $output = [];
        $startCmd = 'docker ps -a --filter "name=memcached" --format "{{.Names}}" | xargs -r docker start';
        exec($startCmd.' 2>&1', $output, $exitCode);
        $this->assertEquals(0, $exitCode, "Docker start failed: $startCmd\nOutput: ".implode("\n", $output));
        sleep(3);

        $this->assertEquals('reconnect', self::$cache->save('test:reconnect', 'reconnect', 'test:reconnect'));
        $this->assertEquals('reconnect', self::$cache->load('test:reconnect', 5));
    }
}
