<?php

namespace Utopia\Tests;

use Memcached as Memcached;
use Utopia\Cache\Adapter\Memcached as MemcachedAdapter;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;

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

        $stdout = '';
        $stderr = '';
        Console::execute('docker ps -a --filter "name=memcached" --format "{{.Names}}" | xargs -r docker stop', '', $stdout, $stderr);
        sleep(1);

        try {
            self::$cache->load('test:file33', 5);
            Console::execute('docker ps -a --filter "name=memcached" --format "{{.Names}}" | xargs -r docker start', '', $stdout, $stderr);
            $this->fail('Memcached connection should have failed');
        } catch (\MemcachedException $e) {
            Console::execute('docker ps -a --filter "name=memcached" --format "{{.Names}}" | xargs -r docker start', '', $stdout, $stderr);
            sleep(3);
        }

        $this->assertEquals('file33', self::$cache->save('test:file33', 'file33', 'test:file33'));
        $this->assertEquals('file33', self::$cache->load('test:file33', 5));
    }

    public static function tearDownAfterClass(): void
    {
        self::$cache::setCaseSensitivity(false);
        // @phpstan-ignore-next-line
        self::$cache = null;
    }
}
