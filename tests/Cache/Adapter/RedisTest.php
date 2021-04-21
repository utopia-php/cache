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

namespace Utopia\Tests\Adapter;

use Redis as Redis;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Redis as RedisAdapter;
use Utopia\Tests\Base;

class RedisTest extends Base
{
    /**
     * @var Cache
     */
    protected $cache = null;

    /**
     * @return Cache
     */
    static function getCache(): Cache
    {
        if (!is_null(self::$cache)) {
            return self::$cache;
        }

        $redis = new Redis();
        $redis->connect('redis', 6379);
        $cache = new Cache(new RedisAdapter($redis));

        return self::$cache = $cache;
    }
}
