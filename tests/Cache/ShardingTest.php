<?php

namespace Utopia\Tests;

use Redis as Redis;
use Throwable;
use Utopia\Cache\Adapter\Redis as RedisAdapter;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;

class ShardingTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        $shardA = new Redis();
        $shardA->connect('shardA', 6379);

        $shardB = new Redis();
        $shardB->connect('shardB', 6379);

        $shardC = new Redis();
        $shardC->connect('shardC', 6379);

        self::$cache = new Cache(new Sharding([
            new RedisAdapter($shardA),
            new RedisAdapter($shardB),
            new RedisAdapter($shardC),
        ]));
    }

    public function testGetSize(): void
    {
        self::$cache->save('test:file33', 'file33', 'test:file33');
        self::$cache->save('test:file34', 'file34', 'test:file33');
        $this->assertEquals(2, self::$cache->getSize());
    }

    public static function tearDownAfterClass(): void
    {
        self::$cache::setCaseSensitivity(false);
        // @phpstan-ignore-next-line
        self::$cache = null;
    }

    public function testEmptyAdapters(): void
    {
        try {
            self::$cache = new Cache(new Sharding([]));
            $this->fail('Expected exception was not thrown');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\Throwable::class, $e);
        }
    }
}
