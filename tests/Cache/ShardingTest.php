<?php
/**
 * Utopia PHP Framework
 *
 * @package Framework
 * @subpackage Tests
 *
 * @link https://github.com/utopia-php/cache
 * @author Eldad Fux <eldad@appwrite.io>
 * @version 1.0 RC4
 * @license The MIT License (MIT) <http://www.opensource.org/licenses/mit-license.php>
 */

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
