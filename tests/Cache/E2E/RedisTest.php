<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use PHPUnit\Framework\Attributes\Depends;
use Redis as Redis;
use RedisException;
use Utopia\Cache\Adapter\Redis as RedisAdapter;
use Utopia\Cache\Cache;
use Utopia\Tests\Base;
use Utopia\Tests\Scope\EmptyObjectFidelity;
use Utopia\Tests\Services;

final class RedisTest extends Base
{
    use EmptyObjectFidelity;

    public static function setUpBeforeClass(): void
    {
        $redis = new Redis();
        $redis->connect(Services::HOST, Services::REDIS_PORT);
        self::$cache = new Cache(new RedisAdapter($redis));
    }

    public function testGetSize(): void
    {
        self::$cache->flush();
        self::$cache->save('test:file33', 'file33', 'test:file33');
        self::$cache->save('test:file34', 'file34', 'test:file34');
        self::$cache->save('test:file35', 'file35', 'test:file35');
        $this->assertSame(3, self::$cache->getSize());
    }

    #[Depends('testGetSize')]
    public function testCacheReconnect(): void
    {
        $this->assertReconnects(persistent: false);
    }

    #[Depends('testCacheReconnect')]
    public function testCacheReconnectPersistent(): void
    {
        $this->assertReconnects(persistent: true);
    }

    /**
     * Restarts Redis underneath a live adapter and asserts it recovers.
     */
    private function assertReconnects(bool $persistent): void
    {
        $key = $persistent ? 'test:reconnect_persistent' : 'test:reconnect';

        $redis = new Redis();
        $persistent
            ? $redis->pconnect(Services::HOST, Services::REDIS_PORT)
            : $redis->connect(Services::HOST, Services::REDIS_PORT);
        self::$cache = new Cache(new RedisAdapter($redis)->setMaxRetries(3));

        self::$cache->save($key, 'reconnect', $key);

        Services::compose('stop', 'redis');
        sleep(1);

        try {
            self::$cache->load($key, 5);
            $this->fail('Redis connection should have failed');
        } catch (RedisException) {
        }

        Services::compose('up', '-d', '--wait', 'redis');

        $this->assertSame('reconnect', self::$cache->save($key, 'reconnect', $key));
        $this->assertEquals('reconnect', self::$cache->load($key, 5));
    }
}
