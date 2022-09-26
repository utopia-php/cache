<?php

namespace Utopia\Tests;

use Redis as Redis;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Redis as RedisAdapter;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Tests\Base;

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
            new RedisAdapter($shardC)
        ]));
    }

    public static function tearDownAfterClass(): void
    {
        self::$cache::setCaseSensitivity(false);
        self::$cache = null;
    }
}
