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
use Utopia\Cache\Adapter\Memory;
use Utopia\Tests\Base;

class MemoryTest extends Base
{
    protected function setUp(): void
    {
        $this->cache = new Cache(new Memory());
        $this->cache::setCaseSensitivity(true);
    }

    protected function tearDown(): void
    {
        $this->cache = null;
    }
}
