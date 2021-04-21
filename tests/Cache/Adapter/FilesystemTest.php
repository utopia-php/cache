<?php
/**
 * Utopia PHP Framework
 *
 * @package Framework
 * @subpackage Tests
 *
 * @link https://github.com/utopia-php/framework
 * @author Eldad Fux <eldad@appwrite.io>
 * @version 1.0 RC4
 * @license The MIT License (MIT) <http://www.opensource.org/licenses/mit-license.php>
 */

namespace Utopia;

use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Filesystem;
use Utopia\Tests\Base;

class FilesystemTest extends Base
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

        $cache = new Cache(new Filesystem('tests/data'));

        return self::$cache = $cache;
    }
}
