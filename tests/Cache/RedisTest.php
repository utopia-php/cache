<?php

namespace Utopia\Tests;

use Redis;
use Utopia\Cache\Adapter\Redis as RedisAdapter;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;

class RedisTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        $redis = new Redis;
        $redis->connect('redis', 6379);
        self::$cache = new Cache(new RedisAdapter($redis));
    }

    public function test_get_size(): void
    {
        self::$cache->save('test:file33', 'file33', 'test:file33');
        self::$cache->save('test:file34', 'file34', 'test:file34');
        self::$cache->save('test:file35', 'file35', 'test:file35');
        $this->assertEquals(3, self::$cache->getSize());
    }

    /**
     * @depends test_get_size
     */
    public function test_cache_reconnect(): void
    {
        $redis = new Redis;
        $redis->connect('redis', 6379);
        self::$cache = new Cache((new RedisAdapter($redis))->setMaxRetries(3));

        self::$cache->save('test:reconnect', 'reconnect', 'test:reconnect');

        $stdout = '';
        $stderr = '';
        Console::execute('docker ps -a --filter "name=cache-redis" --format "{{.Names}}" | xargs -r docker stop', '', $stdout, $stderr);
        sleep(1);

        try {
            self::$cache->load('test:reconnect', 5);
            $this->fail('Redis connection should have failed');
        } catch (\RedisException $e) {
        }

        Console::execute('docker ps -a --filter "name=cache-redis" --format "{{.Names}}" | xargs -r docker start', '', $stdout, $stderr);
        sleep(3);

        $this->assertEquals('reconnect', self::$cache->save('test:reconnect', 'reconnect', 'test:reconnect'));
        $this->assertEquals('reconnect', self::$cache->load('test:reconnect', 5));
    }
}
