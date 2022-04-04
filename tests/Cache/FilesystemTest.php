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

namespace Utopia\Tests;

use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Filesystem;
use Utopia\Tests\Base;

class FilesystemTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        self::$cache = new Cache(new Filesystem('tests/data'));
    }

    public static function tearDownAfterClass(): void
    {
        self::$cache::setCaseSensitivity(false);
        self::$cache = null;
    }
}
