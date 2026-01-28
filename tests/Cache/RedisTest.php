<?php

namespace Utopia\Tests;

use Redis as Redis;
use Utopia\Cache\Adapter\Redis as RedisAdapter;
use Utopia\Cache\Cache;

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
}
