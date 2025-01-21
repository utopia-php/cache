<?php

namespace Utopia\Tests;

use Redis as Redis;
use Utopia\Cache\Adapter\Redis as RedisAdapter;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;

class RedisTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        $redis = new Redis();
        $redis->connect('redis', 6379);
        self::$cache = new Cache(new RedisAdapter($redis));
    }

    public static function tearDownAfterClass(): void
    {
        self::$cache::setCaseSensitivity(false);
        // @phpstan-ignore-next-line
        self::$cache = null;
    }

    public function testGetSize(): void
    {
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

        $stdout = '';
        $stderr = '';
        Console::execute('docker ps -a --filter "name=cache-redis" --format "{{.Names}}" | xargs -r docker stop', '', $stdout, $stderr);
        sleep(1);

        try {
            self::$cache->load('test:file33', 5);
            $this->fail('Redis connection should have failed');
        } catch (\RedisException $e) {
            Console::execute('docker ps -a --filter "name=cache-redis" --format "{{.Names}}" | xargs -r docker start', '', $stdout, $stderr);
            sleep(3);
        }

        $this->assertEquals('file33', self::$cache->save('test:file33', 'file33', 'test:file33'));
        $this->assertEquals('file33', self::$cache->load('test:file33', 5));
    }
}
