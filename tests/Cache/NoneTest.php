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

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\None;

class NoneTest extends TestCase
{
    /**
     * @var Cache
     */
    protected $cache = null;

    /**
     * @var string
     */
    protected $key = 'test-key-for-cache';

    /**
     * @var string
     */
    protected $data = 'test data string';

    protected function setUp(): void
    {
        $this->cache = new Cache(new None());
    }

    protected function tearDown(): void
    {
        $this->cache = null;
    }

    public function testCacheLoad()
    {
        $data  = $this->cache->load($this->key, 60 * 60 * 24 * 30 * 3 /* 3 months */);

        $this->assertEquals(false, $data);
    }

    public function testCacheSave()
    {
        $result = $this->cache->save($this->key, $this->data);

        $this->assertEquals(false, $result);
    }

    public function testCachePurge()
    {
        $result = $this->cache->purge($this->key);

        $this->assertEquals(true, $result);
    }
}
